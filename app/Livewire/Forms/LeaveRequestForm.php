<?php

namespace App\Livewire\Forms;

use App\Enums\LeaveRequestStatus;
use App\Enums\LeaveTypeCases;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Services\LeaveAlternativeEmployees;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Validate;
use Livewire\Form;
use LogicException;

class LeaveRequestForm extends Form
{
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
     * Dynamic validation rules depending on leave type
     */
    public function rules()
    {
        $employee = $this->targetEmployee();
        $activeContract = $employee?->latestActiveContract();
        $maxHours = (($activeContract?->weekly_hours ?? 0) / 5) / 2;

        $rules = [
            'type' => ['required', Rule::in(['day', 'hour'])],
            'reason' => ['required', 'string', 'min:3'],
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

        // Fetch center open and close times
        $center = $activeContract?->position?->center;
        $centerOpenTime = Carbon::parse($center->open_at ?? '09:00');
        $centerCloseTime = Carbon::parse($center->close_at ?? '16:30');

        if ($this->type === 'hour') {
            $rules['start_hour'] = ['required', 'date_format:H:i', function ($attribute, $value, $fail) use ($centerOpenTime) {
                $start = Carbon::createFromFormat('H:i', $value);

                // Check if start hour is within center's working hours
                if ($start->lessThan($centerOpenTime)) {
                    $fail(__('Start hour must be within the center\'s working hours.'));
                }
            }];

            $rules['end_hour'] = [
                'required',
                'date_format:H:i',
                function ($attribute, $value, $fail) use ($centerCloseTime, $maxHours) {
                    if (! $this->start_hour || ! $this->end_hour) {
                        return;
                    }

                    $start = Carbon::createFromFormat('H:i', $this->start_hour);
                    $end = Carbon::createFromFormat('H:i', $value);

                    // Check if end hour is within center's working hours
                    if ($end->greaterThan($centerCloseTime)) {
                        $fail(__('End hour must be within the center\'s working hours.'));

                        return;
                    }

                    // Check that end hour is after start hour
                    if ($end->lessThanOrEqualTo($start)) {
                        $fail(__('End hour must be after start hour.'));

                        return;
                    }

                    $duration = $start->DiffInHours($end);

                    if ($duration > $maxHours) {
                        $fail(__('Duration must not exceed :max hours.', ['max' => $maxHours]));
                    }
                },
            ];

            $rules['leave_date'] = ['required'];
        } else {
            $rules['start_at'] = ['required', 'after_or_equal:'.today()->format('d-m-Y')];
            $rules['end_at'] = ['required', 'after_or_equal:start_at'];
        }

        return $rules;
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
            Gate::authorize('decide', $leaveRequest);

            if ((int) $this->status === LeaveRequestStatus::Accepted) {
                $leaveRequest->update([
                    'status' => LeaveRequestStatus::Accepted,
                    'reject_reason' => null,
                    'approved_at' => now(),
                    'approved_by' => auth()->user()->profile->id,
                ]);
                $leaveRequest->leaveBalance()->increment(
                    'used',
                    $leaveRequest->duration_in_hours,
                );
            } else {
                $leaveRequest->update([
                    'status' => LeaveRequestStatus::Rejected,
                    'reject_reason' => $this->reject_reason,
                    'approved_at' => now(),
                    'approved_by' => auth()->user()->profile->id,
                ]);
            }

            return $this->decisionLeave = $leaveRequest->refresh();
        });
    }

    /**
     * Store new leave request
     */
    public function store(): void
    {

        $this->validate();

        if ($this->type === 'hour') {
            $start_at = $this->start_hour.' '.$this->leave_date;
            $this->start_at = Carbon::createFromFormat('H:i Y-m-d', $start_at);

            $end_at = $this->end_hour.' '.$this->leave_date;
            $this->end_at = Carbon::createFromFormat('H:i Y-m-d', $end_at);
        }

        $this->validateMaxDuration();
        $leaveBalance = $this->selectedBalance();

        // --- Handle Annual Leave Accrual ---
        if ($leaveBalance->isAnnual()) {
            $dailyWorkHour = $leaveBalance->contract->work_hour_per_day;
            $requestedHours = $this->calculateRequestedHours($dailyWorkHour);

            if (! $leaveBalance->canRequestAnnualLeave($requestedHours)) {
                throw ValidationException::withMessages([
                    'leave_balance_id' => __('validation.annual-leave-max-date'),
                ]);
            }
        }

        // status / approved_* are never taken from the form: Livewire public
        // properties are client-writable, and decisions only happen through
        // submitDecision(). A new request is always pending.
        $this->leaveRequest = LeaveRequest::create([
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
        ]);

        $this->resetForm();
    }

    /**
     * Update an existing leave request
     */
    public function update(): void
    {

        $this->validate();
        $leaveBalance = $this->leaveRequest->leaveBalance;

        if ($leaveBalance->isAnnual()) {
            $dailyWorkHour = $leaveBalance->contract->work_hour_per_day;
            $requestedHours = $this->calculateRequestedHours($dailyWorkHour);

            if (! $leaveBalance->canRequestAnnualLeave($requestedHours)) {
                throw ValidationException::withMessages([
                    'leave_balance_id' => __('validation.annual-leave-max-date'),
                ]);
            }
        }
        if ($this->type === 'hour') {

            $start_at = $this->start_hour.' '.$this->leave_date;
            $this->start_at = Carbon::createFromFormat('H:i Y-m-d', $start_at);

            $end_at = $this->end_hour.' '.$this->leave_date;
            $this->end_at = Carbon::createFromFormat('H:i Y-m-d', $end_at);
        } else {

            $this->start_at = Carbon::parse($this->start_at)->startOfDay();
            $this->end_at = Carbon::parse($this->end_at)->startOfDay();
        }
        $this->validateMaxDuration();
        if ((int) $this->leaveRequest->status === LeaveRequestStatus::Accepted) {
            if (! (($this->leaveRequest->start_at->isSameAs('Y-m-d H:i', $this->start_at)) && ($this->leaveRequest->end_at->isSameAs('Y-m-d H:i', $this->end_at)))) {
                $this->leaveRequest->leaveBalance->update([
                    'used' => $this->leaveRequest->leaveBalance->used - $this->leaveRequest->duration_in_hours,
                ]);
                $this->leaveRequest->update($this->requestData());
                $this->leaveRequest->leaveBalance->update([
                    'used' => $this->leaveRequest->leaveBalance->used + $this->leaveRequest->duration_in_hours,
                ]);
            } else {
                $this->leaveRequest->update($this->requestData());
            }

        } else {
            $this->leaveRequest->update($this->requestData());
        }

        $this->resetForm();
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

    /**
     * Calculate requested hours depending on leave type
     */
    protected function calculateRequestedHours($dailyWorkHour = 8): float
    {
        if ($this->type === 'hour') {
            $start = $this->combineDateTime($this->leave_date, $this->start_hour);
            $end = $this->combineDateTime($this->leave_date, $this->end_hour);

            return $start->floatDiffInHours($end);
        }

        if ($this->type === 'day') {
            $start = Carbon::parse($this->start_at)->startOfDay();
            $end = Carbon::parse($this->end_at)->endOfDay();
            $days = $start->diffInDaysFiltered(fn (Carbon $date) => ! $date->isWeekend(), $end) + 1;

            return $days * $dailyWorkHour;
        }

        return 0;
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
        ]);
    }

    protected function validateMaxDuration()
    {
        $leaveBalance = $this->selectedBalance();
        $leaveType = $leaveBalance->leaveType;

        if ($leaveBalance->isAnnual()) {
            return;
        }

        $baseDays = $leaveType->base_days;

        $start = Carbon::parse($this->start_at)->startOfDay();
        $end = Carbon::parse($this->end_at)->startOfDay();

        if ($end->lessThan($start)) {
            return;
        }

        // Calendar Days for maternity
        $maternityTypes = [
            LeaveTypeCases::MaternityFirst,
            LeaveTypeCases::MaternitySecond,
        ];

        if (in_array($leaveType->id, $maternityTypes)) {
            $days = $start->diffInDays($end) + 1;

            if ($days > $baseDays) {
                throw ValidationException::withMessages([
                    'end_at' => __('validation.leave-max-date', [
                        'days' => $days,
                        'max' => $baseDays,
                    ]),
                ]);
            }

            return;
        }

        // other leaves get the weekend from the center

        $weekends = explode(',', $leaveBalance->contract->position->center->weekends ?? '0,6'); // 0 and 6 is for Saturday and Sunday
        $weekends = array_map('intval', $weekends);

        $days = $start->diffInDaysFiltered(function (Carbon $date) use ($weekends) {
            return ! in_array($date->dayOfWeek, $weekends);
        }, $end) + 1;

        if ($days > $baseDays) {
            throw ValidationException::withMessages([
                'end_at' => __('validation.leave-max-date', [
                    'days' => $days,
                    'max' => $baseDays,
                ]),
            ]);
        }
    }

    private function targetEmployee(): ?Employee
    {
        $user = auth()->user();
        $role = $user?->myRole();
        $canSelectEmployee = $role === 'super-admin'
            || ($role && str_starts_with($role, 'HR-'));

        if ($canSelectEmployee && $this->employee_id) {
            return Employee::query()->find($this->employee_id);
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
