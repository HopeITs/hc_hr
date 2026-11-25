<?php

namespace App\Support\Search\Providers;

use App\Models\User;
use App\Services\Assistant\PolicyLibrary;
use App\Support\Search\SearchProvider;
use App\Support\Search\SearchResult;

/**
 * The organisation's policies, by what they say: the passage that answers the typed words, shown
 * as an excerpt under the policy's title. Every signed-in employee may read the policies (the
 * assistant answers from them for everyone), so there is no scope; a result opens the policy's
 * own page at that passage, marked.
 */
final class PoliciesProvider implements SearchProvider
{
    public function __construct(private readonly PolicyLibrary $library) {}

    public function allows(User $user): bool
    {
        return true;
    }

    public function search(User $user, string $term, int $limit): array
    {
        return array_map(
            fn (array $hit): array => SearchResult::make(
                'policies',
                $hit['id'].'-'.$hit['position'],
                $hit['title'],
                $hit['snippet'],
                route('policies.show', ['document' => $hit['id'], 'q' => $term, 'p' => $hit['position']]),
            ),
            $this->library->search($term, $limit),
        );
    }
}
