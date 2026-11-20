<?php

namespace App\Support\Search\Providers;

use App\Models\RecruitmentApplicant;
use App\Models\User;
use App\Support\Search\NameMatch;
use App\Support\Search\SearchProvider;
use App\Support\Search\SearchResult;
use App\Support\SearchText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Applicants by name or email, only for who may list applicants (the policy's viewAny) and only
 * those RecruitmentApplicant::visibleTo allows: candidates are personal data, so this is the
 * strictest provider. The line shows the name and email, which the applicants list shows too.
 */
final class ApplicantsProvider implements SearchProvider
{
    public function allows(User $user): bool
    {
        return Gate::forUser($user)->allows('viewAny', RecruitmentApplicant::class);
    }

    public function search(User $user, string $term, int $limit): array
    {
        $words = SearchText::words($term);

        if ($words === []) {
            return [];
        }

        return RecruitmentApplicant::query()
            ->visibleTo($user)
            ->where(function (Builder $match) use ($words, $term): void {
                $match->where('email_normalized', 'like', NameMatch::like($term));

                $match->orWhere(function (Builder $names) use ($words): void {
                    foreach ($words as $word) {
                        $names->whereRaw(SearchText::foldedColumn('full_name').' like ?', ["%{$word}%"]);
                    }
                });
            })
            ->orderBy('full_name')
            ->limit($limit)
            ->get()
            ->map(fn (RecruitmentApplicant $applicant): array => SearchResult::make(
                'applicants',
                $applicant->id,
                (string) $applicant->full_name,
                (string) $applicant->email_normalized,
                route('recruitment.applicants.show', $applicant->id),
            ))
            ->all();
    }
}
