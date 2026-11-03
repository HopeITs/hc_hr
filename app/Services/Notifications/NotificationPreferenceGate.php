<?php

namespace App\Services\Notifications;

use App\Enums\NotificationCategory;
use App\Enums\NotificationChannel;
use App\Models\NotificationPreference;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The one place that decides whether a notification may reach someone on a
 * channel, and when (docs/notifications/architecture.md "Delivery rules").
 * The listener, the outbox dispatcher and the push sender all ask here, so a
 * rule changed here changes everywhere at once.
 *
 * Bound as a scoped singleton: the preference row is read once per request
 * or queued job, and forgotten whenever a preference is saved
 * (AppServiceProvider) so a change made earlier in the same request counts.
 */
class NotificationPreferenceGate
{
    /** @var array<int, NotificationPreference> */
    private array $preferences = [];

    public function preferenceFor(User $user): NotificationPreference
    {
        return $this->preferences[$user->getKey()] ??= NotificationPreference::for($user);
    }

    public function forget(?int $userId = null): void
    {
        if ($userId === null) {
            $this->preferences = [];

            return;
        }

        unset($this->preferences[$userId]);
    }

    /**
     * Master switch AND the channel's own switch AND the category's cell. The
     * three are checked separately (never folded into one another) so turning
     * the master switch back on restores exactly what was set before.
     */
    public function allows(User $user, NotificationCategory $category, NotificationChannel $channel): bool
    {
        $preference = $this->preferenceFor($user);

        return $preference->enabled
            && $preference->channelEnabled($channel)
            && ($preference->categoryChannels($category)[$channel->value] ?? false);
    }

    /**
     * The master and channel switches only, without the category matrix. A
     * test notification belongs to no category the person picked, so testing
     * a channel asks just this.
     */
    public function channelOpen(User $user, NotificationChannel $channel): bool
    {
        $preference = $this->preferenceFor($user);

        return $preference->enabled && $preference->channelEnabled($channel);
    }

    /**
     * When push / email for this category may go out: now, the next digest
     * moment, or the end of quiet hours / the next working day. Calculated in
     * the person's time zone, returned in the app's.
     */
    public function deliverAt(User $user, NotificationCategory $category, ?CarbonInterface $now = null): CarbonInterface
    {
        $preference = $this->preferenceFor($user);
        $now = CarbonImmutable::instance($now ?? now());

        if ($this->bypasses($preference, $category)) {
            return $now->setTimezone(config('app.timezone'));
        }

        $local = $now->setTimezone($preference->timezoneName());

        $candidate = match ($preference->frequency) {
            'hourly' => $this->isTopOfHour($local) ? $local : $local->startOfHour()->addHour(),
            'daily' => $this->nextDigest($local, $preference->digest_time),
            default => $local,
        };

        return $this->nextOpenMoment($preference, $candidate)->setTimezone(config('app.timezone'));
    }

    /**
     * Rules 1 and 2 only (urgent bypass, quiet hours, working days), without
     * the digest step. The dispatcher re-checks this at send time: settings
     * may have changed since the row was written, but re-applying the digest
     * to a row already waiting for it would push it back forever.
     */
    public function windowOpensAt(User $user, NotificationCategory $category, ?CarbonInterface $now = null): CarbonInterface
    {
        $preference = $this->preferenceFor($user);
        $now = CarbonImmutable::instance($now ?? now());

        if ($this->bypasses($preference, $category)) {
            return $now->setTimezone(config('app.timezone'));
        }

        return $this->nextOpenMoment($preference, $now->setTimezone($preference->timezoneName()))
            ->setTimezone(config('app.timezone'));
    }

    private function bypasses(NotificationPreference $preference, NotificationCategory $category): bool
    {
        return $category->urgent() && $preference->urgent_bypass;
    }

    private function isTopOfHour(CarbonImmutable $time): bool
    {
        return $time->minute === 0 && $time->second === 0;
    }

    private function nextDigest(CarbonImmutable $local, ?string $digestTime): CarbonImmutable
    {
        [$hour, $minute] = $this->parseTime($digestTime, [9, 0]);
        $digest = $local->setTime($hour, $minute);

        return $digest->lessThan($local) ? $digest->addDay() : $digest;
    }

    /**
     * The first moment at or after $time that is on a working day and
     * outside quiet hours. Each step moves strictly forward, and a week of
     * steps covers every combination, so the loop is bounded.
     */
    private function nextOpenMoment(NotificationPreference $preference, CarbonImmutable $time): CarbonImmutable
    {
        // An empty list would mean "never": treat it like no restriction
        // rather than hold every push and email back forever.
        $workingDays = $preference->workingDays() ?: range(0, 6);

        for ($step = 0; $step < 32; $step++) {
            if (! in_array($time->dayOfWeek, $workingDays, true)) {
                $time = $time->addDay()->startOfDay();

                continue;
            }

            $quietEnd = $this->quietEnd($preference, $time);

            if ($quietEnd === null) {
                return $time;
            }

            $time = $quietEnd;
        }

        return $time;
    }

    /** The moment the quiet period containing $time ends, or null when $time is not quiet. */
    private function quietEnd(NotificationPreference $preference, CarbonImmutable $time): ?CarbonImmutable
    {
        if (! $preference->quiet_hours) {
            return null;
        }

        [$startHour, $startMinute] = $this->parseTime($preference->quiet_start, [22, 0]);
        [$endHour, $endMinute] = $this->parseTime($preference->quiet_end, [7, 0]);
        $start = $startHour * 60 + $startMinute;
        $end = $endHour * 60 + $endMinute;
        $minute = $time->hour * 60 + $time->minute;

        if ($start === $end) {
            return null;
        }

        if ($start < $end) {
            return $minute >= $start && $minute < $end ? $time->setTime($endHour, $endMinute) : null;
        }

        // Wraps midnight (22:00 → 07:00).
        if ($minute >= $start) {
            return $time->addDay()->setTime($endHour, $endMinute);
        }

        return $minute < $end ? $time->setTime($endHour, $endMinute) : null;
    }

    /**
     * @param  array{int, int}  $fallback
     * @return array{int, int}
     */
    private function parseTime(?string $value, array $fallback): array
    {
        if (! is_string($value) || ! preg_match('/^(\d{1,2}):(\d{2})/', $value, $m)) {
            return $fallback;
        }

        $hour = (int) $m[1];
        $minute = (int) $m[2];

        return $hour < 24 && $minute < 60 ? [$hour, $minute] : $fallback;
    }
}
