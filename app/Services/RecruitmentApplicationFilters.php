<?php

namespace App\Services;

use App\Enums\RecruitmentApplicationStatus;
use App\Enums\RecruitmentEvaluationRecommendation;
use App\Enums\RecruitmentFileScanStatus;
use App\Models\City;
use App\Models\HiringCampaign;
use App\Models\RecruitmentApplicant;
use App\Models\RecruitmentApplicantFile;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentApplicationFlag;
use App\Models\RecruitmentRequest;
use App\Models\RecruitmentTag;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The one place applications are filtered.
 *
 * Filters only ever narrow. query() starts from RecruitmentApplication::
 * visibleTo(), and every narrowing method here assumes a query that already
 * did — so a campaign, city or tag id typed into the browser can shrink a
 * result, never widen it past the user's scope. Values that do not parse
 * are dropped, not rejected: a stale bookmark shows an unfiltered list
 * rather than an error.
 *
 * Each filter is a single indexed sub-select on the application row, never
 * a join that multiplies rows, so counts and pagination stay correct and a
 * large result set costs one query per page.
 */
class RecruitmentApplicationFilters
{
    public const MAX_SEARCH = 100;

    public const MAX_IDS = 50;

    public const CV_AVAILABLE = 'available';

    public const CV_NONE = 'none';

    /**
     * @param  array<string, mixed>  $filters  campaign, status, city, tag, source, cv,
     *                                         previous, endorsement_group, evaluation,
     *                                         submitted_from, submitted_to, search
     */
    public function query(User $user, array $filters = []): Builder
    {
        $query = RecruitmentApplication::query()->visibleTo($user);

        $this->campaign($query, $filters['campaign'] ?? null);
        $this->status($query, $filters['status'] ?? null);
        $this->city($query, $filters['city'] ?? null);
        $this->tag($query, $filters['tag'] ?? null);
        $this->flagType($query, $filters['flag_type'] ?? null);
        $this->flagCategory($query, $filters['flag_category'] ?? null);
        $this->source($query, $filters['source'] ?? null);
        $this->cv($query, $filters['cv'] ?? null);
        $this->previousApplicant($query, $filters['previous'] ?? null);
        $this->endorsementGroup($query, $filters['endorsement_group'] ?? null);
        $this->evaluationRecommendation($query, $filters['evaluation'] ?? null);
        $this->submittedBetween($query, $filters['submitted_from'] ?? null, $filters['submitted_to'] ?? null);
        $this->search($query, $filters['search'] ?? null);

        return $query;
    }

    // --------------------------------------------
    // Narrowing — each assumes an already scoped query
    // --------------------------------------------

    public function campaign(Builder $query, mixed $value): Builder
    {
        $ids = $this->ids($value);

        return $ids === [] ? $query : $query->whereIn($query->qualifyColumn('campaign_id'), $ids);
    }

    public function status(Builder $query, mixed $value): Builder
    {
        $statuses = array_values(array_intersect(
            array_map('strval', array_filter((array) $value, 'is_scalar')),
            RecruitmentApplicationStatus::values(),
        ));

        return $statuses === [] ? $query : $query->whereIn($query->qualifyColumn('status'), $statuses);
    }

    /** The vacancy's governorate: the city on the request the campaign came from. */
    public function city(Builder $query, mixed $value): Builder
    {
        $ids = $this->ids($value);

        if ($ids === []) {
            return $query;
        }

        return $query->whereIn($query->qualifyColumn('campaign_id'), HiringCampaign::query()
            ->select('hiring_campaigns.id')
            ->join('vacancies', 'vacancies.id', '=', 'hiring_campaigns.vacancy_id')
            ->join('recruitment_requests', 'recruitment_requests.id', '=', 'vacancies.created_from_request_id')
            ->whereIn('recruitment_requests.scope_city_id', $ids));
    }

    /** Any of the given tags. A deleted tag matches nothing. */
    public function tag(Builder $query, mixed $value): Builder
    {
        $ids = $this->ids($value);

        if ($ids === []) {
            return $query;
        }

        return $query->whereIn($query->qualifyColumn('id'), DB::table('recruitment_application_tags')
            ->join('recruitment_tags', 'recruitment_tags.id', '=', 'recruitment_application_tags.tag_id')
            ->whereNull('recruitment_tags.deleted_at')
            ->whereIn('recruitment_application_tags.tag_id', $ids)
            ->select('recruitment_application_tags.application_id'));
    }

    public function flagType(Builder $query, mixed $value): Builder
    {
        if (! is_string($value) || ! in_array($value, RecruitmentApplicationFlag::types(), true)) {
            return $query;
        }

        return $query->whereExists(RecruitmentApplicationFlag::query()
            ->select('id')
            ->whereColumn('application_id', $query->qualifyColumn('id'))
            ->where('type', $value));
    }

    public function flagCategory(Builder $query, mixed $value): Builder
    {
        if (! is_string($value) || ! array_key_exists($value, RecruitmentApplicationFlag::reasons())) {
            return $query;
        }

        return $query->whereExists(RecruitmentApplicationFlag::query()
            ->select('id')
            ->whereColumn('application_id', $query->qualifyColumn('id'))
            ->where('category', $value));
    }

    public function source(Builder $query, mixed $value): Builder
    {
        $sources = array_values(array_filter(
            array_map(fn ($source): string => mb_substr(trim((string) $source), 0, 60), array_filter((array) $value, 'is_scalar')),
            fn (string $source): bool => $source !== '',
        ));

        if ($sources === []) {
            return $query;
        }

        return $query->whereIn($query->qualifyColumn('applicant_id'), RecruitmentApplicant::query()
            ->select('id')
            ->whereIn('source', $sources));
    }

    /**
     * 'available' — a clean CV that is not archived; 'none' — no CV at all.
     * Pending and blocked files are neither, and are left to the CV column.
     */
    public function cv(Builder $query, mixed $value): Builder
    {
        return match ($value) {
            self::CV_AVAILABLE => $query->whereExists($this->cvFiles($query)
                ->where('recruitment_applicant_files.scan_status', RecruitmentFileScanStatus::Clean)
                ->whereNull('recruitment_applicant_files.archived_at')),
            self::CV_NONE => $query->whereNotExists($this->cvFiles($query)),
            default => $query,
        };
    }

    /** A flag, never a decision: this narrows a list, it rejects nobody. */
    public function previousApplicant(Builder $query, mixed $value): Builder
    {
        // filter_var() reads null and '' as false, which would silently turn
        // "no filter" into "first applications only".
        $flag = is_bool($value) || (is_scalar($value) && $value !== '')
            ? filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)
            : null;

        return match ($flag) {
            true => $query->whereExists($this->earlierApplication($query)),
            false => $query->whereNotExists($this->earlierApplication($query)),
            default => $query,
        };
    }

    /**
     * Applications with at least one active reviewer recommendation of the
     * selected value, or with no active evaluation at all. Conflicting
     * reviewers remain visible under each recommendation they submitted.
     */
    public function evaluationRecommendation(Builder $query, mixed $value): Builder
    {
        $allowed = [...RecruitmentEvaluationRecommendation::values(), 'none'];

        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            return $query;
        }

        $evaluations = $this->activeEvaluations($query);

        if ($value === 'none') {
            return $query->whereNotExists($evaluations);
        }

        return $query->whereExists($evaluations->where('evaluations.recommendation', $value));
    }

    public function submittedBetween(Builder $query, mixed $from, mixed $to): Builder
    {
        if (($start = $this->date($from)) !== null) {
            $query->where($query->qualifyColumn('submitted_at'), '>=', $start->startOfDay());
        }

        if (($end = $this->date($to)) !== null) {
            $query->where($query->qualifyColumn('submitted_at'), '<=', $end->endOfDay());
        }

        return $query;
    }

    /** Name or e-mail, as a literal substring: % and _ typed in are not wildcards. */
    public function search(Builder $query, mixed $value): Builder
    {
        $term = is_scalar($value) ? mb_substr(trim((string) $value), 0, self::MAX_SEARCH) : '';

        if ($term === '') {
            return $query;
        }

        // An explicit ESCAPE character: MySQL defaults to backslash, SQLite
        // has no default, and '!' needs no quoting in either.
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term)).'%';

        return $query->whereIn($query->qualifyColumn('applicant_id'), RecruitmentApplicant::query()
            ->select('id')
            ->where(fn (Builder $applicants) => $applicants
                ->whereRaw("LOWER(full_name) LIKE ? ESCAPE '!'", [$like])
                ->orWhereRaw("LOWER(email_normalized) LIKE ? ESCAPE '!'", [$like])));
    }

    // --------------------------------------------
    // Options — every list is drawn from inside the user's scope
    // --------------------------------------------

    /** @return array<int, string> */
    public function campaignOptions(User $user): array
    {
        return HiringCampaign::query()
            ->whereIn('id', RecruitmentApplication::query()
                ->profileVisibleTo($user)
                ->select('campaign_id'))
            ->with('vacancy.position')
            ->latest('id')
            ->get()
            ->mapWithKeys(fn (HiringCampaign $campaign): array => [
                $campaign->getKey() => __('recruitment-applicant.campaign_number', ['number' => $campaign->getKey()])
                    .' · '.($campaign->vacancy?->position?->title ?? '—')
                    .($campaign->starts_at ? ' · '.$campaign->starts_at->format('Y-m-d') : ''),
            ])
            ->all();
    }

    /** Governorates the user has at least one visible request in. */
    public function cityOptions(User $user): array
    {
        return City::query()
            ->whereIn('id', RecruitmentRequest::query()
                ->visibleTo($user)
                ->whereNotNull('scope_city_id')
                ->select('scope_city_id'))
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (City $city): array => [$city->getKey() => $city->name ?? $city->slug])
            ->all();
    }

    /** @return array<string, string> */
    public function statusOptions(): array
    {
        return collect(RecruitmentApplicationStatus::values())
            ->mapWithKeys(fn (string $status): array => [$status => __('recruitment-applicant.statuses.'.$status)])
            ->all();
    }

    /** @return array<string, string> */
    public function sourceOptions(User $user): array
    {
        return RecruitmentApplicant::query()
            ->visibleTo($user)
            ->distinct()
            ->orderBy('source')
            ->pluck('source')
            ->mapWithKeys(fn (string $source): array => [$source => RecruitmentApplicant::sourceLabel($source)])
            ->all();
    }

    /** Tag names are an HR-wide vocabulary; which applications carry them is scoped. */
    public function tagOptions(): array
    {
        return RecruitmentTag::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @return array<string, string> */
    public function flagTypeOptions(): array
    {
        return collect(RecruitmentApplicationFlag::types())
            ->mapWithKeys(fn (string $type): array => [$type => __('recruitment-applicant.flags.types.'.$type)])
            ->all();
    }

    /** @return array<string, string> */
    public function flagCategoryOptions(): array
    {
        return collect(array_keys(RecruitmentApplicationFlag::reasons()))
            ->mapWithKeys(fn (string $category): array => [$category => __('recruitment-applicant.flags.categories.'.$category)])
            ->all();
    }

    /** @return array<string, string> */
    public function cvOptions(): array
    {
        return [
            self::CV_AVAILABLE => __('recruitment-applicant.filters.cv_available'),
            self::CV_NONE => __('recruitment-applicant.filters.cv_none'),
        ];
    }

    /** @return array<string, string> */
    public function previousOptions(): array
    {
        return [
            '1' => __('recruitment-applicant.filters.previous_yes'),
            '0' => __('recruitment-applicant.filters.previous_no'),
        ];
    }

    /** @return array<string, string> */
    public function endorsementGroupOptions(): array
    {
        return [
            'preferred' => __('recruitment-applicant.endorsement.group_preferred'),
            'alternative' => __('recruitment-applicant.endorsement.group_alternative'),
            'unvoted' => __('recruitment-applicant.endorsement.group_unvoted'),
        ];
    }

    /** @return array<string, string> */
    public function evaluationRecommendationOptions(): array
    {
        return collect(RecruitmentEvaluationRecommendation::values())
            ->mapWithKeys(fn (string $recommendation): array => [
                $recommendation => __('recruitment-applicant.evaluations.recommendations.'.$recommendation),
            ])
            ->put('none', __('recruitment-applicant.dashboard.not_evaluated'))
            ->all();
    }

    // --------------------------------------------
    // Internals
    // --------------------------------------------

    /**
     * Did this applicant apply to anything before this application — in any
     * campaign, in scope or not? Only the yes/no leaves the query; which
     * campaign it was stays behind the scope.
     */
    public function earlierApplication(Builder $query): QueryBuilder
    {
        $table = $query->getModel()->getTable();

        return DB::table('recruitment_applications as prior')
            ->selectRaw('1')
            ->whereColumn('prior.applicant_id', "{$table}.applicant_id")
            ->where(fn ($earlier) => $earlier
                ->whereColumn('prior.submitted_at', '<', "{$table}.submitted_at")
                ->orWhere(fn ($tie) => $tie
                    ->whereColumn('prior.submitted_at', '=', "{$table}.submitted_at")
                    ->whereColumn('prior.id', '<', "{$table}.id")))
            ->limit(1);
    }

    /**
     * Filter by endorsement group: 'preferred' (HR + manager), 'alternative'
     * (one side only), 'unvoted' (neither side).
     *
     * Uses a distinct-role subquery so two HR votes never count as preferred.
     */
    public function endorsementGroup(Builder $query, mixed $group): Builder
    {
        if (! in_array($group, ['preferred', 'alternative', 'unvoted'], true)) {
            return $query;
        }

        $endorsementCount = \App\Models\RecruitmentApplicationEndorsement::query()
            ->selectRaw('count(distinct voter_role)')
            ->whereColumn('application_id', $query->qualifyColumn('id'));

        return match ($group) {
            'preferred' => $query->whereRaw('('.$endorsementCount->toSql().') >= 2', $endorsementCount->getBindings()),
            'alternative' => $query->whereRaw('('.$endorsementCount->toSql().') = 1', $endorsementCount->getBindings()),
            'unvoted' => $query->whereRaw('('.$endorsementCount->toSql().') = 0', $endorsementCount->getBindings()),
        };
    }

    private function cvFiles(Builder $query): QueryBuilder
    {
        return DB::table('recruitment_applicant_files')
            ->selectRaw('1')
            ->whereColumn('recruitment_applicant_files.application_id', $query->qualifyColumn('id'))
            ->where('recruitment_applicant_files.file_type', RecruitmentApplicantFile::TYPE_CV);
    }

    private function activeEvaluations(Builder $query): QueryBuilder
    {
        return DB::table('recruitment_interview_evaluations as evaluations')
            ->join('recruitment_interviews as evaluation_interviews', 'evaluation_interviews.id', '=', 'evaluations.interview_id')
            ->selectRaw('1')
            ->whereColumn('evaluation_interviews.application_id', $query->qualifyColumn('id'))
            ->whereNull('evaluations.withdrawn_at');
    }

    /** @return array<int, int> positive integer ids, de-duplicated and capped */
    private function ids(mixed $value): array
    {
        $ids = [];

        foreach ((array) $value as $id) {
            if (is_scalar($id) && ($int = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) !== false) {
                $ids[$int] = $int;
            }
        }

        return array_slice(array_values($ids), 0, self::MAX_IDS);
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            return null;
        }

        // createFromFormat() rolls 2026-13-45 over into 2027; refuse it instead.
        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }
}
