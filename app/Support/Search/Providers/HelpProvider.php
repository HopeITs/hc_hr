<?php

namespace App\Support\Search\Providers;

use App\Models\User;
use App\Support\HelpTopics;
use App\Support\Search\Highlight;
use App\Support\Search\SearchProvider;
use App\Support\Search\SearchResult;
use App\Support\SearchText;

/**
 * The help topics (the "?" panel's own copy), found by what they say. A result does not open a
 * page: it opens the help panel at that topic, wherever the person is (the panel is part of the
 * layout). Only the topics relevant to the active role are offered, as the panel itself does.
 * Help copy holds no figures and no personal data (HelpTopics).
 */
final class HelpProvider implements SearchProvider
{
    public function allows(User $user): bool
    {
        return true;
    }

    public function search(User $user, string $term, int $limit): array
    {
        $words = SearchText::words($term);

        if ($words === []) {
            return [];
        }

        $found = [];

        foreach (HelpTopics::forRequest(request(), $user)['topics'] as $topic) {
            if (! SearchText::matches($term, $topic['title'], $topic['summary'], $topic['keywords'], ...$topic['points'])) {
                continue;
            }

            // A topic whose title holds every word first.
            $found[] = [SearchText::matches($term, $topic['title']) ? 0 : 1, $topic];
        }

        usort($found, fn (array $a, array $b) => $a[0] <=> $b[0]);

        return array_map(
            fn (array $row): array => SearchResult::make(
                'help',
                $row[1]['key'],
                $row[1]['title'],
                $row[1]['summary'],
                '',
                [],
                ['action' => 'help', 'topic' => $row[1]['key'], 'marks' => array_values(array_filter($words, fn (string $word) => mb_strlen($word) >= 3))],
            ),
            array_slice($found, 0, $limit),
        );
    }
}
