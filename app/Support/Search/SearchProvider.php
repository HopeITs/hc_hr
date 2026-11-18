<?php

namespace App\Support\Search;

use App\Models\User;

/**
 * One kind of thing the header search can find (contracts, positions, folders…).
 *
 * A provider never invents its own visibility: it asks for the rows through the scope the
 * page that lists them already uses (Contract::visibleTo, LeaveRequest::manageableBy, …) and
 * only when the active role may open that page at all (allows()). So the search can show a
 * person nothing the list would not, and a result opens a page that checks the viewer again.
 *
 * A result is the array SearchResult::make() builds.
 */
interface SearchProvider
{
    /** May the user's active role search this kind at all? */
    public function allows(User $user): bool;

    /**
     * @return list<array<string, mixed>>
     */
    public function search(User $user, string $term, int $limit): array;
}
