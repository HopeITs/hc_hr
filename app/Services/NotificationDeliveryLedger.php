<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Claims "this recipient has been told about this subject today" before the
 * message is sent, so a repeated run, a retry or a second worker running at the
 * same moment cannot send it twice.
 *
 * The day is the calendar date in config('app.timezone'), not the database or
 * server clock, so the policy means the same thing wherever the job runs.
 */
class NotificationDeliveryLedger
{
    public function key(string $kind, int $recipientId, int $subjectId, CarbonInterface $day): string
    {
        return sprintf('%s:%d:%d:%s', $kind, $recipientId, $subjectId, $day->toDateString());
    }

    public function today(): CarbonInterface
    {
        return Date::now(config('app.timezone'))->startOfDay();
    }

    /**
     * Claim each subject for the recipient on $day. Returns the subject ids this
     * caller won; the rest were already claimed by an earlier or concurrent run.
     *
     * @param  array<int, int>  $subjectIds
     * @return array<int, int>
     */
    public function claim(string $kind, int $recipientId, array $subjectIds, ?CarbonInterface $day = null): array
    {
        $day ??= $this->today();
        $won = [];

        foreach (array_unique($subjectIds) as $subjectId) {
            $inserted = DB::table('notification_deliveries')->insertOrIgnore([
                'idempotency_key' => $this->key($kind, $recipientId, $subjectId, $day),
                'kind' => $kind,
                'recipient_id' => $recipientId,
                'subject_id' => $subjectId,
                'local_date' => $day->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($inserted === 1) {
                $won[] = $subjectId;
            }
        }

        return $won;
    }

    /** @param  array<int, int>  $subjectIds */
    public function markDelivered(string $kind, int $recipientId, array $subjectIds, ?CarbonInterface $day = null): void
    {
        $day ??= $this->today();

        DB::table('notification_deliveries')
            ->whereIn('idempotency_key', array_map(
                fn (int $id): string => $this->key($kind, $recipientId, $id, $day),
                $subjectIds,
            ))
            ->update(['delivered_at' => now()]);
    }

    /**
     * Give a claim back when sending failed, so the next run can retry it. Only
     * undelivered claims are released; a delivered one is history.
     *
     * @param  array<int, int>  $subjectIds
     */
    public function release(string $kind, int $recipientId, array $subjectIds, ?CarbonInterface $day = null): void
    {
        $day ??= $this->today();

        DB::table('notification_deliveries')
            ->whereIn('idempotency_key', array_map(
                fn (int $id): string => $this->key($kind, $recipientId, $id, $day),
                $subjectIds,
            ))
            ->whereNull('delivered_at')
            ->delete();
    }
}
