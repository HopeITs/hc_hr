<?php

namespace App\Services\Notifications;

use App\Enums\NotificationChannel;
use App\Models\NotificationOutboxEntry;
use App\Models\User;
use App\Notifications\PreferenceEmail;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends outbox rows (push or email) for the job and the every-minute
 * command alike, so both follow the same rules (architecture.md, pipeline
 * steps 3 and 4):
 *
 *   - a row whose in-app notification was already read is dropped
 *     ('read_in_app'): no late push for something already seen;
 *   - the preferences are asked again, since they may have changed while
 *     the row waited ('preferences_changed', or moved later);
 *   - several rows of one person and channel become one summary.
 *
 * Each person+channel is handled under a lock, and rows are re-read inside
 * it, so the job and the command can never send the same row twice.
 */
class OutboxDispatcher
{
    /** Email attempts before a row is given up (push is not retried: the push service already queues it). */
    public const MAX_EMAIL_ATTEMPTS = 3;

    public function __construct(
        private NotificationPreferenceGate $gate,
        private WebPushSender $push,
    ) {}

    /**
     * Everything due now, oldest first. Returns how many rows were handled.
     */
    public function dispatchDue(int $limit = 500): int
    {
        $entries = NotificationOutboxEntry::query()
            ->due()
            ->with('user')
            ->orderBy('deliver_after')
            ->limit($limit)
            ->get();

        $this->deliver($entries, retryLocked: false);

        return $entries->count();
    }

    /**
     * Deliver any set of rows: grouped by person and channel.
     *
     * @param  Collection<int, NotificationOutboxEntry>  $entries
     */
    public function deliver(Collection $entries, bool $retryLocked = true): void
    {
        $entries->groupBy(fn (NotificationOutboxEntry $e) => $e->user_id.'|'.$e->channel)
            ->each(function (Collection $group) use ($retryLocked) {
                $first = $group->first();

                if (! $first->user) {
                    return;
                }

                try {
                    $this->deliverGroup($first->user, $first->channel, $group);
                } catch (LockTimeoutException $e) {
                    // Someone else is sending this person's rows right now.
                    // The job retries; the command just finds them next minute.
                    if ($retryLocked) {
                        throw $e;
                    }
                }
            });
    }

    /**
     * @param  Collection<int, NotificationOutboxEntry>  $entries
     */
    public function deliverGroup(User $user, string $channel, Collection $entries): void
    {
        Cache::lock("notification-outbox:{$user->getKey()}:{$channel}", 120)
            ->block(10, function () use ($user, $channel, $entries) {
                // Re-read inside the lock: another worker may have sent some.
                $pending = NotificationOutboxEntry::query()
                    ->whereKey($entries->modelKeys())
                    ->where('user_id', $user->getKey())
                    ->whereNull('sent_at')
                    ->whereNull('failed_at')
                    ->get();

                $pending = $this->dropRead($pending);
                $pending = $this->dropDisallowed($user, $channel, $pending);
                $pending = $this->postponeQuiet($user, $pending);

                if ($pending->isEmpty()) {
                    return;
                }

                // Urgent rows go one by one (they may reach "urgent only"
                // devices); the rest are summarised when there are several.
                [$urgent, $normal] = $pending->partition(fn (NotificationOutboxEntry $e) => $e->urgent);

                foreach ($urgent as $entry) {
                    $this->send($user, $channel, collect([$entry]));
                }

                if ($normal->isNotEmpty()) {
                    $this->send($user, $channel, $normal->values());
                }
            });
    }

    /**
     * @param  Collection<int, NotificationOutboxEntry>  $entries
     * @return Collection<int, NotificationOutboxEntry>
     */
    private function dropRead(Collection $entries): Collection
    {
        $ids = $entries->pluck('notification_id')->filter()->all();

        if ($ids === []) {
            return $entries;
        }

        $read = DB::table('notifications')->whereIn('id', $ids)->whereNotNull('read_at')->pluck('id')->all();

        return $entries->reject(function (NotificationOutboxEntry $entry) use ($read) {
            if (! in_array($entry->notification_id, $read, true)) {
                return false;
            }

            $this->markSent($entry, 'read_in_app');

            return true;
        })->values();
    }

    /**
     * @param  Collection<int, NotificationOutboxEntry>  $entries
     * @return Collection<int, NotificationOutboxEntry>
     */
    private function dropDisallowed(User $user, string $channel, Collection $entries): Collection
    {
        return $entries->reject(function (NotificationOutboxEntry $entry) use ($user, $channel) {
            $category = $entry->categoryEnum();
            $allowed = $channel === NotificationOutboxEntry::EMAIL
                ? $this->gate->allows($user, $category, NotificationChannel::Email)
                : $this->gate->allows($user, $category, NotificationChannel::Desktop)
                    || $this->gate->allows($user, $category, NotificationChannel::Mobile);

            if ($allowed) {
                return false;
            }

            $this->markSent($entry, 'preferences_changed');

            return true;
        })->values();
    }

    /**
     * Quiet hours or working days set after the row was written move it
     * later instead of sending it into the night.
     *
     * @param  Collection<int, NotificationOutboxEntry>  $entries
     * @return Collection<int, NotificationOutboxEntry>
     */
    private function postponeQuiet(User $user, Collection $entries): Collection
    {
        $now = now();

        return $entries->reject(function (NotificationOutboxEntry $entry) use ($user, $now) {
            $opens = $this->gate->windowOpensAt($user, $entry->categoryEnum(), $now);

            if ($opens->lessThanOrEqualTo($now)) {
                return false;
            }

            $entry->forceFill(['deliver_after' => $opens])->save();

            return true;
        })->values();
    }

    /**
     * @param  Collection<int, NotificationOutboxEntry>  $entries
     */
    private function send(User $user, string $channel, Collection $entries): void
    {
        if ($channel === NotificationOutboxEntry::EMAIL) {
            $this->sendEmail($user, $entries);

            return;
        }

        if ($channel !== NotificationOutboxEntry::PUSH) {
            $entries->each(fn ($e) => $this->markFailed($e, 'unknown_channel'));

            return;
        }

        if (! WebPushSender::configured()) {
            $entries->each(fn ($e) => $this->markFailed($e, 'push_not_configured'));

            return;
        }

        $categories = $entries->map(fn (NotificationOutboxEntry $e) => $e->categoryEnum())->all();
        $urgent = $entries->contains(fn (NotificationOutboxEntry $e) => $e->urgent);
        $payload = $entries->count() === 1
            ? $entries->first()->payload + ['tag' => $entries->first()->notification_id ?? $entries->first()->getKey()]
            : $this->summaryPayload($entries->count());

        try {
            $accepted = $this->push->sendToUserForCategories($user, $payload, $categories, $urgent);
        } catch (Throwable $e) {
            report($e);
            $entries->each(fn ($entry) => $this->markFailed($entry, Str::limit($e->getMessage(), 250, '')));

            return;
        }

        // Not retried when nobody accepted it: the devices' own failure
        // counters already record why, and a retry could repeat it on the
        // ones that did take it.
        $entries->each(fn ($entry) => $this->markSent($entry, $accepted === 0 ? 'no_device_accepted' : null));
    }

    /**
     * @param  Collection<int, NotificationOutboxEntry>  $entries
     */
    private function sendEmail(User $user, Collection $entries): void
    {
        if (blank($user->email)) {
            $entries->each(fn ($e) => $this->markFailed($e, 'no_email_address'));

            return;
        }

        $email = (new PreferenceEmail($entries->map(fn ($e) => (array) $e->payload)->values()->all()))
            ->locale(WebPushSender::localeFor($user));

        try {
            $user->notifyNow($email);
        } catch (Throwable $e) {
            report($e);

            foreach ($entries as $entry) {
                $attempts = $entry->attempts + 1;
                $entry->forceFill([
                    'attempts' => $attempts,
                    'error' => Str::limit($e->getMessage(), 250, ''),
                    // Left unsent below the limit, so the next run tries again.
                    'failed_at' => $attempts >= self::MAX_EMAIL_ATTEMPTS ? now() : null,
                ])->save();
            }

            return;
        }

        $entries->each(fn ($entry) => $this->markSent($entry));
    }

    /** @return array{title: array<string, string>, body: array<string, string>, url: string, tag: string} */
    private function summaryPayload(int $count): array
    {
        $text = fn (string $key) => [
            'en' => __($key, ['count' => $count], 'en'),
            'ar' => __($key, ['count' => $count], 'ar'),
        ];

        return [
            'title' => $text('notifications-delivery.summary.title'),
            'body' => $text('notifications-delivery.summary.body'),
            'url' => route('notifications.index'),
            'tag' => 'summary',
        ];
    }

    private function markSent(NotificationOutboxEntry $entry, ?string $error = null): void
    {
        $entry->forceFill([
            'sent_at' => now(),
            'attempts' => $entry->attempts + 1,
            'error' => $error,
        ])->save();
    }

    private function markFailed(NotificationOutboxEntry $entry, string $error): void
    {
        $entry->forceFill([
            'failed_at' => now(),
            'attempts' => $entry->attempts + 1,
            'error' => $error,
        ])->save();
    }
}
