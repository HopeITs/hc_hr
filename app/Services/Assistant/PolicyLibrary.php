<?php

namespace App\Services\Assistant;

use App\Models\PolicyDocument;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The policy text sent along with one question.
 *
 * Every readable policy goes in while they all fit `assistant.policy_char_budget`.
 * When they do not (the real set is ~160k characters), only the passages that
 * share the most telling words with the question are sent, grouped under their
 * policy's title in reading order. Sending everything made each question slow
 * and spent the free tiers' tokens-per-minute in one or two questions.
 *
 * The passages and the word index are built once per set of policies and
 * kept in the cache under a fingerprint of the readable rows (id, updated_at
 * and content length), so a question costs one light query until a policy
 * changes.
 */
class PolicyLibrary
{
    /** Passages are built from whole lines up to about this length. */
    private const PASSAGE_CHARS = 900;

    /** How long a built index may sit in the cache; a changed policy replaces it at once anyway. */
    private const CACHE_SECONDS = 86400;

    /** Share of both words' weights a matching adjacent pair ("annual leave") adds on top of the words themselves. */
    private const PAIR_BONUS = 0.5;

    /** A passage scoring at least this share of the best one is "strong": its neighbours come before weaker matches. */
    private const STRONG_SHARE = 0.5;

    /**
     * Function words that say nothing about the subject. In a large set their
     * weight is already near zero; in a small one they would outrank a real
     * match ("the" found in one passage out of twenty looks telling).
     */
    private const STOP_WORDS = [
        'the', 'and', 'for', 'are', 'but', 'not', 'was', 'were', 'with', 'from', 'that', 'this', 'these', 'those',
        'what', 'which', 'when', 'where', 'who', 'whom', 'why', 'how', 'does', 'did', 'has', 'have', 'had', 'can',
        'could', 'should', 'would', 'will', 'may', 'might', 'must', 'get', 'got', 'you', 'your', 'our', 'its', 'their',
        'they', 'them', 'any', 'all', 'about', 'into', 'than', 'then', 'there', 'here', 'also', 'per', 'via', 'upon',
        'ما', 'ماذا', 'متي', 'كيف', 'اين', 'هذا', 'هذه', 'ذلك', 'تلك', 'علي', 'الي', 'عند', 'بعد', 'قبل', 'لكن',
        'ايضا', 'ضمن', 'حول', 'منذ', 'حتي', 'انا', 'انت', 'نحن', 'هنا', 'هناك', 'كان', 'يكون', 'ليس',
    ];

    public function contextFor(string $question): string
    {
        $index = $this->index();

        if ($index === null) {
            return '';
        }

        $budget = max(0, (int) config('assistant.policy_char_budget', 12000));

        if ($index['whole_length'] <= $budget) {
            return implode("\n\n", array_column($index['documents'], 'block'));
        }

        $chosen = $this->bestPassages($index, $question, $budget);
        $titles = 'Policies on file: '.implode('، ', array_column($index['documents'], 'title')).'.';

        if ($chosen->isEmpty()) {
            return $titles."\nNo passage matches this question.";
        }

        // Back in reading order, one heading per policy; adjacent passages
        // run on, a gap between them is marked.
        $body = $chosen
            ->sortBy([['document', 'asc'], ['position', 'asc']])
            ->groupBy('document')
            ->map(function (Collection $passages) use ($index) {
                $text = $index['documents'][$passages->first()['document']]['heading'];
                $previous = null;

                foreach ($passages as $passage) {
                    $text .= ($previous !== null && $passage['position'] === $previous + 1 ? "\n" : "\n…\n").$passage['text'];
                    $previous = $passage['position'];
                }

                return $text;
            })
            ->implode("\n\n");

        return $titles."\n\n".$body;
    }

    /**
     * The parsed policies, from the cache while none of them changed.
     *
     * @return array{documents: list<array{title: string, heading: string, block: string, words: array<string, true>}>, whole_length: int, passages: list<array{document: int, position: int, text: string, words: array<string, true>, pairs: array<string, true>}>, frequency: array<string, int>}|null
     */
    private function index(): ?array
    {
        $rows = PolicyDocument::query()
            ->readable()
            ->toBase()
            ->selectRaw('id, updated_at, length(content) as size')
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $fingerprint = md5($rows->map(fn (object $row) => $row->id.'|'.$row->updated_at.'|'.$row->size)->implode(';'));

        return Cache::remember('assistant.policies.'.$fingerprint, self::CACHE_SECONDS, function () {
            $documents = PolicyDocument::query()
                ->readable()
                ->orderBy('title')
                ->get(['id', 'title', 'content']);

            return $this->build($documents);
        });
    }

    /**
     * @param  Collection<int, PolicyDocument>  $documents
     * @return array{documents: list<array{title: string, heading: string, block: string, words: array<string, true>}>, whole_length: int, passages: list<array{document: int, position: int, text: string, words: array<string, true>, pairs: array<string, true>}>, frequency: array<string, int>}
     */
    private function build(Collection $documents): array
    {
        $index = ['documents' => [], 'whole_length' => 0, 'passages' => [], 'frequency' => []];

        foreach ($documents->values() as $d => $document) {
            $title = trim((string) $document->title);
            $heading = '### '.$title;
            $block = $heading."\n".trim((string) $document->content);
            $titleWords = $this->words($title);

            $index['documents'][$d] = ['title' => $title, 'heading' => $heading, 'block' => $block, 'words' => $titleWords];
            $index['whole_length'] += mb_strlen($block) + 2;

            foreach ($this->split((string) $document->content) as $position => $text) {
                $tokens = $this->tokens($text);
                // The title's words count for every passage of the policy.
                $words = array_fill_keys($tokens, true) + $titleWords;

                foreach (array_keys($words) as $word) {
                    $index['frequency'][$word] = ($index['frequency'][$word] ?? 0) + 1;
                }

                $index['passages'][] = [
                    'document' => $d,
                    'position' => $position,
                    'text' => $text,
                    'words' => $words,
                    'pairs' => $this->pairs($tokens),
                ];
            }
        }

        return $index;
    }

    /**
     * Whole lines gathered into passages of about PASSAGE_CHARS; an overlong line is cut at a space.
     *
     * @return list<string>
     */
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
     * first, while they fit; then the passage before and after each of them
     * while there is room, so a clause cut at a passage border arrives whole.
     *
     * A word found in few passages says more than one found everywhere
     * ("policy", "employee"), so each match is weighted by how rare the word
     * is (inverse document frequency). Two of the question's words standing
     * next to each other in the passage too ("annual leave") count extra, and
     * a policy whose title answers the question lifts all of its passages.
     *
     * @param  array{documents: list<array{title: string, heading: string, block: string, words: array<string, true>}>, whole_length: int, passages: list<array{document: int, position: int, text: string, words: array<string, true>, pairs: array<string, true>}>, frequency: array<string, int>}  $index
     * @return Collection<int, array{document: int, position: int, text: string}>
     */
    private function bestPassages(array $index, string $question, int $budget): Collection
    {
        $tokens = $this->tokens($question);
        $passages = $index['passages'];

        if ($tokens === [] || $passages === []) {
            return collect();
        }

        $count = count($passages);
        $idf = fn (string $word): float => isset($index['frequency'][$word]) ? log(1 + $count / $index['frequency'][$word]) : 0.0;

        $weights = [];

        foreach (array_unique($tokens) as $word) {
            if (($weight = $idf($word)) > 0) {
                $weights[$word] = $weight;
            }
        }

        if ($weights === []) {
            return collect();
        }

        $askedPairs = $this->pairs($tokens);

        // How much of each title (by weight) the question covers: 0..1.
        $titleMatch = [];

        foreach ($index['documents'] as $d => $document) {
            $total = array_sum(array_map($idf, array_keys($document['words'])));
            $titleMatch[$d] = $total > 0 ? array_sum(array_intersect_key($weights, $document['words'])) / $total : 0.0;
        }

        $ranked = [];

        foreach ($passages as $p => $passage) {
            $score = array_sum(array_intersect_key($weights, $passage['words']));

            if ($score <= 0) {
                continue;
            }

            foreach (array_keys(array_intersect_key($askedPairs, $passage['pairs'])) as $pair) {
                [$first, $second] = explode(' ', $pair, 2);
                $score += self::PAIR_BONUS * ($weights[$first] + $weights[$second]);
            }

            $ranked[$p] = $score * (1 + $titleMatch[$passage['document']]);
        }

        arsort($ranked, SORT_NUMERIC);

        $chosen = [];
        $left = $budget;
        $take = function (int $p) use (&$chosen, &$left, $passages): void {
            if (isset($chosen[$p])) {
                return;
            }

            $length = mb_strlen($passages[$p]['text']) + 4;

            if ($length <= $left) {
                $chosen[$p] = true;
                $left -= $length;
            }
        };

        $neighbours = function (int $p) use ($take, $passages): void {
            foreach ([$p - 1, $p + 1] as $neighbour) {
                if (isset($passages[$neighbour]) && $passages[$neighbour]['document'] === $passages[$p]['document']) {
                    $take($neighbour);
                }
            }
        };

        // The strongest matches bring their context neighbours along at once:
        // the rest of the clause is worth more than a weak match elsewhere.
        $strong = max($ranked) * self::STRONG_SHARE;

        foreach ($ranked as $p => $score) {
            $take($p);

            if ($score >= $strong && isset($chosen[$p])) {
                $neighbours($p);
            }
        }

        // Then every other chosen passage's neighbours, with what is left.
        foreach (array_keys($ranked) as $p) {
            if (isset($chosen[$p])) {
                $neighbours($p);
            }
        }

        return collect(array_keys($chosen))
            ->map(fn (int $p) => [
                'document' => $passages[$p]['document'],
                'position' => $passages[$p]['position'],
                'text' => $passages[$p]['text'],
            ]);
    }

    /**
     * The distinct words of a text as a set.
     *
     * @return array<string, true>
     */
    private function words(string $text): array
    {
        return array_fill_keys($this->tokens($text), true);
    }

    /**
     * Adjacent word pairs, as "first second" keys.
     *
     * @param  list<string>  $tokens
     * @return array<string, true>
     */
    private function pairs(array $tokens): array
    {
        $pairs = [];

        for ($i = 1, $n = count($tokens); $i < $n; $i++) {
            $pairs[$tokens[$i - 1].' '.$tokens[$i]] = true;
        }

        return $pairs;
    }

    /**
     * The words of a text in order, normalised so spelling variants match.
     * Arabic: no diacritics or tatweel, one alef, ى→ي, ة→ه, and the article
     * (with a joined و/ب/ك/ف) and common endings removed, so "بالإجازات" and
     * "إجازة" meet on "اجاز". English: lowercased, without a possessive 's or
     * a plural ending, so "employee's leaves" meets "employee leave".
     *
     * @return list<string>
     */
    private function tokens(string $text): array
    {
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}\x{0640}]/u', '', $text) ?? $text;
        $text = strtr($text, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', '’' => "'"]);
        $text = mb_strtolower($text);

        $tokens = [];

        foreach (preg_split("/[^\p{L}\p{N}']+/u", $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $word = $this->stem(trim($word, "'"));

            if (mb_strlen($word) >= 3 && ! in_array($word, self::STOP_WORDS, true)) {
                $tokens[] = $word;
            }
        }

        return $tokens;
    }

    /** A light stem: enough for matching, never shorter than three letters. */
    private function stem(string $word): string
    {
        if (preg_match('/^[a-z0-9\']+$/', $word)) {
            return $this->stemEnglish($word);
        }

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

    /** Possessive and plural endings off an English word, while three letters stay. */
    private function stemEnglish(string $word): string
    {
        if (str_ends_with($word, "'s")) {
            $word = substr($word, 0, -2);
        }

        $word = str_replace("'", '', $word);

        if (str_ends_with($word, 'ies') && strlen($word) - 2 >= 3) {
            return substr($word, 0, -3).'y';
        }

        if (preg_match('/(ss|x|z|ch|sh)es$/', $word) && strlen($word) - 2 >= 3) {
            return substr($word, 0, -2);
        }

        if (str_ends_with($word, 's') && ! str_ends_with($word, 'ss') && strlen($word) - 1 >= 3) {
            return substr($word, 0, -1);
        }

        return $word;
    }
}
