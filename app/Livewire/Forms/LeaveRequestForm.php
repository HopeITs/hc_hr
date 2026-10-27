<?php

namespace App\Livewire\Forms;

use App\Enums\LeaveRequestStatus;
use App\Exceptions\LeaveDecisionAlreadyMade;
use App\Livewire\Employee\Leave\Concerns\FormatsLeaveDuration;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Services\LeaveAlternativeEmployees;
use App\Services\LeaveDecisionAuthority;
use App\Services\LeaveRequestPricing;
use App\Support\Leave\LeaveQuote;
use App\Support\Leave\LeaveWindow;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Validate;
use Livewire\Form;
use LogicException;
use Throwable;

class LeaveRequestForm extends Form
{
    use FormatsLeaveDuration;

    /** The typed values store()/update() restore after a failed save. */
    private const INPUT_FIELDS = [
        'leave_balance_id', 'type', 'start_at', 'end_at', 'leave_date',
        'start_hour', 'end_hour', 'reason', 'alternative_employee', 'is_exceptional',
    ];

    /** Longest single request, in days (a guard for the day-by-day pricing loop). */
    private const MAX_SPAN_DAYS = 366;

    /** Rounding tolerance for hour comparisons (the quote rounds to 2 decimals). */
    private const EPSILON = 0.005;

    public ?LeaveRequest $leaveRequest = null;

    public ?LeaveRequest $decisionLeave = null;

    public $leave_balance_id = null;

    public $id = null;

    public $employee_id = null;

    // For hour-based requests
    public $leave_date = null;

    public $start_hour = null;

    public $end_hour = null;

    // For day-based requests
    public $start_at = '';

    public $end_at = '';

    #[Validate('required|string|min:3')]
    public $reason = '';

    public $alternative_employee = null;

    #[Validate('nullable|exists:employees,id')]
    public $approved_by = null;

    public $reject_reason = null;

    public $approved_at = null;

    public $status = LeaveRequestStatus::Pending;

    public $type = 'day'; // matches DB column enum ['day', 'hour']

    /**
     * The requester marks a December leave as exceptional (A3-D D-7). Only
     * meaningful when the period touches December; stored as is_exceptional.
     * The manager + HR double approval it calls for is C3's, not built here.
     */
    public $is_exceptional = false;

    /**
     * Issued when the create form opens, consumed by a successful store().
     * A replay with the same token returns the request it already created.
     */
    public ?string $submission_token = null;

    /** Set by store() for the current request only; never sent to the browser. */
    protected bool $replayed = false;

    /**
     * Dynamic validation rules depending on leave type
     */
    public function rules()
    {
        $employee = $this->targetEmployee();
        $activeContract = $employee?->latestActiveContract();

        $rules = [
            'type' => ['required', Rule::in(['day', 'hour'])],
            'reason' => ['required', 'string', 'min:3'],
            'is_exceptional' => ['boolean'],
            'leave_balance_id' => [
                'required',
                Rule::exists('leave_balances', 'id')
                    ->where('contract_id', $activeContract?->id ?? 0),
            ],
            'alternative_employee' => [
                'nullable',
                Rule::in($employee ? app(LeaveAlternativeEmployees::class)->query($employee)->pluck('id')->all() : []),
            ],
        ];

        // Working hours, the 15-minute minimum, countable days, type maximum,
        // December and the balance are the pricing service's errors
        // (quote()->errors, see assertQuoteAllows()). Only the shape of the
        // input is checked here. The old half-day cap is gone (D-3b).
        if ($this->type === 'hour') {
            $rules['leave_date'] = ['required', 'date'];
            $rules['start_hour'] = ['required', 'date_format:H:i'];
            $rules['end_hour'] = [
                'required',
                'date_format:H:i',
                function ($attribute, $value, $fail) {
                    if (! $this->start_hour || ! $this->end_hour) {
                        return;
                    }

                    if (LeaveWindow::minuteOfDay((string) $value) <= LeaveWindow::minuteOfDay((string) $this->start_hour)) {
                        $fail(__('leaves.validation.end-hour-after-start-hour'));
                    }
                },
            ];
        } else {
            $rules['start_at'] = ['required', 'date', 'after_or_equal:'.today()->format('d-m-Y')];
            $rules['end_at'] = [
                'required',
                'date',
                'after_or_equal:start_at',
                function ($attribute, $value, $fail) {
                    if ($this->spanTooLong()) {
                        $fail(__('leaves.validation.range-too-long', ['days' => self::MAX_SPAN_DAYS]));
                    }
                },
            ];
        }

        return $rules;
    }

    /**
     * What the user can change, for the dialog's close guard (A5-M).
     *
     * @return array<string, mixed>
     */
    public function entries(): array
    {
        return $this->only(self::INPUT_FIELDS);
    }

    /**
     * Set form state from an existing leave request
     */
    public function setLeaveRequest(LeaveRequest $leaveRequest): void
    {

        $this->leaveRequest = $leaveRequest;
        $this->fill($leaveRequest->only([
            'id',
            'leave_balance_id',
            'start_at',
            'end_at',
            'reason',
            'approved_by',
            'approved_at',
            'status',
            'type',
        ]));
        $this->alternative_employee = $leaveRequest->alternative_employee_id;
        $this->is_exceptional = (bool) $leaveRequest->is_exceptional;
        // Handle hour-based requests
        if ($leaveRequest->type === 'hour') {
            $this->leave_date = Carbon::parse($leaveRequest->start_at)->toDateString();
            $this->start_hour = Carbon::parse($leaveRequest->start_at)->format('H:i');
            $this->end_hour = Carbon::parse($leaveRequest->end_at)->format('H:i');
        } else {
            $this->start_at = Carbon::parse($leaveRequest->start_at)->toDateString();
            $this->end_at = Carbon::parse($leaveRequest->end_at)->toDateString();
        }
    }

    public function setDecisionLeave(LeaveRequest $leaveRequest): void
    {
        $this->decisionLeave = $leaveRequest;
        $this->fill($leaveRequest->only([
            'id',
            'approved_by',
            'approved_at',
        ]));

        // A decision must always be an explicit choice made in this modal.
        $this->status = null;
        $this->reject_reason = null;
        $this->resetValidation();
    }

    public function submitDecision(): LeaveRequest
    {
        if (! $this->decisionLeave) {
            throw new LogicException('A leave request must be selected before submitting a decision.');
        }

        // Before validation, so nobody is asked for a reason on a decision
        // that can no longer be made. Repeated under the lock below.
        if ($current = LeaveRequest::query()->find($this->decisionLeave->id)) {
            $this->assertStillPending($current);
        }

        $this->validate([
            'status' => [
                'required',
                Rule::in([
                    LeaveRequestStatus::Accepted,
                    LeaveRequestStatus::Rejected,
                ]),
            ],
            'reject_reason' => [
                Rule::requiredIf(fn () => (int) $this->status === LeaveRequestStatus::Rejected),
                'nullable',
                'string',
                'max:255',
            ],
        ], attributes: [
            'status' => __('general.action'),
            'reject_reason' => __('general.reject-reason'),
        ]);

        return DB::transaction(function (): LeaveRequest {
            $leaveRequest = LeaveRequest::query()
                ->with('leaveBalance.contract.position.center')
                ->lockForUpdate()
                ->findOrFail($this->decisionLeave->id);

            // Authorization is intentionally checked after the row lock. A
            // concurrent decision must observe the latest persisted status.
            $this->assertStillPending($leaveRequest);
            Gate::authorize('decide', $leaveRequest);

            // How the decider was entitled (C3): the direct manager, or HR on
            // their behalf / with no direct manager. Stored with the decision.
            $authority = app(LeaveDecisionAuthority::class);
            $basis = $authority->basisFor(auth()->user(), $leaveRequest);
            $onBehalfOf = $basis === LeaveDecisionAuthority::ON_BEHALF
                ? $authority->directManagers($leaveRequest)->first()?->id
                : null;

            if ((int) $this->status === LeaveRequestStatus::Accepted) {
                // Lock order: request, then balance (store/update: employee,
                // then balance; update locks its request first as well).
                $balance = LeaveBalance::query()
                    ->with(['leaveType', 'contract'])
                    ->lockForUpdate()
                    ->findOrFail($leaveRequest->leave_balance_id);

                // The amount priced when the request was sent (D-5 snapshot);
                // a request from before the snapshot keeps the rule that
                // priced it (§4.2), so `used` reconciles with history.
                $hours = $this->deductionHours($leaveRequest);

                try {
                    $this->assertDecisionFitsBalance($balance, $hours);
                } catch (ValidationException $e) {
                    throw $this->linkErrorsToFields($e);
                }

                $leaveRequest->update([
                    'status' => LeaveRequestStatus::Accepted,
                    'reject_reason' => null,
                    'approved_at' => now(),
                    'approved_by' => auth()->user()->profile->id,
                    'decision_basis' => $basis,
                    'decided_on_behalf_of' => $onBehalfOf,
                ]);

                if ($hours > 0) {
                    $balance->increment('used', $hours);
                }
            } else {
                $leaveRequest->update([
                    'status' => LeaveRequestStatus::Rejected,
                    'reject_reason' => $this->reject_reason,
                    'approved_at' => now(),
                    'approved_by' => auth()->user()->profile->id,
                    'decision_basis' => $basis,
                    'decided_on_behalf_of' => $onBehalfOf,
                ]);
            }

            return $this->decisionLeave = $leaveRequest->refresh();
        });
    }

    /**
     * Store a new leave request.
     *
     * Duplicate protection (A3, AC-A3-03). A double click, a retried request
     * after a timeout, or a replayed payload must create one row and one set
     * of notifications:
     *
     *  1. submission_token is issued when the form opens. Under a cache lock
     *     on that token, a token already consumed returns the stored request
     *     instead of writing again (wasReplayed() tells the caller to skip the
     *     notifications).
     *  2. Inside the transaction the requester's employee row and the balance
     *     row are locked, and own overlapping Pending/Accepted requests are
     *     looked up again, so two different tokens for the same dates (two
     *     tabs) still produce one row.
     *
     * Disabled buttons in the browser are only feedback; this is the guard.
     */
    public function store(): void
    {
        $this->replayed = false;
        $key = $this->submission_token
            ? self::submissionKey((int) auth()->id(), (string) $this->submission_token)
            : null;

        $this->keepingInputOnFailure(function () use ($key): void {
            if ($key === null) {
                $this->storeOnce(null);

                return;
            }

            try {
                Cache::lock($key.':lock', 30)->block(10, fn () => $this->storeOnce($key));
            } catch (LockTimeoutException) {
                throw ValidationException::withMessages([
                    'submission' => __('leaves.submit.in-progress'),
                ]);
            }
        });
    }

    /**
     * True when the last store() found its token already used and returned
     * that earlier request instead of writing a new one.
     */
    public function wasReplayed(): bool
    {
        return $this->replayed;
    }

    public static function submissionKey(int $userId, string $token): string
    {
        return 'leave-request-submission:'.$userId.':'.sha1($token);
    }

    /**
     * Update an existing leave request
     */
    public function update(): void
    {
        $this->keepingInputOnFailure(fn () => $this->updateOnce());
    }

    public function selectedBalance(): LeaveBalance
    {
        $activeContract = $this->targetEmployee()?->latestActiveContract();
        $leaveBalance = $activeContract?->leaveBalances()
            ->with(['leaveType', 'contract.position.center'])
            ->find($this->leave_balance_id);

        if (! $leaveBalance) {
            throw ValidationException::withMessages([
                'leave_balance_id' => __('validation.exists', [
                    'attribute' => trans_choice('general.leave-type', 1),
                ]),
            ]);
        }

        return $leaveBalance;
    }

    /**
     * Own-overlap check for the dates/hours currently in the form, for use
     * before the confirmation dialog. store()/update() repeat it under a lock.
     */
    public function ensureNoOwnOverlap(): void
    {
        $employeeId = $this->leaveRequest
            ? $this->leaveRequest->leaveBalance?->contract?->employee_id
            : $this->selectedBalance()->contract->employee_id;

        try {
            $this->assertNoOwnOverlap((int) $employeeId, $this->leaveRequest?->id);
        } catch (ValidationException $e) {
            throw $this->linkErrorsToFields($e);
        }
    }

    /**
     * The window typed in the form, as the pricing service reads it, or null
     * while the form is partial or unparseable. Never throws and never
     * changes the typed values. The December flag is carried only when the
     * period touches December, so a stale tick on another month is ignored.
     */
    public function window(): ?LeaveWindow
    {
        try {
            if ($this->type === 'hour') {
                if (! self::isDate($this->leave_date) || ! self::isTime($this->start_hour) || ! self::isTime($this->end_hour)) {
                    return null;
                }

                $build = fn (bool $exceptional) => LeaveWindow::hours((string) $this->leave_date, (string) $this->start_hour, (string) $this->end_hour, $exceptional);
            } elseif ($this->type === 'day') {
                if (! self::isDate($this->start_at) || ! self::isDate($this->end_at)) {
                    return null;
                }

                $build = fn (bool $exceptional) => LeaveWindow::days($this->start_at, $this->end_at, $exceptional);
            } else {
                return null;
            }

            $window = $build(false);
        } catch (Throwable) {
            return null;
        }

        return filter_var($this->is_exceptional, FILTER_VALIDATE_BOOLEAN) && self::touchesDecember($window)
            ? $build(true)
            : $window;
    }

    /**
     * Does the typed period include any date in December? Drives whether the
     * "exceptional request" checkbox is offered (D-7).
     */
    public function periodTouchesDecember(): bool
    {
        $window = $this->window();

        return $window !== null && self::touchesDecember($window);
    }

    public static function touchesDecember(LeaveWindow $window): bool
    {
        for ($month = $window->startDate->startOfMonth(); $month->lte($window->endDate); $month = $month->addMonth()) {
            if ($month->month === 12) {
                return true;
            }
        }

        return false;
    }

    /**
     * Price the typed window against $balance (A3-D §2.3). The request being
     * edited is left out of pending.
     */
    public function quoteFor(LeaveBalance $balance, LeaveWindow $window, ?LeaveRequest $excluding = null): LeaveQuote
    {
        return app(LeaveRequestPricing::class)->quote($balance->contract, $balance, $window, $excluding);
    }

    /**
     * The live impact summary for the form, priced against a balance of
     * $requester's own active contract only (§9: the quote is the owner's or
     * city-scoped HR's to see). Null — never an exception — for a partial,
     * invalid or out-of-range form.
     */
    public function previewQuote(Employee $requester): ?LeaveQuote
    {
        try {
            if (blank($this->leave_balance_id) || $this->spanTooLong()) {
                return null;
            }

            $window = $this->window();

            if ($window === null) {
                return null;
            }

            $balance = $requester->latestActiveContract()
                ?->leaveBalances()
                ->with(['leaveType', 'contract.position.center'])
                ->find($this->leave_balance_id);

            $editing = $this->leaveRequest?->exists ? $this->leaveRequest : null;

            return $balance ? $this->quoteFor($balance, $window, $editing) : null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * The quote for the confirmation dialog, with its blocking errors raised
     * as field-linked validation errors (the same check store()/update()
     * repeat under a lock).
     */
    public function checkedQuote(): ?LeaveQuote
    {
        try {
            $balance = $this->selectedBalance();
            $window = $this->requireWindow();
            $original = $this->leaveRequest?->exists ? $this->leaveRequest : null;
            $accepted = $original && (int) $original->status === LeaveRequestStatus::Accepted;

            if ($accepted && ! $this->windowDiffersFrom($original, $balance, $window)) {
                // A content-only edit of an accepted request prices nothing.
                return null;
            }

            $quote = $this->quoteFor($balance, $window, $original);
            $sameBalance = $original && (int) $original->leave_balance_id === (int) $balance->id;

            $this->assertQuoteAllows(
                $quote,
                $balance,
                $window,
                releasedHours: $accepted && $sameBalance ? $this->deductionHours($original) : 0.0,
                committedOnly: $accepted,
            );

            return $quote;
        } catch (ValidationException $e) {
            throw $this->linkErrorsToFields($e);
        }
    }

    /**
     * Field names used in validation messages, in the current locale.
     *
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'type' => __('leaves.unit.legend'),
            'reason' => __('general.reason'),
            'leave_balance_id' => trans_choice('general.leave-type', 1),
            'alternative_employee' => __('general.alternative-employee'),
            'start_at' => __('general.start-date'),
            'end_at' => __('general.end-date'),
            'leave_date' => __('general.leave-date'),
            'start_hour' => __('general.start-time'),
            'end_hour' => __('general.end-time'),
        ];
    }

    /**
     * The columns an edit is allowed to change. status / approved_* / hr_approved
     * must never come from the form — they are set by submitDecision() and the
     * HR-seen toggle only.
     *
     * @return array<string, mixed>
     */
    protected function contentAttributes(): array
    {
        return [
            'leave_balance_id' => $this->leave_balance_id,
            'type' => $this->type,
            'start_at' => $this->start_at,
            'end_at' => $this->end_at,
            'reason' => $this->reason,
            'alternative_employee_id' => $this->alternative_employee,
        ];
    }

    /**
     * Combine date + time safely
     */
    protected function combineDateTime(?string $date, ?string $time): ?Carbon
    {
        if (! $date || ! $time) {
            return null;
        }

        return Carbon::parse($date.' '.$time);
    }

    protected function resetForm(): void
    {
        $this->reset([
            'id',
            'leave_balance_id',
            'leave_date',
            'start_hour',
            'end_hour',
            'start_at',
            'end_at',
            'reason',
            'alternative_employee',
            'approved_by',
            'approved_at',
            'status',
            'type',
            'is_exceptional',
        ]);
    }

    /**
     * Reversed dates get a message that says what to change, on the end-date
     * field (the rule itself is the existing after_or_equal:start_at).
     *
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'end_at.after_or_equal' => __('leaves.validation.end-before-start'),
        ];
    }

    /**
     * A request decided since the dialog opened (by someone else, or by this
     * same decision sent twice) is reported as already decided, not as a 403
     * (G-B-1). Only to someone who may still see it: anyone else gets the
     * policy's refusal, which says nothing about the request.
     */
    private function assertStillPending(LeaveRequest $leaveRequest): void
    {
        if ((int) $leaveRequest->status !== LeaveRequestStatus::Pending && Gate::allows('view', $leaveRequest)) {
            throw new LeaveDecisionAlreadyMade($leaveRequest);
        }
    }

    private function storeOnce(?string $key): void
    {
        if ($key !== null && ($existing = $this->consumedSubmission($key))) {
            $this->leaveRequest = $existing;
            $this->replayed = true;

            return;
        }

        $this->validate();
        $leaveBalance = $this->selectedBalance();
        $window = $this->requireWindow();

        if ($this->type === 'hour') {
            $start_at = $this->start_hour.' '.$this->leave_date;
            $this->start_at = Carbon::createFromFormat('H:i Y-m-d', $start_at);

            $end_at = $this->end_hour.' '.$this->leave_date;
            $this->end_at = Carbon::createFromFormat('H:i Y-m-d', $end_at);
        }

        $employeeId = (int) $leaveBalance->contract->employee_id;

        $this->leaveRequest = DB::transaction(function () use ($leaveBalance, $employeeId, $window): LeaveRequest {
            $this->lockRequesterRows($employeeId, $leaveBalance->id);
            $leaveBalance->refresh();

            $this->assertNoOwnOverlap($employeeId);

            // Authoritative price, under the balance lock: what the preview
            // showed may be stale (another request decided or sent in the
            // meantime), and a stale shortfall is reported on the same field.
            $quote = $this->quoteFor($leaveBalance, $window);
            $this->assertQuoteAllows($quote, $leaveBalance, $window);

            // status / approved_* are never taken from the form: Livewire public
            // properties are client-writable, and decisions only happen through
            // submitDecision(). A new request is always pending. The snapshot
            // (computed_hours, rule_version) is the server's quote, never input.
            return LeaveRequest::create([
                'leave_balance_id' => $this->leave_balance_id,
                'type' => $this->type,
                'start_at' => $this->start_at ?? $this->combineDateTime($this->leave_date, $this->start_hour),
                'end_at' => $this->end_at ?? $this->combineDateTime($this->leave_date, $this->end_hour),
                'reason' => $this->reason,
                'alternative_employee_id' => $this->alternative_employee,
                'approved_by' => null,
                'approved_at' => null,
                'reject_reason' => null,
                'status' => LeaveRequestStatus::Pending,
            ] + $this->snapshot($quote, $window));
        });

        if ($key !== null) {
            Cache::put($key, $this->leaveRequest->id, now()->addDay());
        }

        $this->resetForm();
    }

    /**
     * The request a consumed token created, if it still belongs to the same
     * requester. A token is never a way to read someone else's request.
     */
    private function consumedSubmission(string $key): ?LeaveRequest
    {
        $id = Cache::get($key);

        if (! $id) {
            return null;
        }

        $employeeId = $this->targetEmployee()?->id;

        return LeaveRequest::query()
            ->whereKey($id)
            ->whereHas('leaveBalance.contract', fn ($query) => $query->where('employee_id', $employeeId))
            ->first();
    }

    /**
     * Pending: re-priced and re-validated with the request itself left out of
     * pending, snapshot refreshed.
     *
     * Accepted (HR or a deciding manager; the owner may edit only while
     * pending): one transaction under the balance lock. When the period,
     * unit, balance or December flag changed, `used` moves by new − old,
     * where old is the stored snapshot (or the legacy rule for a request from
     * before it) and new is the fresh quote, re-validated against the
     * committed balance. A content-only edit (reason, alternative) prices
     * nothing, so a historical deduction is never rewritten by a typo fix.
     */
    private function updateOnce(): void
    {
        $this->validate();
        $balance = $this->selectedBalance();
        $window = $this->requireWindow();

        if ($this->type === 'hour') {
            $start_at = $this->start_hour.' '.$this->leave_date;
            $this->start_at = Carbon::createFromFormat('H:i Y-m-d', $start_at);

            $end_at = $this->end_hour.' '.$this->leave_date;
            $this->end_at = Carbon::createFromFormat('H:i Y-m-d', $end_at);
        } else {
            $this->start_at = Carbon::parse($this->start_at)->startOfDay();
            $this->end_at = Carbon::parse($this->end_at)->startOfDay();
        }

        DB::transaction(function () use ($balance, $window): void {
            // Lock order: request, then employee, then balance(s). The
            // decision path locks request then balance, and store() employee
            // then balance, so no two paths wait on each other in reverse.
            $original = LeaveRequest::query()
                ->with('leaveBalance.contract')
                ->lockForUpdate()
                ->findOrFail($this->leaveRequest->id);

            // Stale state: a decision may have landed since the form opened
            // (an owner may edit only while the request is pending).
            Gate::authorize('update', $original);

            $originalBalanceId = (int) $original->leave_balance_id;
            $employeeId = (int) $balance->contract->employee_id;
            $this->lockRequesterRows($employeeId, $balance->id, $originalBalanceId);
            $balance->refresh();

            // Recheck at write time, ignoring the request being edited.
            $this->assertNoOwnOverlap($employeeId, $original->id);

            $accepted = (int) $original->status === LeaveRequestStatus::Accepted;

            if ($accepted && ! $this->windowDiffersFrom($original, $balance, $window)) {
                $original->update($this->requestData());

                return;
            }

            $quote = $this->quoteFor($balance, $window, $original);

            if (! $accepted) {
                $this->assertQuoteAllows($quote, $balance, $window);
                $original->update($this->requestData() + $this->snapshot($quote, $window));

                return;
            }

            $old = $this->deductionHours($original);
            $sameBalance = $originalBalanceId === (int) $balance->id;

            $this->assertQuoteAllows(
                $quote,
                $balance,
                $window,
                releasedHours: $sameBalance ? $old : 0.0,
                committedOnly: true,
            );

            $original->update($this->requestData() + $this->snapshot($quote, $window));

            if ($sameBalance) {
                $this->adjustUsed($balance, $quote->requestedHours - $old);
            } else {
                $this->adjustUsed(LeaveBalance::query()->findOrFail($originalBalanceId), -$old);
                $this->adjustUsed($balance, $quote->requestedHours);
            }
        });

        $this->resetForm();
    }

    /**
     * A failed save must not lose what was typed. store()/update() rewrite
     * start_at/end_at into timestamps on the way to the insert; if anything
     * after that fails, the typed values come back. Errors raised outside
     * validate() (withMessages) are also given the form's prefix so they show
     * next to their field instead of only in the summary.
     */
    private function keepingInputOnFailure(callable $write): void
    {
        $input = [];
        foreach (self::INPUT_FIELDS as $field) {
            $input[$field] = $this->{$field};
        }

        try {
            $write();
        } catch (Throwable $e) {
            foreach ($input as $field => $value) {
                $this->{$field} = $value;
            }

            throw $e instanceof ValidationException ? $this->linkErrorsToFields($e) : $e;
        }
    }

    private function linkErrorsToFields(ValidationException $e): ValidationException
    {
        $prefix = $this->getPropertyName().'.';
        $errors = $e->errors();

        if (collect(array_keys($errors))->every(fn (string $key) => str_starts_with($key, $prefix))) {
            return $e;
        }

        $linked = [];
        foreach ($errors as $key => $messages) {
            $linked[str_starts_with($key, $prefix) ? $key : $prefix.$key] = $messages;
        }

        return ValidationException::withMessages($linked);
    }

    /**
     * Serialize writes for one requester: every store/update for the same
     * employee waits here, so the overlap check below sees committed rows.
     * Lock order is always employee, then balance.
     */
    private function lockRequesterRows(int $employeeId, int ...$balanceIds): void
    {
        DB::table('employees')->where('id', $employeeId)->lockForUpdate()->first();

        $balanceIds = array_values(array_unique($balanceIds));
        sort($balanceIds);

        foreach ($balanceIds as $balanceId) {
            LeaveBalance::query()->whereKey($balanceId)->lockForUpdate()->first();
        }
    }

    /**
     * The employee may not hold two Pending/Accepted requests that overlap.
     * Day requests cover whole days; hour requests their exact times, so
     * 09:00-11:00 and 11:00-13:00 on the same day do not clash.
     */
    private function assertNoOwnOverlap(int $employeeId, ?int $ignoreId = null): void
    {
        $period = $this->requestedPeriod();

        if ($period === null) {
            return;
        }

        [$from, $to] = $period;

        $conflict = LeaveRequest::query()
            ->whereHas('leaveBalance.contract', fn ($query) => $query->where('employee_id', $employeeId))
            ->whereIn('status', [LeaveRequestStatus::Pending, LeaveRequestStatus::Accepted])
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->whereDate('start_at', '<=', $to->toDateString())
            ->whereDate('end_at', '>=', $from->toDateString())
            ->orderBy('start_at')
            ->get()
            ->first(function (LeaveRequest $existing) use ($from, $to): bool {
                [$existingFrom, $existingTo] = self::coveredPeriod($existing->type, $existing->start_at, $existing->end_at);

                return $existingFrom->lt($to) && $existingTo->gt($from);
            });

        if (! $conflict) {
            return;
        }

        throw ValidationException::withMessages([
            ($this->type === 'hour' ? 'leave_date' : 'start_at') => __('leaves.validation.overlap', [
                'status' => LeaveRequestStatus::getLocalizedName((int) $conflict->status)['value'],
                'period' => $this->describePeriod($conflict),
            ]),
        ]);
    }

    /**
     * The period the form asks for, read from the typed values without
     * changing them. Null when incomplete (validation reports that).
     *
     * @return array{0: Carbon, 1: Carbon}|null
     */
    private function requestedPeriod(): ?array
    {
        try {
            if ($this->type === 'hour') {
                $start = $this->combineDateTime($this->leave_date, $this->start_hour);
                $end = $this->combineDateTime($this->leave_date, $this->end_hour);
            } else {
                $start = filled($this->start_at) ? Carbon::parse($this->start_at) : null;
                $end = filled($this->end_at) ? Carbon::parse($this->end_at) : null;
            }
        } catch (Throwable) {
            return null;
        }

        if (! $start || ! $end || $end->lessThan($start)) {
            return null;
        }

        return self::coveredPeriod($this->type, $start, $end);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private static function coveredPeriod(?string $type, mixed $start, mixed $end): array
    {
        $start = Carbon::parse($start);
        $end = Carbon::parse($end);

        return $type === 'hour'
            ? [$start, $end]
            : [$start->copy()->startOfDay(), $end->copy()->endOfDay()];
    }

    /**
     * "25-10-2026 → 26-10-2026" or "25-10-2026, 09:00 → 11:00", each value
     * isolated so it reads correctly inside an Arabic sentence.
     */
    private function describePeriod(LeaveRequest $leaveRequest): string
    {
        $isolate = fn (string $value): string => "\u{2068}{$value}\u{2069}";
        $separator = ' '.__('leaves.range-separator').' ';

        if ($leaveRequest->type === 'hour') {
            return $isolate($leaveRequest->start_at->format('d-m-Y, H:i'))
                .$separator.$isolate($leaveRequest->end_at->format('H:i'));
        }

        $start = $leaveRequest->start_at->format('d-m-Y');
        $end = $leaveRequest->end_at->format('d-m-Y');

        return $start === $end
            ? $isolate($start)
            : $isolate($start).$separator.$isolate($end);
    }

    /**
     * The typed window after validate() passed. A window that still cannot
     * be read (should not happen past the rules) is a field error, not a 500.
     */
    private function requireWindow(): LeaveWindow
    {
        $window = $this->window();

        if ($window === null) {
            throw ValidationException::withMessages([
                ($this->type === 'hour' ? 'end_hour' : 'end_at') => __(LeaveQuote::messageKey(LeaveQuote::INVALID_WINDOW)),
            ]);
        }

        return $window;
    }

    /**
     * Raise the quote's blocking errors as field-linked validation errors,
     * each with its leave_quote.codes.* message. Warnings never block.
     *
     * The balance shortfall is re-derived here so one rule serves every path:
     *  - a new or pending request: the quote's own projection (pending
     *    requests reserve, §3.4) — identical to quote()->errors;
     *  - an accepted request being edited ($committedOnly): checked against
     *    the committed balance only, with its own previous deduction
     *    ($releasedHours) given back first.
     * Annual only blocks (D-6); other types merely warn.
     */
    private function assertQuoteAllows(
        LeaveQuote $quote,
        LeaveBalance $balance,
        LeaveWindow $window,
        float $releasedHours = 0.0,
        bool $committedOnly = false,
    ): void {
        $errors = [];

        foreach ($quote->errors as $code) {
            if ($code !== LeaveQuote::EXCEEDS_BALANCE) {
                $errors[$this->fieldForCode($code, $window)][] = __(LeaveQuote::messageKey($code));
            }
        }

        $projected = $quote->projectedHours + $releasedHours + ($committedOnly ? $quote->pendingHours : 0.0);

        if ($balance->isAnnual() && $quote->requestedHours > 0 && $projected < -self::EPSILON) {
            $errors['leave_balance_id'][] = __(LeaveQuote::messageKey(LeaveQuote::EXCEEDS_BALANCE));
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(array_map('array_unique', $errors));
        }
    }

    /**
     * The field an error code belongs to, so it shows next to what to change.
     */
    private function fieldForCode(string $code, LeaveWindow $window): string
    {
        $hourly = $window->isHourly();

        return match ($code) {
            LeaveQuote::EXCEEDS_BALANCE,
            LeaveQuote::SCHEDULE_UNKNOWN,
            LeaveQuote::NO_LEAVE_ENTITLEMENT => 'leave_balance_id',
            LeaveQuote::BELOW_MINIMUM,
            LeaveQuote::OUTSIDE_WORKING_HOURS => 'end_hour',
            LeaveQuote::INVALID_WINDOW => $hourly ? 'end_hour' : 'end_at',
            default => $hourly ? 'leave_date' : 'start_at',
        };
    }

    /**
     * D-5 snapshot plus the D-7 flag, from the server's own quote.
     *
     * @return array{computed_hours: float, rule_version: string, is_exceptional: bool}
     */
    private function snapshot(LeaveQuote $quote, LeaveWindow $window): array
    {
        return [
            'computed_hours' => $quote->requestedHours,
            'rule_version' => $quote->ruleVersion,
            'is_exceptional' => $window->exceptional,
        ];
    }

    /**
     * What accepting $leaveRequest deducts (or once deducted): its stored
     * snapshot, else the legacy rule that priced requests before it.
     */
    private function deductionHours(LeaveRequest $leaveRequest): float
    {
        return $leaveRequest->deductionHours();
    }

    /**
     * Decision-time recheck: accepting must not take an annual balance below
     * zero. Checked against the committed balance (eligible to date − used);
     * other pending requests are not commitments of this decision and are
     * rechecked when they are decided themselves.
     */
    private function assertDecisionFitsBalance(LeaveBalance $balance, float $hours): void
    {
        if ($hours <= 0 || ! $balance->isAnnual()) {
            return;
        }

        $available = round((float) $balance->eligibleAnnualLeaveHours() - (float) ($balance->used ?? 0), 2);

        if ($available - $hours < -self::EPSILON) {
            throw ValidationException::withMessages([
                'status' => __('leave_quote.decision.exceeds_balance', [
                    'available' => $this->formatLeaveHours(max(0, $available)),
                    'requested' => $this->formatLeaveHours($hours),
                ]),
            ]);
        }
    }

    private function adjustUsed(LeaveBalance $balance, float $delta): void
    {
        $delta = round($delta, 2);

        if ($delta > 0) {
            $balance->increment('used', $delta);
        } elseif ($delta < 0) {
            $balance->decrement('used', -$delta);
        }
    }

    /**
     * Would saving re-price $original? True when the balance, unit, period
     * or December flag differs from what is stored.
     */
    private function windowDiffersFrom(LeaveRequest $original, LeaveBalance $balance, LeaveWindow $window): bool
    {
        return (int) $original->leave_balance_id !== (int) $balance->id
            || $original->type !== $window->type
            || $original->start_at?->format('Y-m-d H:i') !== $window->startsAt()->format('Y-m-d H:i')
            || $original->end_at?->format('Y-m-d H:i') !== $window->endsAt()->format('Y-m-d H:i')
            || (bool) $original->is_exceptional !== $window->exceptional;
    }

    /**
     * A day range longer than MAX_SPAN_DAYS. Pricing walks the range day by
     * day, so an absurd end date is refused before it is priced.
     */
    private function spanTooLong(): bool
    {
        if ($this->type !== 'day' || ! self::isDate($this->start_at) || ! self::isDate($this->end_at)) {
            return false;
        }

        try {
            return Carbon::parse($this->start_at)->startOfDay()
                ->diffInDays(Carbon::parse($this->end_at)->startOfDay(), false) > self::MAX_SPAN_DAYS;
        } catch (Throwable) {
            return false;
        }
    }

    private static function isDate(mixed $value): bool
    {
        if ($value instanceof DateTimeInterface) {
            return true;
        }

        if (! is_string($value) || trim($value) === '') {
            return false;
        }

        try {
            Carbon::parse($value);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private static function isTime(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^([01]?\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', trim($value)) === 1;
    }

    private function targetEmployee(): ?Employee
    {
        $user = auth()->user();
        $role = $user?->myRole();
        $canSelectEmployee = $role === 'super-admin'
            || ($role && str_starts_with($role, 'HR-'));

        if ($canSelectEmployee && $this->employee_id) {
            // employee_id is client-writable: keep it inside the HR role's
            // cities, like the employee dropdown (scopedEmployees()).
            $cityIds = $user->activeCityIds();

            return Employee::query()
                ->when($cityIds !== null, fn ($query) => $query->whereIn('city_id', $cityIds))
                ->find($this->employee_id);
        }

        // An edit by anyone else (the owner, or a manager the policy let in)
        // is about the request's own employee, never the editor's profile:
        // a manager's edit was validated against the manager's own contract
        // and always refused (G-B-6).
        if ($this->leaveRequest) {
            return $this->leaveRequest->leaveBalance?->contract?->employee;
        }

        return $user?->profile;
    }

    /**
     * Only request-editable fields may be updated. Decision metadata is kept
     * authoritative and cannot be changed through forged form state.
     *
     * @return array<string, mixed>
     */
    private function requestData(): array
    {
        return [
            'leave_balance_id' => $this->leave_balance_id,
            'type' => $this->type,
            'start_at' => $this->start_at,
            'end_at' => $this->end_at,
            'reason' => $this->reason,
            'alternative_employee_id' => $this->alternative_employee,
        ];
    }
}
