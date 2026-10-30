<?php

namespace App\Services\Assistant;

use App\Models\PolicyDocument;
use Illuminate\Support\Collection;

/**
 * The policy text sent along with one question.
 *
 * Every readable policy goes in while they fit `assistant.policy_char_budget`.
 * When they do not, the documents sharing the most words with the question go
 * first and the last one that only partly fits is cut, so the model still sees
 * the policy most likely to hold the answer.
 */
class PolicyLibrary
{
    public function contextFor(string $question): string
    {
        $documents = PolicyDocument::query()
            ->readable()
            ->orderBy('title')
            ->get(['id', 'title', 'content']);

        if ($documents->isEmpty()) {
            return '';
        }

        $blocks = $documents->map(fn (PolicyDocument $document) => [
            'text' => $this->block($document),
            'document' => $document,
        ]);

        $budget = max(0, (int) config('assistant.policy_char_budget', 150000));
        $total = $blocks->sum(fn (array $block) => mb_strlen($block['text']) + 2);

        if ($total <= $budget) {
            return $blocks->pluck('text')->implode("\n\n");
        }

        return $this->fitRanked($this->rank($blocks, $question), $budget);
    }

    /** @param  Collection<int, array{text: string, document: PolicyDocument}>  $blocks */
    private function rank(Collection $blocks, string $question): Collection
    {
        $words = $this->words($question);

        return $blocks
            ->map(function (array $block) use ($words) {
                $haystack = $this->words($block['document']->title.' '.$block['document']->content);
                $title = $this->words($block['document']->title);

                // A word in the title says more about the document than one in its body.
                $block['score'] = count(array_intersect_key($words, $haystack))
                    + 2 * count(array_intersect_key($words, $title));

                return $block;
            })
            // Stable: equal scores keep the alphabetical order.
            ->sortBy([['score', 'desc']])
            ->values();
    }

    private function fitRanked(Collection $blocks, int $budget): string
    {
        $parts = [];
        $left = $budget;

        foreach ($blocks as $block) {
            $separator = $parts === [] ? 0 : 2;
            $length = mb_strlen($block['text']);

            if ($length + $separator <= $left) {
                $parts[] = $block['text'];
                $left -= $length + $separator;

                continue;
            }

            // Cut the first document that no longer fits and stop: a sliver of
            // every remaining one would help less than most of this one.
            $room = $left - $separator;

            if ($room > mb_strlen($block['document']->title) + 10) {
                $parts[] = mb_substr($block['text'], 0, $room - 1).'…';
            }

            break;
        }

        return implode("\n\n", $parts);
    }

    private function block(PolicyDocument $document): string
    {
        return '### '.trim((string) $document->title)."\n".trim((string) $document->content);
    }

    /**
     * The distinct words of a text, normalised so Arabic spelling variants
     * match: no diacritics or tatweel, one alef, ى→ي, ة→ه, lowercased.
     *
     * @return array<string, true>
     */
    private function words(string $text): array
    {
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}\x{0640}]/u', '', $text) ?? $text;
        $text = strtr($text, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه']);
        $text = mb_strtolower($text);

        $words = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_fill_keys(array_filter($words, fn (string $word) => mb_strlen($word) >= 3), true);
    }
}
