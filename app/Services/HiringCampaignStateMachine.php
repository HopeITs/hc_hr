<?php

namespace App\Services;

use App\Enums\HiringCampaignStatus as Status;
use App\Models\HiringCampaign;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * The only place a campaign's status is allowed to change.
 *
 * Every move is named in the C1–C9 table of 02-state-machine.md and anything
 * not named there is refused. The campaign decides whether strangers can read
 * a job advert and whether their applications are accepted, so a status
 * written straight onto the column is how a closed campaign quietly starts
 * taking submissions again.
 *
 * One thing here is unlike the request machine: a campaign's status is also a
 * function of the clock. A campaign left `open` with a past `ends_at` is
 * expired whether or not anything has written that to the column yet, so
 * `effective()` is what every gate asks rather than the column itself.
 */
class HiringCampaignStateMachine
{
    /**
     * The transition table, verbatim from the state machine document.
     *
     * Each entry is trigger => [allowed source statuses, target status]. C1 is
     * absent on purpose: it creates the row rather than moving one. C9 is
     * absent too, because its target is computed rather than fixed.
     *
     * @var array<string, array{0: array<int, int>, 1: int}>
     */
    private const TRANSITIONS = [
        // C2 — the advert becomes readable. Applications are still shut (D3).
        'publish' => [[Status::Draft], Status::Published],

        // C3 — applications open, by hand or when starts_at arrives.
        'open' => [[Status::Published], Status::Open],

        // C4 — HR shuts applications while the advert stays readable.
        'close' => [[Status::Open], Status::Closed],

        // C5 · C6 — the window ran out, from either published state.
        'expire' => [[Status::Open, Status::Published], Status::Expired],

        // C7 — reopening, which the service only allows with a future end.
        'reopen' => [[Status::Closed], Status::Open],

        // C8 — administrative, from anywhere, and it hides the advert at once.
        'disable' => [
            [Status::Draft, Status::Published, Status::Open, Status::Closed, Status::Expired],
            Status::Disabled,
        ],
    ];

    /**
     * Move the campaign, or refuse and leave it exactly as it was.
     *
     * The model is saved here rather than handed back unsaved, so a caller
     * cannot take the new status and forget to persist it — or persist one
     * without having asked whether the move was allowed.
     */
    public function apply(HiringCampaign $campaign, string $trigger): HiringCampaign
    {
        $target = $this->resolve($campaign, $trigger);

        if ($target !== (int) $campaign->status) {
            $campaign->forceFill(['status' => $target])->save();
        }

        return $campaign;
    }

    /**
     * Bring a disabled campaign back to where the clock says it belongs (C9).
     *
     * Not to the status it literally held: a campaign that was `open` when it
     * was disabled, and whose `ends_at` passed while it was hidden, comes back
     * `expired`. Restoring it to `open` would quietly reopen applications on a
     * window that has already closed.
     */
    public function reactivate(HiringCampaign $campaign, int $previous): HiringCampaign
    {
        if ((int) $campaign->status !== Status::Disabled) {
            throw $this->refuse('recruitment-campaign.not_disabled');
        }

        $campaign->forceFill(['status' => $this->recompute($campaign, $previous)])->save();

        return $campaign;
    }

    /**
     * The status as the clock sees it, which may be ahead of the column.
     *
     * Every gate asks this rather than reading `status`, because the scheduled
     * sweep that writes expiry runs once a day and a campaign must stop taking
     * applications the moment its window shuts, not the next morning.
     */
    public function effective(HiringCampaign $campaign): int
    {
        $status = (int) $campaign->status;

        if (in_array($status, [Status::Published, Status::Open], true) && $this->hasEnded($campaign)) {
            return Status::Expired;
        }

        return $status;
    }

    /** May this campaign take this trigger right now? */
    public function can(HiringCampaign $campaign, string $trigger): bool
    {
        try {
            $this->resolve($campaign, $trigger);

            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    /** Has the campaign's window closed? A campaign with no end never expires. */
    public function hasEnded(HiringCampaign $campaign): bool
    {
        return $campaign->ends_at !== null && Carbon::parse($campaign->ends_at)->isPast();
    }

    /** Has the campaign's window opened yet? One with no start is open now. */
    public function hasStarted(HiringCampaign $campaign): bool
    {
        return $campaign->starts_at === null || Carbon::parse($campaign->starts_at)->isPast();
    }

    /**
     * @throws ValidationException when the move is not in the table
     */
    private function resolve(HiringCampaign $campaign, string $trigger): int
    {
        if (! array_key_exists($trigger, self::TRANSITIONS)) {
            throw $this->refuse('recruitment-campaign.unknown_transition');
        }

        [$from, $target] = self::TRANSITIONS[$trigger];
        $current = (int) $campaign->status;

        if (! in_array($current, $from, true)) {
            throw $this->refuse('recruitment-campaign.transition_not_allowed');
        }

        return $target;
    }

    /** Where a reactivated campaign belongs, given the window and the clock. */
    private function recompute(HiringCampaign $campaign, int $previous): int
    {
        if (in_array($previous, [Status::Closed, Status::Draft], true)) {
            return $previous;
        }

        if ($this->hasEnded($campaign)) {
            return Status::Expired;
        }

        // It was taking applications and its window still stands, but only if
        // the window has actually begun.
        if ($previous === Status::Open) {
            return $this->hasStarted($campaign) ? Status::Open : Status::Published;
        }

        return Status::Published;
    }

    private function refuse(string $message): ValidationException
    {
        return ValidationException::withMessages(['campaign' => __($message)]);
    }
}
