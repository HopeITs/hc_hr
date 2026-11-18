<?php

namespace App\Support\Search\Providers;

use App\Models\RecruitmentRequest;
use App\Models\User;
use App\Support\Search\NameMatch;
use App\Support\Search\SearchProvider;
use App\Support\Search\SearchResult;

/**
 * Hiring requests by their number, through the scope the requests list uses
 * (RecruitmentRequest::visibleTo) for roles that may view requests.
 */
final class RecruitmentRequestsProvider implements SearchProvider
{
    public function allows(User $user): bool
    {
        return $user->hasActivePermission('recruitment-requests.view');
    }

    public function search(User $user, string $term, int $limit): array
    {
        return RecruitmentRequest::query()
            ->visibleTo($user)
            ->where('request_number', 'like', NameMatch::like($term))
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (RecruitmentRequest $request): array => SearchResult::make(
                'requests',
                $request->id,
                (string) $request->request_number,
                (string) $request->created_at?->format('d/m/Y'),
                route('recruitment.requests.show', $request->id),
            ))
            ->all();
    }
}
