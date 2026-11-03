<?php

namespace App\Services\Assistant;

use App\Livewire\Employee\Leave\Concerns\FormatsLeaveDuration;
use App\Livewire\Forms\LeaveRequestForm;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\Leave\LeaveQuote;
use Carbon\Carbon;
use Closure;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * The My leaves page's own new-request path, driven without the page.
 *
 * Every rule lives in LeaveRequestForm (balance of the user's latest active
 * contract, allowed alternative, dates, overlap, pricing, December, balance)
 * and LeaveRequestPricing. This class only fills that form from a chat
 * draft and runs the same calls the page runs: draft() is confirmSave()
 * (validate, own overlap, checked quote: nothing written), store() is the
 * create branch of processSave() (store() re-validates under its locks).
 *
 * The form reads the requester from auth(), so both run as $user.
 */
class LeaveRequestDrafter
{
    use FormatsLeaveDuration;

    /**
     * Validate a request exactly as the page does before its confirmation
     * dialog. Writes nothing.
     *
     * @param  array<string, mixed>  $input  leave_balance_id, type (day|hour), start, end, reason, alternative_employee_id, is_exceptional
     * @return array{proposal: array<string, mixed>, quote: LeaveQuote}
     *
     * @throws ValidationException
     */
    public function draft(User $user, array $input): array
    {
        return $this->asUser($user, function () use ($input): array {
            $form = $this->form($input);

            $form->validate();
            $this->ensureUnitAllowed($form);
            $form->ensureNoOwnOverlap();
            $quote = $form->checkedQuote();

            return ['proposal' => $this->proposal($form, $quote), 'quote' => $quote];
        });
    }

    /**
     * Create the request through the page's store(): one pending row with
     * the server's own price snapshot, written under the same locks and
     * re-checked there. A $token already used returns the request it created.
     *
     * @return array{0: LeaveRequest, 1: bool} the request, and whether it was a replay
     *
     * @throws ValidationException
     */
    public function store(User $user, array $proposal, ?string $token = null): array
    {
        return $this->asUser($user, function () use ($proposal, $token): array {
            $form = $this->form($proposal);
            $form->submission_token = $token;

            // A second confirm of the same draft: the request the first one
            // created (store() repeats this under its lock).
            if ($existing = $form->consumedRequest()) {
                return [$existing, true];
            }

            if ($form->type === 'hour') {
                $this->ensureUnitAllowed($form);
            }

            // Validates every rule again (time may have passed, the balance
            // may have changed), then checks overlap and price under its locks.
            $form->store();

            return [$form->leaveRequest, $form->wasReplayed()];
        });
    }

    /** An amount of hours worded as the page words it ("2 days and 3 hours"). */
    public function formatSplit(float $hours, ?float $hoursPerDay): string
    {
        return $this->formatLeaveSplit($hours, $hoursPerDay);
    }

    /** Every message of a validation failure, in order, without duplicates. */
    public static function messages(ValidationException $e): array
    {
        return array_values(array_unique(array_merge(...array_values($e->errors()))));
    }

    /**
     * The proposal shape of AssistantReply::$leaveProposal.
     *
     * @return array<string, mixed>
     */
    private function proposal(LeaveRequestForm $form, ?LeaveQuote $quote): array
    {
        $balance = $form->selectedBalance();
        $hourly = $form->type === 'hour';
        $alternative = filled($form->alternative_employee)
            ? Employee::query()->find($form->alternative_employee)
            : null;

        return [
            'leave_balance_id' => (int) $balance->id,
            'leave_type' => (string) $balance->leaveType?->name,
            'type' => $hourly ? 'hour' : 'day',
            'start' => $hourly
                ? Carbon::parse($form->leave_date)->format('Y-m-d').' '.$this->hhmm($form->start_hour)
                : Carbon::parse($form->start_at)->format('Y-m-d'),
            'end' => $hourly
                ? Carbon::parse($form->leave_date)->format('Y-m-d').' '.$this->hhmm($form->end_hour)
                : Carbon::parse($form->end_at)->format('Y-m-d'),
            'duration' => $quote ? $this->formatLeaveSplit($quote->requestedHours, $quote->hoursPerDay) : '',
            'reason' => filled($form->reason) ? (string) $form->reason : null,
            'alternative_employee_id' => $alternative?->id,
            'alternative_employee' => $alternative?->nameForLocale(app()->getLocale()),
            'is_exceptional' => (bool) $form->window()?->exceptional,
        ];
    }

    /**
     * Only annual leave can be taken by the hour: the page offers the unit
     * for annual balances only (and switches back to days otherwise).
     */
    private function ensureUnitAllowed(LeaveRequestForm $form): void
    {
        if ($form->type === 'hour' && ! $form->selectedBalance()->isAnnual()) {
            throw ValidationException::withMessages([
                'form.type' => $this->say(
                    'assistant.leave.errors.hours-annual-only',
                    'Only annual leave can be taken by the hour.',
                ),
            ]);
        }
    }

    /** The page's form, filled from a draft. Nothing here decides validity. */
    private function form(array $input): LeaveRequestForm
    {
        $form = new LeaveRequestForm(new class extends Component
        {
            public function render(): string
            {
                return '<div></div>';
            }
        }, 'form');

        $type = $input['type'] ?? 'day';
        $form->type = is_string($type) ? $type : 'day';
        $form->leave_balance_id = $input['leave_balance_id'] ?? null;
        $form->reason = trim((string) ($input['reason'] ?? ''));
        $form->alternative_employee = filled($input['alternative_employee_id'] ?? null)
            ? $input['alternative_employee_id']
            : null;
        $form->is_exceptional = filter_var($input['is_exceptional'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $start = trim((string) ($input['start'] ?? ''));
        $end = trim((string) ($input['end'] ?? ''));

        if ($form->type === 'hour') {
            [$startDate, $startTime] = $this->dateAndTime($start);
            [$endDate, $endTime] = $this->dateAndTime($end, $startDate);

            if ($endDate !== null && $startDate !== null && $endDate !== $startDate) {
                throw ValidationException::withMessages([
                    'form.end_hour' => $this->say(
                        'assistant.leave.errors.hours-one-day',
                        'An hourly leave must start and end on the same day.',
                    ),
                ]);
            }

            $form->leave_date = $startDate;
            $form->start_hour = $startTime;
            $form->end_hour = $endTime;
        } else {
            $form->start_at = $this->dateOnly($start);
            $form->end_at = $this->dateOnly($end);
        }

        return $form;
    }

    /**
     * "2026-11-05 09:30" (or a time alone, on $defaultDate) as [date, time].
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function dateAndTime(string $value, ?string $defaultDate = null): array
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{1,2}:\d{2})(:\d{2})?$/', $value, $match)) {
            return [$match[1], $this->hhmm($match[2])];
        }

        if (preg_match('/^(\d{1,2}:\d{2})(:\d{2})?$/', $value, $match)) {
            return [$defaultDate, $this->hhmm($match[1])];
        }

        // Left for the form's own date / time rules to report.
        return [$value !== '' ? $value : null, null];
    }

    private function dateOnly(string $value): string
    {
        return preg_match('/^(\d{4}-\d{2}-\d{2})([ T].*)?$/', $value, $match) ? $match[1] : $value;
    }

    private function hhmm(?string $time): ?string
    {
        if ($time === null || ! preg_match('/^(\d{1,2}):(\d{2})/', $time, $match)) {
            return $time;
        }

        return str_pad($match[1], 2, '0', STR_PAD_LEFT).':'.$match[2];
    }

    private function say(string $key, string $fallback): string
    {
        return Lang::has($key) ? __($key) : $fallback;
    }

    /**
     * Run $callback with $user as the authenticated user: the form reads the
     * requester (and the submission token's owner) from auth().
     */
    private function asUser(User $user, Closure $callback): mixed
    {
        $guard = auth()->guard();
        $previous = $guard->user();

        if ($previous?->is($user)) {
            return $callback();
        }

        $guard->setUser($user);

        try {
            return $callback();
        } finally {
            $previous ? $guard->setUser($previous) : $guard->forgetUser();
        }
    }
}
