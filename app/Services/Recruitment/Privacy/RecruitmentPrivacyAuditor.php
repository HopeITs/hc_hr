<?php

namespace App\Services\Recruitment\Privacy;

use App\Enums\RecruitmentScopeType;
use App\Models\RecruitmentPrivacyAudit;
use App\Models\User;
use App\Services\RecruitmentScopeResolver;
use Illuminate\Support\Str;

/**
 * Writes the append-only record of a sensitive act on applicant data.
 *
 * Deliberately not wrapped in a try/catch. An export whose audit row cannot be
 * written must not happen, so callers write the row before the data leaves and
 * let a failure stop them. (The monitoring log is the opposite: it never throws.)
 *
 * Every row carries the same five things — actor, date, scope, filter, count —
 * whatever the action, so "who exported how much, and how wide was their
 * reach" is one query.
 */
class RecruitmentPrivacyAuditor
{
    public const SUBJECT_APPLICANT = 'recruitment_applicant';

    public function __construct(private readonly RecruitmentScopeResolver $scopes) {}

    /**
     * @param  array<string, mixed>  $filters  the filters as they were applied
     * @param  array<string, mixed>  $details  action-specific context; never personal data
     */
    public function record(
        string $action,
        string $outcome,
        ?User $actor,
        array $filters = [],
        int $recordCount = 0,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?string $reason = null,
        array $details = [],
    ): RecruitmentPrivacyAudit {
        [$scopeType, $cityIds] = $this->scope($actor);
        // A system act (no actor) has no client, whatever request happens to be
        // bound; the console kernel binds a synthetic one (127.0.0.1) for jobs
        // and commands, and only a routed request came from a browser.
        $request = $actor !== null && app()->bound('request') && request()->route() !== null ? request() : null;

        return RecruitmentPrivacyAudit::query()->create([
            'action' => $action,
            'outcome' => $outcome,
            'actor_user_id' => $actor?->getKey(),
            'actor_role' => $actor?->myRole(),
            'scope_type' => $scopeType,
            'scope_city_ids' => $cityIds,
            'filters_json' => $filters === [] ? null : $filters,
            'record_count' => $recordCount,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'reason' => $reason,
            'details_json' => $details === [] ? null : $details,
            'ip_address' => $request?->ip(),
            'user_agent' => $request === null ? null : Str::limit((string) $request->userAgent(), 500, ''),
        ]);
    }

    /**
     * How far the actor reaches at this moment, as it was when they acted.
     *
     * @return array{0: string, 1: array<int, int>|null}
     */
    public function scope(?User $actor): array
    {
        if ($actor === null) {
            return [RecruitmentPrivacyAudit::SCOPE_SYSTEM, null];
        }

        return match ($this->scopes->scopeType($actor)) {
            RecruitmentScopeType::Organization => ['organization', null],
            RecruitmentScopeType::City => ['city', $this->scopes->governorateCityIds($actor)],
            RecruitmentScopeType::DirectReports => ['direct_reports', null],
            default => ['none', null],
        };
    }
}
