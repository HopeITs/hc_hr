<?php

namespace App\Services;

use App\Models\RecruitmentApplication;
use App\Models\RecruitmentApplicationParticipant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RecruitmentApplicationParticipantService
{
    public const AUDIT_LOG = 'recruitment-application-participant';

    public function __construct(private readonly ActivityLogger $activity) {}

    public function assign(User $actor, RecruitmentApplication $application, int $userId, string $role): RecruitmentApplicationParticipant
    {
        Gate::forUser($actor)->authorize('manageReviewParticipants', $application);

        if (! in_array($role, RecruitmentApplicationParticipant::roles(), true)) {
            throw ValidationException::withMessages([
                'newParticipantRole' => __('recruitment-applicant.profile_reviews.errors.role_required'),
            ]);
        }

        $user = User::query()->with('profile')->find($userId);
        if (! RecruitmentInterviewSchedulingService::isActiveUser($user)) {
            throw ValidationException::withMessages([
                'newParticipantId' => __('recruitment-applicant.profile_reviews.errors.active_user_required'),
            ]);
        }

        $applicantEmployeeId = $application->applicant()->value('employee_id');
        if ($applicantEmployeeId !== null && (int) $user->employee_id === (int) $applicantEmployeeId) {
            throw ValidationException::withMessages([
                'newParticipantId' => __('recruitment-applicant.profile_reviews.errors.applicant_cannot_review'),
            ]);
        }

        $applicant = $application->applicant()->firstOrFail();

        $participant = DB::transaction(function () use ($actor, $application, $applicant, $user, $role): RecruitmentApplicationParticipant {
            $participant = RecruitmentApplicationParticipant::query()
                ->where('applicant_id', $applicant->getKey())
                ->where('user_id', $user->getKey())
                ->lockForUpdate()
                ->first();

            if ($participant === null) {
                return RecruitmentApplicationParticipant::query()->create([
                    'application_id' => $application->getKey(),
                    'applicant_id' => $applicant->getKey(),
                    'user_id' => $user->getKey(),
                    'role' => $role,
                    'added_by_user_id' => $actor->getKey(),
                ]);
            }

            $participant->forceFill([
                'role' => $role,
                'added_by_user_id' => $actor->getKey(),
                'removed_at' => null,
                'removed_by_user_id' => null,
            ])->save();

            return $participant;
        });

        $this->activity->log(
            'recruitment_application_participant.assigned',
            subject: $application,
            causer: $actor,
            properties: ['user_id' => $user->getKey(), 'role' => $role],
            logName: self::AUDIT_LOG,
        );

        return $participant;
    }

    public function remove(User $actor, RecruitmentApplication $application, int $participantId): void
    {
        Gate::forUser($actor)->authorize('manageReviewParticipants', $application);

        $applicant = $application->applicant()->firstOrFail();
        $participant = RecruitmentApplicationParticipant::query()
            ->current()
            ->where('applicant_id', $applicant->getKey())
            ->findOrFail($participantId);

        $participant->forceFill([
            'removed_at' => now(),
            'removed_by_user_id' => $actor->getKey(),
        ])->save();

        $this->activity->log(
            'recruitment_application_participant.removed',
            subject: $application,
            causer: $actor,
            properties: ['user_id' => (int) $participant->user_id, 'role' => $participant->role],
            logName: self::AUDIT_LOG,
        );
    }

    /** @param iterable<mixed> $applicationIds @return array{changed: int, skipped: int} */
    public function bulkAssign(User $actor, iterable $applicationIds, mixed $userId, mixed $role): array
    {
        Gate::forUser($actor)->authorize('bulkManageReviewParticipants', RecruitmentApplication::class);

        $ids = collect($applicationIds)
            ->filter(fn ($id): bool => is_scalar($id) && filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->take((int) config('recruitment.tags.bulk_limit', 500))
            ->values();

        if ($ids->isEmpty()) {
            throw ValidationException::withMessages(['applicationIds' => __('recruitment-applicant.profile_reviews.bulk_empty')]);
        }

        $userId = filter_var($userId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($userId === false) {
            throw ValidationException::withMessages(['bulkParticipantId' => __('recruitment-applicant.profile_reviews.errors.user_required')]);
        }

        if (! in_array($role, RecruitmentApplicationParticipant::roles(), true)) {
            throw ValidationException::withMessages(['bulkParticipantRole' => __('recruitment-applicant.profile_reviews.errors.role_required')]);
        }

        $visible = RecruitmentApplication::query()
            ->visibleTo($actor)
            ->whereKey($ids)
            ->get();

        foreach ($visible as $application) {
            $this->assign($actor, $application, (int) $userId, $role);
        }

        return ['changed' => $visible->count(), 'skipped' => $ids->count() - $visible->count()];
    }
}
