<?php

namespace App\Services\Recruitment\Privacy;

use App\Models\RecruitmentApplicant;
use App\Services\Monitoring\SecurityMonitor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The nightly sweep behind recruitment:retention-sweep.
 *
 * Two passes. The first keeps retention_until honest: it is set for whoever
 * has a clock running and cleared for whoever does not (an application was
 * reopened, someone applied again). The second anonymizes:
 *
 *   - every applicant with a pending erasure request, always;
 *   - every applicant whose retention_until has passed, only when
 *     retention.enforce is on. Until the periods are confirmed the sweep just
 *     reports how many that would be ("due_held").
 *
 * A failure on one applicant is reported and skipped; it never stops the rest.
 */
class RecruitmentRetentionService
{
    public function __construct(
        private readonly RetentionPolicy $policy,
        private readonly RecruitmentAnonymizer $anonymizer,
        private readonly SecurityMonitor $monitor,
    ) {}

    /**
     * @return array{stamped: int, cleared: int, anonymized: int, blocked: int, due_held: int, failed: int}
     */
    public function sweep(bool $dryRun = false): array
    {
        $stats = ['stamped' => 0, 'cleared' => 0, 'anonymized' => 0, 'blocked' => 0, 'due_held' => 0, 'failed' => 0];

        $this->stampRetentionDates($dryRun, $stats);
        $this->anonymizeDue($dryRun, $stats);

        return $stats;
    }

    /** @param array<string, int> $stats */
    private function stampRetentionDates(bool $dryRun, array &$stats): void
    {
        RecruitmentApplicant::query()
            ->whereNull('anonymized_at')
            // An erasure request set retention_until to the moment it was made;
            // the policy's longer date must not push it back out.
            ->whereNull('erasure_requested_at')
            ->chunkById(200, function (Collection $applicants) use ($dryRun, &$stats): void {
                foreach ($applicants as $applicant) {
                    $due = $this->policy->dueAt($applicant);

                    if ($this->sameMoment($due, $applicant->retention_until)) {
                        continue;
                    }

                    if (! $dryRun) {
                        DB::table('recruitment_applicants')->where('id', $applicant->getKey())->update([
                            'retention_until' => $due,
                        ]);
                    }

                    $due === null ? $stats['cleared']++ : $stats['stamped']++;
                }
            });
    }

    /** @param array<string, int> $stats */
    private function anonymizeDue(bool $dryRun, array &$stats): void
    {
        $enforce = (bool) config('recruitment-privacy.retention.enforce');

        if (! $enforce) {
            $stats['due_held'] = RecruitmentApplicant::query()
                ->whereNull('anonymized_at')
                ->whereNull('erasure_requested_at')
                ->where('retention_until', '<=', now())
                ->count();
        }

        RecruitmentApplicant::query()
            ->whereNull('anonymized_at')
            ->where(function ($query) use ($enforce): void {
                $query->whereNotNull('erasure_requested_at');

                if ($enforce) {
                    $query->orWhere('retention_until', '<=', now());
                }
            })
            ->chunkById(50, function (Collection $applicants) use ($dryRun, &$stats): void {
                foreach ($applicants as $applicant) {
                    $this->anonymizeOne($applicant, $dryRun, $stats);
                }
            });
    }

    /** @param array<string, int> $stats */
    private function anonymizeOne(RecruitmentApplicant $applicant, bool $dryRun, array &$stats): void
    {
        try {
            if ($dryRun) {
                $this->policy->blocker($applicant) === null ? $stats['anonymized']++ : $stats['blocked']++;

                return;
            }

            $blocker = $this->anonymizer->anonymize(
                $applicant,
                $applicant->erasure_requested_at !== null
                    ? RecruitmentAnonymizer::REASON_ERASURE
                    : RecruitmentAnonymizer::REASON_RETENTION,
            );

            $blocker === null ? $stats['anonymized']++ : $stats['blocked']++;
        } catch (Throwable $exception) {
            report($exception);
            $stats['failed']++;

            $this->monitor->record(SecurityMonitor::RETENTION_FAILED, [
                'applicant_id' => $applicant->getKey(),
                'exception' => class_basename($exception),
            ]);
        }
    }

    private function sameMoment(?CarbonImmutable $due, mixed $stored): bool
    {
        if ($due === null || $stored === null) {
            return $due === null && $stored === null;
        }

        return $due->getTimestamp() === $stored->getTimestamp();
    }
}
