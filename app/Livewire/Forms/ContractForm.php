<?php

namespace App\Livewire\Forms;

use App\Enums\ContractPlace;
use App\Enums\ContractType as ContractTypeEnum;
use App\Enums\PayMode;
use App\Enums\SalaryPer;
use App\Enums\ScheduleMode;
use App\Enums\SessionDuration;
use App\Models\Contract;
use App\Models\ContractPayTier;
use App\Models\ContractType;
use App\Models\Employee;
use App\Models\Position;
use Carbon\Carbon;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Form;

class ContractForm extends Form
{
    // ── Model reference
    public ?Contract $contract = null;

    /**
     * Set when this form is drafting the successor of an existing contract.
     *
     * Locked: this marker is what waives the position capacity check, so a
     * client able to set it could point an ordinary create at any contract on a
     * full position and overflow max_emp. Only the server may assign it.
     */
    #[Locked]
    public ?int $renewal_source_id = null;

    // ── Identity
    public ?int $employee_id = null;

    public ?int $position_id = null;

    public ?int $contract_type_id = null;

    public int $type = 1;

    public int $place = 1;

    public bool $is_active = true;

    public ?bool $auto_renew = null;

    // ── Terms
    public ?string $start_date = null;

    public ?string $end_date = null;

    public ?string $terminated_at = null;

    public ?string $reason_of_termination = null;

    public ?int $termination_reason_id = null;

    public int $weekly_hours = 40;

    // ── Schedule
    public mixed $work_days_ar = null;

    public mixed $work_days_en = null;

    // 1 = fixed, 2 = flexible — see App\Enums\ScheduleMode.
    public int $schedule_mode = 1;

    // -- Fixed mode
    public ?string $work_hours_from = '09:00';

    public ?string $work_hours_to = '17:00';

    /** Optional second daily shift, e.g. 9:00-13:00 and 17:00-21:00. Both null, or both set. */
    public ?string $work_hours_2_from = null;

    public ?string $work_hours_2_to = null;

    public ?int $work_day_from = null;

    public ?int $work_day_to = null;

    /** Whether the printed contract states the computed "N days/week" / "M hours/week" / "M hours/day" / day range — fixed mode only, never edited directly. */
    public bool $show_days_per_week = false;

    public bool $show_hours_per_week = false;

    public bool $show_hours_per_day = false;

    public bool $show_day_range = false;

    // -- Flexible mode
    public ?int $flex_days_per_week = null;

    public ?int $flex_hours_per_week = null;

    public ?int $flex_hours_per_month = null;

    public bool $flex_varies_by_requirements = false;

    public bool $flex_coordinated_with_manager = false;

    public ?string $flex_custom_en = null;

    public ?string $flex_custom_ar = null;

    // ── Pay
    // 1 = fixed, 2 = flexible — see App\Enums\PayMode.
    public int $pay_mode = 1;

    public int $currency = 1;

    // -- Fixed mode
    public mixed $salary = null;

    public int $salary_per = 1;

    /** Only meaningful when salary_per is Session — see App\Enums\SessionDuration. */
    public ?int $session_duration = null;

    /**
     * -- Flexible mode: an unbounded "Add condition" repeater instead of one
     * flat figure, e.g. "if teaching 60h/month" => 200, "70h/month" => 300.
     * Each row: ['condition_en' => string, 'condition_ar' => string, 'amount' => numeric].
     *
     * @var array<int, array{condition_en: string, condition_ar: string, amount: mixed}>
     */
    public array $pay_tiers = [];

    // ────────────────────────────────────────────────
    // Lifecycle hooks
    // ────────────────────────────────────────────────

    /**
     * Recompute the term when the contract type changes.
     *
     * $anchor is the date the term must start from. A renewal passes the date
     * its predecessor hands over on; without it the term would be reset to the
     * start of next month, silently back-dating the renewal to before the
     * contract it replaces has even ended. A fresh contract passes nothing and
     * keeps the original "starts next month" default.
     */
    public function updatedContractTypeId(mixed $value, ?string $anchor = null): void
    {
        if (! $value) {
            return;
        }

        $start = Carbon::parse($anchor ?? Carbon::now()->addMonthNoOverflow()->startOfMonth());

        $this->start_date = $start->toDateString();

        // Contract type 3 is the trial contract (3-month duration)
        if ((int) $value === 3) {
            $this->end_date = $start->copy()->addMonthsNoOverflow(3)->toDateString();

            return;
        }

        // A fresh contract runs to the end of the current year. A renewal has
        // no such anchor to fall back on — the current year may already be
        // behind it — so it runs a full year from its own start instead, which
        // is also the longest term validateTerms() accepts.
        $this->end_date = $anchor
            ? $start->copy()->addYear()->subDay()->toDateString()
            : Carbon::now()->endOfYear()->toDateString();
    }

    // ────────────────────────────────────────────────
    // Initialisation
    // ────────────────────────────────────────────────

    public function setDefaults(): void
    {

        $this->is_active = true;
        $this->type = 1;
        $this->place = 1;
        $this->weekly_hours = 40;
        $this->currency = 1; // SYP
        $this->salary_per = SalaryPer::Month;
        $this->terminated_at = null;
        $this->termination_reason_id = null;
        $this->reason_of_termination = null;
        $this->auto_renew = null;

        $this->schedule_mode = ScheduleMode::Fixed;
        $this->work_hours_2_from = null;
        $this->work_hours_2_to = null;
        $this->work_day_from = null;
        $this->work_day_to = null;
        // A brand-new contract starts with the full standard clause stated —
        // "40 working hours per week, 8 hours per day, 5 days per week,
        // Monday to Friday" — since that is the common case; HR unchecks
        // whichever piece this particular contract should omit.
        $this->show_days_per_week = true;
        $this->show_hours_per_week = true;
        $this->show_hours_per_day = true;
        $this->show_day_range = true;
        $this->flex_days_per_week = null;
        $this->flex_hours_per_week = null;
        $this->flex_hours_per_month = null;
        $this->flex_varies_by_requirements = false;
        $this->flex_coordinated_with_manager = false;
        $this->flex_custom_en = null;
        $this->flex_custom_ar = null;

        $this->pay_mode = PayMode::Fixed;
        $this->session_duration = null;
        $this->pay_tiers = [];
    }

    public function setContract(Contract $contract): void
    {
        $this->contract = $contract;
        $this->fillFromModel($contract);
    }

    /**
     * Draft the successor of $source: every term carried over, only the dates
     * replaced.
     *
     * Nulling `contract` is what makes the shared save()/create() path insert a
     * new row instead of updating the source, and clearing the termination trio
     * stops a contract that was terminated and later reactivated from passing a
     * stale termination on to its successor.
     */
    public function fillFromRenewal(Contract $source, string $start, string $end): void
    {
        $this->fillFromModel($source);

        $this->contract = null;
        $this->renewal_source_id = $source->id;

        $this->is_active = true;
        $this->terminated_at = null;
        $this->termination_reason_id = null;
        $this->reason_of_termination = null;

        $this->start_date = $start;
        $this->end_date = $end;
    }

    public function fillFromModel(Contract $contract): void
    {
        $this->employee_id = $contract->employee_id;
        $this->position_id = $contract->position_id;
        $this->contract_type_id = $contract->contract_type_id;
        $this->type = (int) $contract->type;
        $this->place = (int) $contract->place;
        $this->is_active = (bool) $contract->is_active;
        $this->auto_renew = (bool) $contract->auto_renew;

        $this->start_date = $contract->start_date?->toDateString();
        $this->end_date = $contract->end_date?->toDateString();
        $this->terminated_at = $contract->terminated_at?->toDateString();
        $this->termination_reason_id = $contract->termination_reason_id;
        $this->reason_of_termination = $contract->reason_of_termination;
        $this->weekly_hours = (int) $contract->weekly_hours;

        $this->pay_mode = (int) ($contract->pay_mode ?? PayMode::Fixed);
        $this->currency = (int) $contract->currency;
        // The figures themselves are withheld from a role that may not see
        // them; the mode, currency and period stay so the screen can still
        // explain what kind of pay clause this contract has. The withheld
        // values are never sent to the browser, and preserveHiddenPay() below
        // is what stops the resulting blanks being saved back over them.
        $payHidden = $this->payIsHidden();

        $this->salary = $payHidden ? null : $contract->salary;
        $this->salary_per = $contract->salary_per;
        $this->session_duration = $contract->session_duration;
        $this->pay_tiers = $contract->payTiers
            ->map(fn (ContractPayTier $tier): array => [
                'condition_en' => $tier->condition_en,
                'condition_ar' => $tier->condition_ar,
                'amount' => $payHidden ? null : $tier->amount,
            ])
            ->all();
        $this->work_days_ar = $contract->work_days_ar;
        $this->work_days_en = $contract->work_days_en;
        $this->work_hours_from = $this->timeForInput($contract->work_hours_from, $this->work_hours_from);
        $this->work_hours_to = $this->timeForInput($contract->work_hours_to, $this->work_hours_to);

        $this->schedule_mode = (int) ($contract->schedule_mode ?? ScheduleMode::Fixed);
        $this->work_hours_2_from = $contract->work_hours_2_from;
        $this->work_hours_2_to = $contract->work_hours_2_to;
        $this->work_day_from = $contract->work_day_from;
        $this->work_day_to = $contract->work_day_to;
        $this->show_days_per_week = (bool) $contract->show_days_per_week;
        $this->show_hours_per_week = (bool) $contract->show_hours_per_week;
        $this->show_hours_per_day = (bool) $contract->show_hours_per_day;
        $this->show_day_range = (bool) $contract->show_day_range;
        $this->flex_days_per_week = $contract->flex_days_per_week;
        $this->flex_hours_per_week = $contract->flex_hours_per_week;
        $this->flex_hours_per_month = $contract->flex_hours_per_month;
        $this->flex_varies_by_requirements = (bool) $contract->flex_varies_by_requirements;
        $this->flex_coordinated_with_manager = (bool) $contract->flex_coordinated_with_manager;
        $this->flex_custom_en = $contract->flex_custom_en;
        $this->flex_custom_ar = $contract->flex_custom_ar;
    }

    // ────────────────────────────────────────────────
    // Validation
    // ────────────────────────────────────────────────

    /**
     * Human field names for every message below, in the viewer's locale —
     * without them Laravel falls back to the raw key ("employee id").
     */
    public function validationAttributes(): array
    {
        return __('contracts.attributes');
    }

    public function validateIdentity(): array
    {
        return $this->validate([
            // An incomplete record cannot be given a contract (B5, AC-B5-02);
            // only a Draft-type reservation, which is not made here.
            'employee_id' => ['required', 'exists:employees,id', $this->employeeIsCompleteRule()],
            'position_id' => ['required', 'exists:positions,id', $this->positionCapacityRule()],
            'contract_type_id' => ['required', 'exists:contract_types,id'],
            'type' => ['required', Rule::in([ContractTypeEnum::FullTime, ContractTypeEnum::PartTime, ContractTypeEnum::Freelance]), $this->typeMatchesCategoryRule()],
            'place' => ['required', Rule::in([ContractPlace::Onsite, ContractPlace::Online])],
            'is_active' => ['required', 'boolean'],
        ]);
    }

    public function validateTerms(): array
    {
        $maxEndDate = $this->start_date
            ? Carbon::parse($this->start_date)->addYear()->toDateString()
            : null;

        return $this->validate([
            'start_date' => ['required', 'date'],
            'end_date' => array_filter([
                'required',
                'date',
                'after_or_equal:start_date',
                $maxEndDate ? "before_or_equal:{$maxEndDate}" : null,
            ]),
            'terminated_at' => ['nullable', 'date', 'after_or_equal:start_date'],
            'termination_reason_id' => ['nullable', 'exists:termination_reasons,id'],
            'reason_of_termination' => ['nullable', 'string'],
            // Computed automatically in fixed mode (day range × daily hours) and
            // taken from the "hours/week" flex box when set; a service contract
            // is the only category allowed to leave it at 0 — LeaveBalanceService
            // already skips leave entitlement entirely for that category.
            'weekly_hours' => ['required', 'integer', 'min:0', $this->weeklyHoursRule()],

            'schedule_mode' => ['required', Rule::in([ScheduleMode::Fixed, ScheduleMode::Flexible])],

            // Not hard-required even in fixed mode: a contract that predates
            // this field (schedule_mode/day range did not exist yet) must
            // still be renewable/editable without being forced to backfill
            // it on the spot. weekly_hours (below) is the real gate — it
            // cannot land on a number without either this range or the flex
            // "hours/week" box, for any category that isn't service.
            'work_day_from' => ['nullable', 'integer', 'between:0,6', 'required_with:work_day_to'],
            'work_day_to' => ['nullable', 'integer', 'between:0,6', 'gte:work_day_from', 'required_with:work_day_from'],
            'work_hours_2_from' => ['nullable', 'date_format:H:i', 'required_with:work_hours_2_to'],
            'work_hours_2_to' => ['nullable', 'date_format:H:i', 'required_with:work_hours_2_from', 'after:work_hours_2_from'],

            'flex_days_per_week' => ['nullable', 'integer', 'between:1,7'],
            'flex_hours_per_week' => ['nullable', 'integer', 'min:1'],
            'flex_hours_per_month' => ['nullable', 'integer', 'between:1,300'],
            'flex_custom_en' => ['nullable', 'string', 'max:255'],
            'flex_custom_ar' => ['nullable', 'string', 'max:255'],
        ]);
    }

    public function validatePay(): array
    {
        if ($this->payIsHidden()) {
            // Nothing on this step is writable for such a role - see
            // preserveHiddenPay() - so the blanks the browser sent are not
            // worth validating; enforcing the ordinary rules on them would
            // reject the save over fields nobody was allowed to fill. What is
            // still worth checking is that there is a stored clause to keep.
            return $this->validate(['salary' => [$this->hiddenPayRule()]]);
        }

        $isFixed = $this->pay_mode === PayMode::Fixed;

        return $this->validate([
            'pay_mode' => ['required', Rule::in([PayMode::Fixed, PayMode::Flexible])],
            'currency' => ['required', 'integer', Rule::in([0, 1, 2, 3])],

            // -- Fixed mode: one flat figure, plus a session length when paid per session.
            'salary' => [Rule::requiredIf($isFixed), 'nullable', 'numeric', 'min:0'],
            'salary_per' => [Rule::requiredIf($isFixed), 'nullable', 'integer'],
            'session_duration' => [
                Rule::requiredIf($isFixed && (int) $this->salary_per === SalaryPer::Session),
                'nullable', 'integer',
                Rule::in([SessionDuration::Hour, SessionDuration::HourAndHalf, SessionDuration::TwoHours]),
            ],

            // -- Flexible mode: an unbounded repeater instead of one figure —
            // at least one condition, or the mode says nothing at all.
            'pay_tiers' => [Rule::requiredIf(! $isFixed), 'array', $isFixed ? 'nullable' : 'min:1'],
            'pay_tiers.*.condition_en' => ['required_with:pay_tiers', 'string', 'max:255'],
            'pay_tiers.*.condition_ar' => ['required_with:pay_tiers', 'string', 'max:255'],
            'pay_tiers.*.amount' => ['required_with:pay_tiers', 'numeric', 'min:0'],
        ]);
    }

    /**
     * Is the current user barred from pay figures?
     *
     * Read from the request rather than held in a property: a Livewire form
     * object round-trips its public state through the browser, and a flag
     * saying "this user may not see salaries" is the last thing that should
     * make that trip. auth() is authoritative on every request and cannot be
     * tampered with between them.
     */
    public function payIsHidden(): bool
    {
        $user = auth()->user();

        return $user !== null && ! $user->canViewSalary();
    }

    // ────────────────────────────────────────────────
    // Persistence
    // ────────────────────────────────────────────────

    /**
     * Full validation + create/update inside a transaction.
     */
    public function save(): Contract
    {
        $this->validateIdentity();
        $this->validateTerms();
        $this->validatePay();

        return DB::transaction(function () {
            if ($this->contract) {
                $this->contract->update($this->buildPayload());
            } else {
                $this->contract = $this->insert();
            }

            $this->syncPayTiers($this->contract);

            return $this->contract;
        });
    }

    /**
     * Create from data that the owning component has already fully validated.
     */
    public function create(): Contract
    {
        return DB::transaction(function (): Contract {
            $this->contract = $this->insert();
            $this->syncPayTiers($this->contract);

            return $this->contract;
        });
    }

    /**
     * Update a specific contract instance without re-running validation
     * (callers are responsible for validating beforehand).
     */
    public function update(Contract $contract): bool
    {
        return DB::transaction(function () use ($contract): bool {
            $result = $contract->update($this->buildPayload());
            $this->syncPayTiers($contract);

            return $result;
        });
    }

    public function saveIdentity(): void
    {
        $this->validateIdentity();

        $this->contract?->update([
            'employee_id' => $this->employee_id,
            'position_id' => $this->position_id,
            'contract_type_id' => $this->contract_type_id,
            'type' => $this->type,
            'place' => $this->place,
            'is_active' => (bool) $this->is_active,
        ]);
    }

    public function saveTerms(): void
    {
        $this->validateTerms();

        if (! $this->contract) {
            return;
        }

        $isTerminated = filled($this->terminated_at) || filled($this->termination_reason_id);

        $this->contract->update([
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'terminated_at' => $this->terminated_at ?? null,
            'termination_reason_id' => $this->termination_reason_id ?? null,
            'reason_of_termination' => $this->reason_of_termination ?? null,
            'weekly_hours' => (int) $this->weekly_hours,
            'work_days_ar' => $this->work_days_ar,
            'work_days_en' => $this->work_days_en,
            'work_hours_from' => $this->work_hours_from,
            'work_hours_to' => $this->work_hours_to,
            // Automatically deactivate when a termination is recorded
            'is_active' => $isTerminated ? false : $this->is_active,
        ]);
    }

    public function savePay(): void
    {
        $this->validatePay();

        // Nothing on this step is editable without sight of the figures -
        // see preserveHiddenPay().
        if ($this->payIsHidden()) {
            return;
        }

        $this->contract?->update([
            'currency' => (int) $this->currency,
            'salary' => $this->salary,
            'salary_per' => (string) $this->salary_per,
        ]);
    }

    private function employeeIsCompleteRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (Employee::query()->whereKey($value)->drafts()->exists()) {
                $fail(__('contracts.errors.employee-incomplete'));
            }
        };
    }

    /**
     * The only pay rule a hidden role gets: there has to be a stored figure to
     * carry forward.
     *
     * Editing an existing contract or renewing one both have a figure already
     * on file, and preserveHiddenPay() moves it across without it ever being
     * visible. A contract created from nothing has no such figure, and saving
     * one would quietly produce a contract with no pay clause at all - so that
     * is the single combination this refuses.
     */
    private function hiddenPayRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($this->contract !== null || $this->renewal_source_id !== null) {
                return;
            }

            $fail(__('validation.custom.salary.hidden_from_role'));
        };
    }

    /**
     * Every category except service must land on an explicit weekly-hours
     * figure one way or another (computed from the fixed day/hour range, or
     * typed into the flex "hours/week" box) — service is the one category
     * LeaveBalanceService exempts from leave entitlement entirely, so a
     * contract that only says "varies" / "coordinated with manager" / free
     * text has nothing to compute a number from, and that is fine only there.
     */
    private function weeklyHoursRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $category = ContractType::find($this->contract_type_id)?->category;

            if ($category !== 'service' && (int) $value < 1) {
                $fail(__('validation.custom.weekly_hours.required_unless_service'));
            }
        };
    }

    // ────────────────────────────────────────────────
    // Private helpers
    // ────────────────────────────────────────────────

    /**
     * Insert a new contract, carrying the transient renewal marker onto the
     * model so ContractObserver::creating() can recognise a renewal in place.
     */
    private function insert(): Contract
    {
        $contract = new Contract($this->buildPayload());
        $contract->renewalSourceId = $this->renewal_source_id;
        $contract->save();

        return $contract;
    }

    /**
     * Replace this contract's pay tiers wholesale with $this->pay_tiers.
     *
     * Delete-then-recreate rather than diffing against existing rows: the
     * repeater has no stable per-row identity the UI needs to preserve
     * (no other table references a tier by id), and the row count is always
     * small, so there is nothing a smarter sync would actually save.
     */
    private function syncPayTiers(Contract $contract): void
    {
        if ($this->payIsHidden()) {
            $this->carryForwardHiddenPayTiers($contract);

            return;
        }

        $contract->payTiers()->delete();

        if ($this->pay_mode !== PayMode::Flexible) {
            return;
        }

        foreach ($this->pay_tiers as $index => $tier) {
            $contract->payTiers()->create([
                'condition_en' => trim((string) ($tier['condition_en'] ?? '')),
                'condition_ar' => trim((string) ($tier['condition_ar'] ?? '')),
                'amount' => $tier['amount'] ?? 0,
                'sort_order' => $index,
            ]);
        }
    }

    /**
     * A hidden role's repeater holds blank amounts, because fillFromModel()
     * withheld them - writing it back would delete a flexible contract's whole
     * pay schedule. So: an existing contract keeps the tiers it has, and a
     * renewal is given a copy of its source's.
     */
    private function carryForwardHiddenPayTiers(Contract $contract): void
    {
        if ($this->renewal_source_id === null || $contract->payTiers()->exists()) {
            return;
        }

        $source = Contract::query()->with('payTiers')->find($this->renewal_source_id);

        foreach ($source?->payTiers ?? [] as $tier) {
            $contract->payTiers()->create([
                'condition_en' => $tier->condition_en,
                'condition_ar' => $tier->condition_ar,
                'amount' => $tier->amount,
                'sort_order' => $tier->sort_order,
            ]);
        }
    }

    private function buildPayload(): array
    {
        $isFixed = $this->schedule_mode === ScheduleMode::Fixed;
        $isFixedPay = $this->pay_mode === PayMode::Fixed;

        $payload = [
            'employee_id' => $this->employee_id,
            'position_id' => $this->position_id,
            'contract_type_id' => $this->contract_type_id,
            'type' => $this->type,
            'place' => $this->place,
            'is_active' => (bool) $this->is_active,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'terminated_at' => $this->terminated_at ?? null,
            'termination_reason_id' => $this->termination_reason_id,
            'reason_of_termination' => $this->reason_of_termination ?? null,
            'weekly_hours' => (int) $this->weekly_hours,
            'work_days_ar' => $this->work_days_ar,
            'work_days_en' => $this->work_days_en,
            'work_hours_from' => $isFixed ? $this->work_hours_from : null,
            'work_hours_to' => $isFixed ? $this->work_hours_to : null,

            'schedule_mode' => $this->schedule_mode,
            // Each mode's own fields are cleared when it is not the active one,
            // so a contract switched from flexible to fixed (or back) does not
            // keep a stale value from the mode it left behind.
            'work_hours_2_from' => $isFixed ? $this->work_hours_2_from : null,
            'work_hours_2_to' => $isFixed ? $this->work_hours_2_to : null,
            'work_day_from' => $isFixed ? $this->work_day_from : null,
            'work_day_to' => $isFixed ? $this->work_day_to : null,
            'show_days_per_week' => $isFixed && $this->show_days_per_week,
            'show_hours_per_week' => $isFixed && $this->show_hours_per_week,
            'show_hours_per_day' => $isFixed && $this->show_hours_per_day,
            'show_day_range' => $isFixed && $this->show_day_range,

            'flex_days_per_week' => $isFixed ? null : $this->flex_days_per_week,
            'flex_hours_per_week' => $isFixed ? null : $this->flex_hours_per_week,
            'flex_hours_per_month' => $isFixed ? null : $this->flex_hours_per_month,
            'flex_varies_by_requirements' => ! $isFixed && $this->flex_varies_by_requirements,
            'flex_coordinated_with_manager' => ! $isFixed && $this->flex_coordinated_with_manager,
            'flex_custom_en' => $isFixed ? null : (filled($this->flex_custom_en) ? trim($this->flex_custom_en) : null),
            'flex_custom_ar' => $isFixed ? null : (filled($this->flex_custom_ar) ? trim($this->flex_custom_ar) : null),

            'pay_mode' => $this->pay_mode,
            // Currency applies to both modes — one flat figure or a list of
            // tiered ones, always in the same currency. The other fields
            // below are each mode's own, cleared when it is not active, same
            // as the schedule fields above.
            'currency' => (int) $this->currency,
            'salary' => $isFixedPay ? $this->salary : null,
            'salary_per' => $isFixedPay ? (string) $this->salary_per : (string) SalaryPer::Unknown,
            'session_duration' => ($isFixedPay && (int) $this->salary_per === SalaryPer::Session)
                ? $this->session_duration
                : null,
        ];

        if ($this->canPersistAutoRenew()) {
            $payload['auto_renew'] = (bool) $this->auto_renew;
        }

        return $this->payIsHidden() ? $this->preserveHiddenPay($payload) : $payload;
    }

    /**
     * Strip the pay clause out of a payload built by someone who could not see
     * it, and put back what is already on file.
     *
     * The whole clause moves together, not just the figure: a role that cannot
     * enter an amount must not be able to switch a contract from fixed to
     * flexible pay either, because the result would be a contract whose pay
     * mode says one thing and whose (untouched) figures say another.
     *
     * On an edit the keys are simply dropped, so update() never names those
     * columns and the stored ciphertext survives byte for byte. On a renewal
     * the source contract's clause is read here, server-side, and carried
     * forward - the same values the renewal copies anyway, without them ever
     * reaching the browser. A contract created from nothing has no clause to
     * carry, which is what hiddenPayRule() refuses up front.
     */
    private function preserveHiddenPay(array $payload): array
    {
        $payKeys = ['pay_mode', 'currency', 'salary', 'salary_per', 'session_duration'];

        foreach ($payKeys as $key) {
            unset($payload[$key]);
        }

        if ($this->contract !== null || $this->renewal_source_id === null) {
            return $payload;
        }

        $source = Contract::query()->find($this->renewal_source_id);

        if ($source === null) {
            return $payload;
        }

        foreach ($payKeys as $key) {
            $payload[$key] = $source->{$key};
        }

        return $payload;
    }

    private function canPersistAutoRenew(): bool
    {
        if (! auth()->check()) {
            return false;
        }

        if ($this->contract) {
            return auth()->user()->can('manageAutoRenew', $this->contract);
        }

        if (! $this->renewal_source_id) {
            return false;
        }

        $source = Contract::query()->find($this->renewal_source_id);

        return $source !== null && auth()->user()->can('manageAutoRenew', $source);
    }

    private function timeForInput(mixed $value, ?string $fallback = null): ?string
    {
        if ($value === null || $value === '') {
            return $fallback;
        }

        if (is_string($value) && preg_match('/^\d{2}:\d{2}/', $value) === 1) {
            return substr($value, 0, 5);
        }

        return Carbon::parse($value)->format('H:i');
    }

    /**
     * Closure-based validation rule that enforces position capacity, skipping
     * the check whenever the seat is already held by the record this write
     * descends from: an edit that leaves the position alone, or a renewal
     * issued onto the same position as its source. Neither adds headcount, and
     * checking them would make renewing a max_emp = 1 position impossible.
     */
    private function positionCapacityRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $isUnchanged = $this->contract && $this->contract->position_id === (int) $value;

            if ($isUnchanged || Contract::isRenewalOfPosition($this->renewal_source_id, (int) $value)) {
                return;
            }

            $position = Position::query()
                ->select('id', 'max_emp', 'exist_emp')
                ->find($value);

            if ($position && ! $position->canAssign()) {
                $fail(__('contracts.errors.position-full'));
            }
        };
    }

    /**
     * Full/Part time only make sense for a volunteer or trial contract;
     * Freelance only for a service one — the composer's own UI already
     * restricts the choice to match (see contract-composer.js::chooseType()),
     * this is the matching server-side check for a request that bypasses it.
     */
    private function typeMatchesCategoryRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $category = ContractType::find($this->contract_type_id)?->category;

            if ($category === null) {
                return; // contract_type_id's own `exists` rule already reports this.
            }

            $isFreelance = (int) $value === ContractTypeEnum::Freelance;

            if ($category === 'service' && ! $isFreelance) {
                $fail(__('validation.custom.type.service_requires_freelance'));
            } elseif ($category !== 'service' && $isFreelance) {
                $fail(__('validation.custom.type.freelance_requires_service'));
            }
        };
    }
}
