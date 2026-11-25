<?php

namespace App\Services\Assistant;

use App\Models\PolicyDocument;
use App\Support\SearchText;
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

    /**
     * @param  bool  $withLinks  a "Link:" line with each policy (or each passage when only passages are
     *                           sent): the assistant's answer can then point at the policy's own page
     */
    public function contextFor(string $question, bool $withLinks = false): string
    {
        $index = $this->index();

        if ($index === null) {
            return '';
        }

        $budget = max(0, (int) config('assistant.policy_char_budget', 12000));

        if ($index['whole_length'] <= $budget) {
            if (! $withLinks) {
                return implode("\n\n", array_column($index['documents'], 'block'));
            }

            return implode("\n\n", array_map(
                fn (array $document): string => $document['heading']."\nLink: ".$this->link($document['id'], null, $question).substr($document['block'], strlen($document['heading'])),
                $index['documents'],
            ));
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
            ->map(function (Collection $passages) use ($index, $withLinks, $question) {
                $text = $index['documents'][$passages->first()['document']]['heading'];
                $previous = null;

                foreach ($passages as $passage) {
                    $text .= ($previous !== null && $passage['position'] === $previous + 1 ? "\n" : "\n…\n").$passage['text'];

                    if ($withLinks) {
                        $text .= "\nLink: ".$this->link($index['documents'][$passage['document']]['id'], $passage['position'], $question);
                    }

                    $previous = $passage['position'];
                }

                return $text;
            })
            ->implode("\n\n");

        return $titles."\n\n".$body;
    }

    /** The policy's page, at a passage when one is given, with the question's words to mark. */
    private function link(int $document, ?int $position, string $question): string
    {
        return route('policies.show', array_filter([
            'document' => $document,
            'q' => mb_substr(trim($question), 0, 80),
            'p' => $position,
        ], fn ($value) => $value !== null && $value !== ''));
    }

    /**
     * The passages of one policy's text, in reading order: the same split the index uses, so a
     * result's `position` is the passage to scroll to on the policy's page.
     *
     * @return list<string>
     */
    public function passagesOf(string $content): array
    {
        return $this->split($content);
    }

    /**
     * Where the header search finds a typed term in the policies: for each policy at most one
     * passage (its best), best first, with a short excerpt of the sentence that matches.
     *
     * A typed word counts when a passage holds it as a word (spelling variants meet, as for the
     * assistant) or anywhere inside its text (so «الكترونيات» finds «الإلكترونيات»). A word no
     * policy holds at all («بدي», «please») is left out instead of ruling everything out; of the
     * rest a passage must hold about 60% (two of two, two of three). Rarer words weigh more, and
     * words standing together as typed add a bonus.
     *
     * @return list<array{id: int, title: string, position: int, text: string, snippet: string}>
     */
    public function search(string $term, int $limit = 4): array
    {
        $index = $this->index();
        $words = $this->searchWords($term);

        if ($index === null || $words === [] || $limit < 1) {
            return [];
        }

        $passages = $index['passages'];
        $count = count($passages);
        $holds = [];
        $frequency = array_fill(0, count($words), 0);

        foreach ($passages as $p => $passage) {
            foreach ($words as $w => $word) {
                if (isset($passage['words'][$word['stem']]) || str_contains($passage['norm'], $word['core'])) {
                    $holds[$p][] = $w;
                    $frequency[$w]++;
                }
            }
        }

        $weights = [];

        foreach ($frequency as $w => $found) {
            if ($found > 0) {
                $weights[$w] = log(1 + $count / $found);
            }
        }

        if ($weights === []) {
            return [];
        }

        $needed = (int) ceil(count($weights) * 0.6);
        $ranked = [];

        foreach ($holds as $p => $held) {
            if (count($held) < $needed) {
                continue;
            }

            $score = array_sum(array_intersect_key($weights, array_flip($held)));

            foreach ($words as $w => $word) {
                $next = $words[$w + 1] ?? null;

                if ($next !== null && isset($weights[$w], $weights[$w + 1]) && str_contains($passages[$p]['norm'], $word['raw'].' '.$next['raw'])) {
                    $score += self::PAIR_BONUS * ($weights[$w] + $weights[$w + 1]);
                }
            }

            $ranked[$p] = $score;
        }

        arsort($ranked, SORT_NUMERIC);

        $results = [];
        $seen = [];

        foreach (array_keys($ranked) as $p) {
            $passage = $passages[$p];

            if (isset($seen[$passage['document']])) {
                continue;
            }

            $seen[$passage['document']] = true;
            $results[] = [
                'id' => (int) $index['documents'][$passage['document']]['id'],
                'title' => $index['documents'][$passage['document']]['title'],
                'position' => $passage['position'],
                'text' => $passage['text'],
                'snippet' => $this->snippet($passage['text'], $words),
            ];

            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    /**
     * The spellings of a typed term to mark in a policy's text: each word as typed (folded) and
     * its stem, so «employees» also marks «employee».
     *
     * @return list<string>
     */
    public function highlightTerms(string $term): array
    {
        $terms = [];

        foreach ($this->searchWords($term) as $word) {
            $terms[] = $word['raw'];
            $terms[] = $word['stem'];
            $terms[] = $word['core'];
        }

        return array_values(array_unique($terms));
    }

    /**
     * The typed words worth looking for, folded, each with its stem; a word too short or a
     * function word is left out.
     *
     * @return list<array{raw: string, stem: string, core: string}>
     */
    private function searchWords(string $term): array
    {
        $words = [];

        foreach (preg_split('/[^\p{L}\p{N}]+/u', SearchText::normalize($term), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $raw) {
            $stem = $this->tokens($raw)[0] ?? null;

            if ($stem !== null) {
                $words[$raw] = ['raw' => $raw, 'stem' => $stem, 'core' => $this->core($raw, $stem)];
            }
        }

        return array_slice(array_values($words), 0, 6);
    }

    /**
     * The part of a typed word that stays the same when it is inflected, to look for inside the
     * text: «الكترونيات» and «الإلكترونية» meet on «كترون», «leaves» and «leave» on «leave». The
     * article and a plural or feminine ending come off Arabic, never below three letters.
     */
    private function core(string $raw, string $stem): string
    {
        if (preg_match('/^[a-z0-9]+$/', $raw)) {
            return $stem;
        }

        if (str_starts_with($raw, 'ال') && mb_strlen($raw) - 2 >= 3) {
            $raw = mb_substr($raw, 2);
        }

        foreach (['يات', 'ات', 'ية', 'يه', 'ون', 'ين', 'ها', 'ه', 'ي'] as $suffix) {
            if (str_ends_with($raw, $suffix) && mb_strlen($raw) - mb_strlen($suffix) >= 3) {
                return mb_substr($raw, 0, -mb_strlen($suffix));
            }
        }

        return $raw;
    }

    /**
     * The sentence of a passage that holds the most of the typed words, on one line and cut at
     * about 200 characters.
     *
     * @param  list<array{raw: string, stem: string, core: string}>  $words
     */
    private function snippet(string $text, array $words): string
    {
        $best = '';
        $bestScore = -1;

        foreach (preg_split('/(?<=[.!?؟؛])\s+|\R+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $sentence) {
            $folded = SearchText::normalize($sentence);
            $stems = $this->words($sentence);
            $score = 0;

            foreach ($words as $word) {
                $score += (isset($stems[$word['stem']]) || str_contains($folded, $word['core'])) ? 1 : 0;
            }

            if ($score > $bestScore) {
                $best = trim($sentence);
                $bestScore = $score;
            }
        }

        $best = trim((string) preg_replace('/\s+/u', ' ', $best !== '' ? $best : $text));

        return mb_strlen($best) > 200 ? rtrim(mb_substr($best, 0, 200)).'…' : $best;
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

        // "v2": the index now also carries each policy's id and the folded text of each passage (search).
        return Cache::remember('assistant.policies.v2.'.$fingerprint, self::CACHE_SECONDS, function () {
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

            $index['documents'][$d] = ['id' => $document->id, 'title' => $title, 'heading' => $heading, 'block' => $block, 'words' => $titleWords];
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
                    'norm' => SearchText::normalize($title.' '.$text),
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
