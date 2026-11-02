<?php

namespace App\Services\Assistant;

use App\Models\PolicyDocument;
use Illuminate\Support\Collection;

/**
 * The policy text sent along with one question.
 *
 * Every readable policy goes in while they all fit `assistant.policy_char_budget`.
 * When they do not (the real set is ~160k characters), only the passages that
 * share the most telling words with the question are sent, grouped under their
 * policy's title in reading order. Sending everything made each question slow
 * and spent the free tiers' tokens-per-minute in one or two questions.
 */
class PolicyLibrary
{
    /** Passages are built from whole lines up to about this length. */
    private const PASSAGE_CHARS = 900;

    public function contextFor(string $question): string
    {
        $documents = PolicyDocument::query()
            ->readable()
            ->orderBy('title')
            ->get(['id', 'title', 'content']);

        if ($documents->isEmpty()) {
            return '';
        }

        $budget = max(0, (int) config('assistant.policy_char_budget', 12000));
        $whole = $documents->map(fn (PolicyDocument $document) => $this->heading($document)."\n".trim((string) $document->content));

        if ($whole->sum(fn (string $block) => mb_strlen($block) + 2) <= $budget) {
            return $whole->implode("\n\n");
        }

        $chosen = $this->bestPassages($this->passages($documents), $question, $budget);
        $titles = 'Policies on file: '.$documents->map(fn (PolicyDocument $document) => trim((string) $document->title))->implode('، ').'.';

        if ($chosen->isEmpty()) {
            return $titles."\nNo passage matches this question.";
        }

        // Back in reading order, one heading per policy.
        $body = $chosen
            ->sortBy([['document', 'asc'], ['position', 'asc']])
            ->groupBy('document')
            ->map(fn (Collection $passages) => $passages->first()['heading']."\n".$passages->pluck('text')->implode("\n…\n"))
            ->implode("\n\n");

        return $titles."\n\n".$body;
    }

    /**
     * @param  Collection<int, PolicyDocument>  $documents
     * @return Collection<int, array{document: int, position: int, heading: string, text: string, words: array<string, true>}>
     */
    private function passages(Collection $documents): Collection
    {
        $passages = collect();

        foreach ($documents->values() as $d => $document) {
            $heading = $this->heading($document);
            $titleWords = $this->words((string) $document->title);
            $position = 0;

            foreach ($this->split((string) $document->content) as $text) {
                $passages->push([
                    'document' => $d,
                    'position' => $position++,
                    'heading' => $heading,
                    'text' => $text,
                    // The title's words count for every passage of the policy.
                    'words' => $this->words($text) + $titleWords,
                ]);
            }
        }

        return $passages;
    }

    /** Whole lines gathered into passages of about PASSAGE_CHARS; an overlong line is cut at a space. */
    private function split(string $content): array
    {
        $passages = [];
        $current = '';

        foreach (preg_split('/\R+/u', trim($content)) ?: [] as $line) {
            $line = trim($line);

            while (mb_strlen($line) > self::PASSAGE_CHARS) {
                $cut = mb_strrpos(mb_substr($line, 0, self::PASSAGE_CHARS), ' ') ?: self::PASSAGE_CHARS;
                $passages[] = trim(($current !== '' ? $current."\n" : '').mb_substr($line, 0, $cut));
                $current = '';
                $line = trim(mb_substr($line, $cut));
            }

            if ($line === '') {
                continue;
            }

            if ($current !== '' && mb_strlen($current) + mb_strlen($line) + 1 > self::PASSAGE_CHARS) {
                $passages[] = $current;
                $current = '';
            }

            $current = $current === '' ? $line : $current."\n".$line;
        }

        if ($current !== '') {
            $passages[] = $current;
        }

        return $passages;
    }

    /**
     * The passages sharing the most telling words with the question, best
     * first, while they fit. A word found in few passages says more than one
     * found everywhere ("policy", "employee"), so each match is weighted by
     * how rare the word is (inverse document frequency).
     */
    private function bestPassages(Collection $passages, string $question, int $budget): Collection
    {
        $asked = $this->words($question);

        if ($asked === [] || $passages->isEmpty()) {
            return collect();
        }

        $count = $passages->count();
        $weights = [];

        foreach (array_keys($asked) as $word) {
            $found = $passages->filter(fn (array $passage) => isset($passage['words'][$word]))->count();

            if ($found > 0) {
                $weights[$word] = log(1 + $count / $found);
            }
        }

        $ranked = $passages
            ->map(function (array $passage) use ($weights) {
                $passage['score'] = array_sum(array_intersect_key($weights, $passage['words']));

                return $passage;
            })
            ->filter(fn (array $passage) => $passage['score'] > 0)
            ->sortByDesc('score');

        $chosen = collect();
        $left = $budget;

        foreach ($ranked as $passage) {
            $length = mb_strlen($passage['text']) + 4;

            if ($length > $left) {
                continue;
            }

            $chosen->push($passage);
            $left -= $length;
        }

        return $chosen;
    }

    private function heading(PolicyDocument $document): string
    {
        return '### '.trim((string) $document->title);
    }

    /**
     * The distinct words of a text, normalised so Arabic spelling variants
     * match: no diacritics or tatweel, one alef, ى→ي, ة→ه, lowercased, and
     * the article (with a joined و/ب/ك/ف) and common endings removed, so
     * "بالإجازات" and "إجازة" meet on "اجاز".
     *
     * @return array<string, true>
     */
    private function words(string $text): array
    {
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}\x{0640}]/u', '', $text) ?? $text;
        $text = strtr($text, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه']);
        $text = mb_strtolower($text);

        $words = [];

        foreach (preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $word = $this->stem($word);

            if (mb_strlen($word) >= 3) {
                $words[$word] = true;
            }
        }

        return $words;
    }

    /** A light Arabic stem: enough for matching, never shorter than three letters. */
    private function stem(string $word): string
    {
        foreach (['وال', 'بال', 'كال', 'فال', 'لل', 'ال'] as $prefix) {
            if (str_starts_with($word, $prefix) && mb_strlen($word) - mb_strlen($prefix) >= 3) {
                $word = mb_substr($word, mb_strlen($prefix));

                break;
            }
        }

        foreach (['ات', 'ون', 'ين', 'ها', 'ه'] as $suffix) {
            if (str_ends_with($word, $suffix) && mb_strlen($word) - mb_strlen($suffix) >= 3) {
                return mb_substr($word, 0, -mb_strlen($suffix));
            }
        }

        return $word;
    }
}
