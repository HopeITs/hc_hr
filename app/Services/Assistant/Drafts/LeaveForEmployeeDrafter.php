<?php

namespace App\Services\Assistant\Drafts;

use App\Livewire\Employee\Leave\Concerns\FormatsLeaveDuration;
use App\Livewire\Forms\LeaveRequestForm;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\Leave\LeaveQuote;
use Carbon\Carbon;
use Closure;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * The All leaves page's "Add" path for another employee, driven without the
 * page: the same LeaveRequestForm with form.employee_id set, as the page's
 * dropdown sets it.
 *
 * Every rule stays in the form: targetEmployee() keeps employee_id inside
 * the HR role's cities, the balance must belong to that employee's latest
 * active contract, the alternative must be one LeaveAlternativeEmployees
 * allows for them, and dates, overlap, December and the balance are priced
 * by LeaveRequestPricing. draft() is the page's confirmSave() (nothing
 * written); store() is processSave()'s create branch (form->store(), which
 * re-validates under its locks). Both run as $user.
 *
 * The employee is also checked against the page's own dropdown
 * (LeaveForEmployeeScreen::employee()) before the form sees it, because the
 * form falls back to the user's own profile when employee_id is empty.
 */
class LeaveForEmployeeDrafter
{
    use FormatsLeaveDuration;

    /**
     * @param  array<string, mixed>  $input  employee_id, leave_balance_id, type (day|hour), start, end, reason, alternative_employee_id, is_exceptional
     * @return array{proposal: array<string, mixed>, quote: ?LeaveQuote}
     *
     * @throws ValidationException
     */
    public function draft(User $user, array $input): array
    {
        return $this->asUser($user, function () use ($input): array {
            $employee = $this->allowedEmployee($input['employee_id'] ?? null);
            $form = $this->form($employee, $input);

            $form->validate();
            $this->ensureUnitAllowed($form);
            $form->ensureNoOwnOverlap();
            $quote = $form->checkedQuote();

            return ['proposal' => $this->proposal($employee, $form, $quote), 'quote' => $quote];
        });
    }

    /**
     * Create the request through the page's store(). A $token already used
     * by this user returns the request it created.
     *
     * @return array{0: LeaveRequest, 1: bool} the request, and whether it was a replay
     *
     * @throws ValidationException
     */
    public function store(User $user, array $proposal, ?string $token = null): array
    {
        return $this->asUser($user, function () use ($proposal, $token): array {
            $employee = $this->allowedEmployee($proposal['employee_id'] ?? null);
            $form = $this->form($employee, $proposal);
            $form->submission_token = $token;

            if ($existing = $form->consumedRequest()) {
                return [$existing, true];
            }

            if ($form->type === 'hour') {
                $this->ensureUnitAllowed($form);
            }

            $form->store();

            return [$form->leaveRequest, $form->wasReplayed()];
        });
    }

    /** Run $callback as $user: the page and its form read the requester from auth(). */
    public function asUser(User $user, Closure $callback): mixed
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

    public function formatSplit(float $hours, ?float $hoursPerDay): string
    {
        return $this->formatLeaveSplit($hours, $hoursPerDay);
    }

    /** @throws ValidationException when the page would not offer this employee */
    private function allowedEmployee(mixed $id): Employee
    {
        $employee = (new LeaveForEmployeeScreen)->employee($id);

        if (! $employee) {
            throw ValidationException::withMessages([
                'form.employee_id' => __('assistant-leave-for.errors.employee'),
            ]);
        }

        return $employee;
    }

    /** @return array<string, mixed> */
    private function proposal(Employee $employee, LeaveRequestForm $form, ?LeaveQuote $quote): array
    {
        $balance = $form->selectedBalance();
        $hourly = $form->type === 'hour';
        $locale = app()->getLocale();
        $alternative = filled($form->alternative_employee)
            ? Employee::query()->find($form->alternative_employee)
            : null;

        return [
            'employee_id' => (int) $employee->id,
            'employee' => $employee->nameForLocale($locale),
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
            'alternative_employee' => $alternative?->nameForLocale($locale),
            'is_exceptional' => (bool) $form->window()?->exceptional,
        ];
    }

    /** As the page: only annual leave offers the hour unit. */
    private function ensureUnitAllowed(LeaveRequestForm $form): void
    {
        if ($form->type === 'hour' && ! $form->selectedBalance()->isAnnual()) {
            throw ValidationException::withMessages([
                'form.type' => __('assistant-leave-for.errors.hours-annual-only'),
            ]);
        }
    }

    /** The page's form, filled as its dialog fills it. Nothing here decides validity. */
    private function form(Employee $employee, array $input): LeaveRequestForm
    {
        $form = new LeaveRequestForm(new class extends Component
        {
            public function render(): string
            {
                return '<div></div>';
            }
        }, 'form');

        $type = $input['type'] ?? 'day';
        $form->employee_id = $employee->id;
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
                    'form.end_hour' => __('assistant-leave-for.errors.hours-one-day'),
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

    /** @return array{0: ?string, 1: ?string} */
    private function dateAndTime(string $value, ?string $defaultDate = null): array
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{1,2}:\d{2})(:\d{2})?$/', $value, $match)) {
            return [$match[1], $this->hhmm($match[2])];
        }

        if (preg_match('/^(\d{1,2}:\d{2})(:\d{2})?$/', $value, $match)) {
            return [$defaultDate, $this->hhmm($match[1])];
        }

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
}
