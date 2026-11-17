<?php

namespace App\Services;

use App\Enums\RecruitmentEventType;
use App\Enums\RecruitmentRequestStatus as Status;
use App\Enums\RecruitmentRequestType;
use App\Models\RecruitmentRequest;
use App\Models\User;
use App\Services\Recruitment\Handlers\AppliesRecruitmentRequest;
use App\Services\Recruitment\Handlers\ClosePositionHandler;
use App\Services\Recruitment\Handlers\CloseVacancyHandler;
use App\Services\Recruitment\Handlers\CreatePositionHandler;
use App\Services\Recruitment\Handlers\DecreaseHeadcountHandler;
use App\Services\Recruitment\Handlers\FillVacancyHandler;
use App\Services\Recruitment\Handlers\IncreaseHeadcountHandler;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Turning an approved request into a change in the organisation.
 *
 * Approval and execution are two different things, and conflating them is the
 * mistake this class exists to avoid. A request that has been approved is a
 * decision that has been made; it is not yet a post that has closed or an
 * establishment that has shrunk. Two of the six types carry an effective date
 * and must not take effect before it, so `approved` means *pending execution*
 * and only `applied` means done.
 *
 * Execution is deliberately outside the approval's transaction (D44 · D59):
 * if applying fails, the approval stands and the approver is not asked to
 * decide again. The failure is recorded on the request, and the request can be
 * retried — by HR, or by the scheduled run — without anybody re-approving it.
 */
class RecruitmentRequestApplicationService
{
    /** The two types that wait on a date somebody chose. */
    private const DATED = [
        RecruitmentRequestType::DecreaseHeadcount,
        RecruitmentRequestType::ClosePosition,
        RecruitmentRequestType::CloseVacancy,
    ];

    public function __construct(
        private readonly RecruitmentRequestStateMachine $states,
    ) {}

    /**
     * Apply a request's effect, or explain why it is not applied yet.
     *
     * `$force` is HR choosing to execute before the effective date. It is
     * recorded as their decision, with who and when, because bringing a
     * closure forward is an act somebody took and not something the system
     * decided on its own.
     */
    public function apply(RecruitmentRequest $request, ?User $actor = null, bool $force = false): RecruitmentRequest
    {
        $locked = RecruitmentRequest::query()
            ->whereKey($request->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        // Idempotence, first and under the lock. A request already applied is
        // left exactly as it is, whether the second call came from HR's button
        // or from the nightly run finding it a second time.
        if ((int) $locked->status === Status::Applied) {
            return $locked;
        }

        if (! in_array((int) $locked->status, [Status::Approved, Status::FailedApplication], true)) {
            return $locked;
        }

        if (! $force && ! $this->isDue($locked)) {
            return $locked;
        }

        return $this->run($locked, $actor, $force);
    }

    /**
     * Is this request's effect allowed to happen now?
     *
     * Four of the six types have no effective date and are due the moment they
     * are approved. The other two are due when their date arrives.
     */
    public function isDue(RecruitmentRequest $request): bool
    {
        $date = $this->effectiveDate($request);

        return $date === null || ! $date->isFuture();
    }

    /**
     * The date this request's effect was asked to take place, if any.
     *
     * Read from the approved snapshot rather than the live draft: what was
     * approved is what takes effect, and the draft can be edited afterwards.
     */
    public function effectiveDate(RecruitmentRequest $request): ?CarbonInterface
    {
        if (! in_array((int) $request->request_type, self::DATED, true)) {
            return null;
        }

        $raw = $this->snapshot($request)['effective_date'] ?? null;

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        // The start of that day, so a request effective today is due today
        // rather than at the following midnight.
        return Carbon::parse($raw)->startOfDay();
    }

    /** Is this request approved and merely waiting for its date? */
    public function isAwaitingEffectiveDate(RecruitmentRequest $request): bool
    {
        return in_array((int) $request->status, [Status::Approved, Status::FailedApplication], true)
            && ! $this->isDue($request);
    }

    /** The effect itself, with the request's outcome written either way. */
    private function run(RecruitmentRequest $request, ?User $actor, bool $force): RecruitmentRequest
    {
        $snapshot = $this->snapshot($request);
        $early = $force && ! $this->isDue($request);

        try {
            DB::transaction(function () use ($request, $snapshot, $actor, $early): void {
                $events = $this->handlerFor((int) $request->request_type)->apply($request, $snapshot);

                $this->states->apply($request, 'apply_succeeded');
                $request->forceFill(['applied_at' => now()])->save();

                foreach ($events as $event) {
                    $this->record($request, $actor, $event['event_type'], $event['meta'] ?? []);
                }

                $this->record($request, $actor, RecruitmentEventType::RequestApplied, array_filter([
                    'early' => $early ?: null,
                    'effective_date' => $this->effectiveDate($request)?->toDateString(),
                    'triggered_by' => $actor === null ? 'schedule' : 'user',
                ], fn ($value): bool => $value !== null));
            });
        } catch (Throwable $exception) {
            return $this->fail($request, $actor, $exception);
        }

        return $request->fresh();
    }

    /**
     * Record the failure without losing the approval.
     *
     * The status write is its own transaction because the one that failed has
     * been rolled back — including, had we written it there, the record of why.
     */
    private function fail(RecruitmentRequest $request, ?User $actor, Throwable $exception): RecruitmentRequest
    {
        Log::error('Applying a recruitment request failed.', [
            'request_id' => $request->getKey(),
            'request_type' => (int) $request->request_type,
            'exception' => $exception->getMessage(),
        ]);

        DB::transaction(function () use ($request, $actor, $exception): void {
            $fresh = RecruitmentRequest::query()
                ->whereKey($request->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->states->apply($fresh, 'apply_failed');

            $this->record($fresh, $actor, RecruitmentEventType::RequestApplicationFailed, [
                'reason' => $exception->getMessage(),
                'triggered_by' => $actor === null ? 'schedule' : 'user',
            ]);
        });

        return $request->fresh();
    }

    /** @return array<string, mixed> */
    private function snapshot(RecruitmentRequest $request): array
    {
        $version = $request->relationLoaded('latestVersion')
            ? $request->latestVersion
            : $request->versions()->orderByDesc('version_no')->first();

        return (array) ($version?->snapshot_json ?? $request->draft_payload_json ?? []);
    }

    private function handlerFor(int $type): AppliesRecruitmentRequest
    {
        return match ($type) {
            RecruitmentRequestType::FillVacancy => app(FillVacancyHandler::class),
            RecruitmentRequestType::IncreaseHeadcount => app(IncreaseHeadcountHandler::class),
            RecruitmentRequestType::DecreaseHeadcount => app(DecreaseHeadcountHandler::class),
            RecruitmentRequestType::CreatePosition => app(CreatePositionHandler::class),
            RecruitmentRequestType::ClosePosition => app(ClosePositionHandler::class),
            RecruitmentRequestType::CloseVacancy => app(CloseVacancyHandler::class),
            default => throw new InvalidArgumentException("No application handler for request type {$type}."),
        };
    }

    /**
     * Write one audit row.
     *
     * A failure to record must not undo the effect that has already happened,
     * which is the same rule the rest of the module records events under.
     *
     * @param  array<string, mixed>  $meta
     */
    private function record(RecruitmentRequest $request, ?User $actor, int $eventType, array $meta = []): void
    {
        try {
            $version = $request->versions()->orderByDesc('version_no')->first();

            $request->events()->create([
                'request_version_id' => $version?->getKey(),
                'event_type' => $eventType,
                'from_status' => Status::Approved,
                'to_status' => (int) $request->status,
                'actor_user_id' => $actor?->getKey(),
                // The role as it was, never looked up when the event is read.
                'actor_role' => $actor?->myRole() ?? 'system',
                'meta' => $meta === [] ? null : $meta,
            ]);
        } catch (Throwable $exception) {
            Log::warning('Recording a recruitment application event failed.', [
                'request_id' => $request->getKey(),
                'event_type' => $eventType,
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
