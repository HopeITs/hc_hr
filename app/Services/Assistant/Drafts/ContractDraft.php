<?php

namespace App\Services\Assistant\Drafts;

use App\Enums\ContractPlace;
use App\Enums\ContractType as ContractTypeEnum;
use App\Enums\Currency;
use App\Enums\PayMode;
use App\Enums\SalaryPer;
use App\Enums\ScheduleMode;
use App\Enums\SessionDuration;
use App\Models\City;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Carbon\Carbon;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * A new contract, drafted from the chat and created only when the user
 * confirms it. Offered to whoever the New menu offers the create-contract
 * screen to (contracts.create under the active role, and sight of pay).
 *
 * Every rule is the screen's own (ContractDrafter drives ContractCreate):
 * this class only turns the model's arguments into the screen's payload,
 * offers the same choices the screen's dropdowns offer, and words the result.
 *
 * Scope: one employee, one position, a fixed weekly schedule (day range and
 * one daily shift) and a single fixed pay figure. Flexible schedules, a
 * second shift, flexible pay tiers, auto-renew and files are set on the
 * contract page afterwards.
 */
class ContractDraft implements RecordDraft
{
    private const MAX_EMPLOYEES = 10;

    private const MAX_POSITIONS = 40;

    private const DEFAULT_HOURS = ['09:00', '17:00'];

    public function __construct(private ?ContractDrafter $drafter = null)
    {
        $this->drafter ??= app(ContractDrafter::class);
    }

    public function type(): string
    {
        return 'contract';
    }

    /** The New menu's rule for this screen (Navigation::newActions). */
    public function allowed(User $user): bool
    {
        return $user->hasActivePermission('contracts.create') && $user->canViewSalary();
    }

    public function declarations(User $user): array
    {
        if (! $this->allowed($user)) {
            return [];
        }

        return [
            [
                'name' => 'find_employee_for_contract',
                'description' => 'Finds employees the user may give a new contract to, by name (Arabic or English). Returns at most '.self::MAX_EMPLOYEES.' with their id, city, and the end date of any contract in force. Use it to get employee_id for draft_contract; if several match, ask the user which one.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'Part of the employee\'s name, e.g. "Rami" or "رامي خوري".'],
                    ],
                    'required' => ['name'],
                ],
            ],
            [
                'name' => 'get_contract_options',
                'description' => 'The choices of the create-contract screen: contract types, employment types, places, currencies, pay periods, session lengths, week days, the cities the user may use, and the positions there that still have a free seat. Call it before draft_contract and use only these ids.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'city_id' => ['type' => 'integer', 'description' => 'Only positions in this city (from cities). Omit for every city the user may use.'],
                        'position_search' => ['type' => 'string', 'description' => 'Part of the position title, e.g. "accountant" or "محاسب".'],
                    ],
                ],
            ],
            [
                'name' => 'draft_contract',
                'description' => 'Prepares a new contract for the user to confirm; it does not create it. Checked with the create-contract screen\'s own rules. Covers a fixed weekly schedule and one fixed pay figure only: flexible schedules, a second shift and conditional (flexible) pay are set on the contract page. Never invent the salary, currency, or working days: ask the user.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'employee_id' => ['type' => 'integer', 'description' => 'From find_employee_for_contract.'],
                        'position_id' => ['type' => 'integer', 'description' => 'From get_contract_options positions.'],
                        'city_id' => ['type' => 'integer', 'description' => 'The contract\'s city, from get_contract_options cities. Omit to use the employee\'s own city.'],
                        'contract_type_id' => ['type' => 'integer', 'description' => 'From get_contract_options contract_types.'],
                        'employment_type' => ['type' => 'integer', 'description' => 'From employment_types. Omit for the default: Freelance for a service contract type, otherwise Full Time.'],
                        'place' => ['type' => 'integer', 'description' => 'From places. Omit for onsite.'],
                        'start_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD. Omit for the contract type\'s default start (the first of next month).'],
                        'end_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD, at most one year after the start. Omit for the contract type\'s default end.'],
                        'work_day_from' => ['type' => 'integer', 'description' => 'First working day of the week: 0 = Sunday ... 6 = Saturday.'],
                        'work_day_to' => ['type' => 'integer', 'description' => 'Last working day of the week (not before work_day_from): 0 = Sunday ... 6 = Saturday.'],
                        'work_hours_from' => ['type' => 'string', 'description' => 'HH:MM, 24-hour. Omit for 09:00.'],
                        'work_hours_to' => ['type' => 'string', 'description' => 'HH:MM, 24-hour. Omit for 17:00.'],
                        'salary' => ['type' => 'number', 'description' => 'The pay figure, as the user said it.'],
                        'currency' => ['type' => 'integer', 'description' => 'From currencies.'],
                        'salary_per' => ['type' => 'integer', 'description' => 'From pay_periods. Omit for per month.'],
                        'session_duration' => ['type' => 'integer', 'description' => 'From session_durations; required only when paid per session.'],
                    ],
                    'required' => ['employee_id', 'position_id', 'contract_type_id', 'work_day_from', 'work_day_to', 'salary', 'currency'],
                ],
            ],
        ];
    }

    public function call(User $user, string $tool, array $args): array
    {
        if (! $this->allowed($user)) {
            return ['error' => "The tool {$tool} is not available."];
        }

        return match ($tool) {
            'find_employee_for_contract' => $this->findEmployees($user, $args),
            'get_contract_options' => $this->options($user, $args),
            'draft_contract' => $this->draft($user, $args),
            default => ['error' => "The tool {$tool} is not available."],
        };
    }

    public function card(array $proposal): array
    {
        $days = filled($proposal['work_days'] ?? null) && filled($proposal['work_hours'] ?? null)
            ? $proposal['work_days'].' · '.$proposal['work_hours']
            : ($proposal['work_days'] ?? $proposal['work_hours'] ?? null);

        return [
            'title' => __('assistant-contract.title'),
            'icon' => 'file',
            'rows' => array_filter([
                __('assistant-contract.fields.employee') => $proposal['employee'] ?? null,
                __('assistant-contract.fields.position') => $proposal['position'] ?? null,
                __('assistant-contract.fields.city') => $proposal['city'] ?? null,
                __('assistant-contract.fields.contract_type') => $this->join([$proposal['contract_type'] ?? null, $proposal['employment_type_label'] ?? null, $proposal['place_label'] ?? null]),
                __('assistant-contract.fields.from') => $proposal['start_date'] ?? null,
                __('assistant-contract.fields.to') => $proposal['end_date'] ?? null,
                __('assistant-contract.fields.schedule') => $days,
                __('assistant-contract.fields.weekly_hours') => isset($proposal['weekly_hours']) ? (string) $proposal['weekly_hours'] : null,
                __('assistant-contract.fields.pay') => $proposal['pay'] ?? null,
            ], fn ($value) => filled($value)),
        ];
    }

    public function submit(User $user, array $proposal, string $token): array
    {
        if (! $this->allowed($user)) {
            $this->forbidden();
        }

        $payload = $this->payload($user, $proposal);
        $contract = $this->drafter->store($user, $payload, $token);

        return [
            'message' => __('assistant-contract.created', [
                'name' => $contract->employee?->nameForLocale(app()->getLocale()) ?? '',
            ]),
            'url' => route('contracts.show', $contract),
            'link' => __('assistant-contract.open'),
        ];
    }

    // ────────────────────────────────────────────────
    // Tools
    // ────────────────────────────────────────────────

    private function findEmployees(User $user, array $args): array
    {
        $name = trim((string) (is_scalar($args['name'] ?? null) ? $args['name'] : ''));

        if (mb_strlen($name) < 2) {
            return ['employees' => [], 'note' => 'Give at least two letters of the name.'];
        }

        $locale = app()->getLocale();
        $employees = $this->employees($user)
            ->with('city')
            ->where(function (Builder $query) use ($name) {
                foreach (preg_split('/\s+/u', $name) as $word) {
                    $query->where(fn (Builder $q) => $q->whereLikeAny(
                        ['first_name_en', 'last_name_en', 'first_name_ar', 'last_name_ar', 'username'],
                        $word,
                    ));
                }
            })
            ->orderBy("first_name_{$locale}")
            ->limit(self::MAX_EMPLOYEES + 1)
            ->get();

        $inForce = Contract::query()
            ->activeNow()
            ->whereIn('employee_id', $employees->modelKeys())
            ->get(['employee_id', 'end_date'])
            ->mapWithKeys(fn (Contract $contract) => [$contract->employee_id => $contract->end_date?->toDateString()]);

        return array_filter([
            'employees' => $employees->take(self::MAX_EMPLOYEES)->map(fn (Employee $employee) => array_filter([
                'id' => $employee->id,
                'name' => $employee->nameForLocale($locale),
                'city_id' => $employee->city_id,
                'city' => $employee->city?->name,
                'contract_in_force_until' => $inForce[$employee->id] ?? null,
            ], fn ($value) => $value !== null))->values()->all(),
            'truncated' => $employees->count() > self::MAX_EMPLOYEES ?: null,
            'note' => $employees->isEmpty() ? 'No employee the user may give a contract to matches that name.' : null,
        ], fn ($value) => $value !== null);
    }

    private function options(User $user, array $args): array
    {
        $locale = app()->getLocale();
        $cities = $this->cities($user)->get();
        $cityId = filter_var($args['city_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
        $search = trim((string) (is_scalar($args['position_search'] ?? null) ? $args['position_search'] : ''));

        $cityIds = $cityId !== null
            ? array_values(array_intersect([$cityId], $cities->modelKeys()))
            : $cities->modelKeys();

        $positions = $cityIds === [] ? collect() : Position::query()
            ->inCity($cityIds)
            ->whereColumn('exist_emp', '<', 'max_emp')
            ->with(['center.city', 'city', 'program'])
            ->when($search !== '', fn (Builder $query) => $query->whereLikeAny(['title_en', 'title_ar'], $search))
            ->orderBy("title_{$locale}")
            ->limit(self::MAX_POSITIONS + 1)
            ->get();

        return array_filter([
            'contract_types' => ContractType::query()->orderBy('id')->get()->map(fn (ContractType $type) => [
                'id' => $type->id,
                'name' => $type->name,
                'category' => $type->category,
            ])->all(),
            'employment_types' => $this->labelled([ContractTypeEnum::FullTime, ContractTypeEnum::PartTime, ContractTypeEnum::Freelance], fn (int $v) => ContractTypeEnum::getLocalizedName($v)['value']),
            'employment_type_rule' => 'Freelance only for a service contract type; Full or Part Time for every other type.',
            'places' => $this->labelled([ContractPlace::Onsite, ContractPlace::Online], fn (int $v) => ContractPlace::getLocalizedName($v)['value']),
            'currencies' => $this->labelled([Currency::SYP, Currency::USD, Currency::EUR], fn (int $v) => Currency::getLocalizedName($v)[$locale]),
            'pay_periods' => $this->labelled([SalaryPer::Month, SalaryPer::Hour, SalaryPer::Session], fn (int $v) => SalaryPer::getLocalizedName($v)['value']),
            'session_durations' => $this->labelled([SessionDuration::Hour, SessionDuration::HourAndHalf, SessionDuration::TwoHours], fn (int $v) => SessionDuration::getLocalizedName($v)['value']),
            'week_days' => collect(range(0, 6))->map(fn (int $day) => ['id' => $day, 'name' => $this->dayName($day)])->all(),
            'cities' => $cities->map(fn (City $city) => ['id' => $city->id, 'name' => $city->name])->values()->all(),
            'positions' => $positions->take(self::MAX_POSITIONS)->map(fn (Position $position) => array_filter([
                'id' => $position->id,
                'title' => $position->title,
                'city' => ($position->center?->city ?? $position->city)?->name,
                'center' => $position->center?->name,
                'program' => $position->program?->name,
                'free_seats' => max(0, (int) $position->max_emp - (int) $position->exist_emp),
            ], fn ($value) => $value !== null))->values()->all(),
            'positions_truncated' => $positions->count() > self::MAX_POSITIONS ? 'More positions match: narrow with position_search or city_id.' : null,
            'note' => $positions->isEmpty() ? 'No position with a free seat matches in the user\'s cities.' : null,
        ], fn ($value) => $value !== null);
    }

    private function draft(User $user, array $args): array
    {
        try {
            $payload = $this->drafter->check($user, $this->payloadFromArgs($user, $args));
        } catch (ValidationException $e) {
            return [
                'status' => 'invalid',
                'errors' => array_values(array_unique(Arr::flatten($e->errors()))),
                'note' => 'Nothing was drafted. Tell the user what to change, then draft again.',
            ];
        }

        return [
            'status' => 'drafted',
            'proposal' => $this->proposal($payload),
            'note' => 'Not created. Summarise the draft and ask the user to press Confirm below your answer.',
        ];
    }

    // ────────────────────────────────────────────────
    // Payload
    // ────────────────────────────────────────────────

    /**
     * The screen's submit() payload from the model's arguments. Ids are only
     * shaped here; the screen's own checks decide whether they are allowed.
     */
    private function payloadFromArgs(User $user, array $args): array
    {
        $int = fn (string $key): ?int => is_numeric($args[$key] ?? null) ? (int) $args[$key] : null;
        $text = fn (string $key): ?string => is_scalar($args[$key] ?? null) && trim((string) $args[$key]) !== '' ? trim((string) $args[$key]) : null;

        $employee = $int('employee_id') ? $this->employees($user)->find($int('employee_id')) : null;

        // The screen's employee list (contractable, in the user's cities):
        // anyone else it would never have offered.
        if ($employee === null) {
            throw ValidationException::withMessages(['employee_id' => __('assistant-contract.errors.employee')]);
        }

        $cityId = $int('city_id') ?? $employee->city_id;

        // Only the active cities the screen's city list offers.
        if (! $this->cities($user)->whereKey($cityId)->exists()) {
            throw ValidationException::withMessages(['city_id' => __('contracts.errors.city-not-allowed')]);
        }

        $typeId = $int('contract_type_id');
        $type = $typeId ? ContractType::query()->find($typeId) : null;

        [$start, $end] = $this->term($type, $text('start_date'), $text('end_date'));

        $dayFrom = $int('work_day_from');
        $dayTo = $int('work_day_to');

        // The screen will not go on without a day range (scheduleValid).
        if ($dayFrom === null || $dayTo === null) {
            throw ValidationException::withMessages(['work_day_from' => __('assistant-contract.errors.days')]);
        }

        $hoursFrom = $text('work_hours_from') ?? self::DEFAULT_HOURS[0];
        $hoursTo = $text('work_hours_to') ?? self::DEFAULT_HOURS[1];
        $position = $int('position_id') ? Position::query()->find($int('position_id')) : null;

        return [
            'cityId' => $cityId,
            'employeeId' => $employee->id,
            'programId' => $position?->program_id,
            'centerId' => $position?->center_id,
            'positionId' => $int('position_id'),
            'contractTypeId' => $typeId,
            'type' => $int('employment_type') ?? ($type?->category === 'service' ? ContractTypeEnum::Freelance : ContractTypeEnum::FullTime),
            'place' => $int('place') ?? ContractPlace::Onsite,
            'startDate' => $start,
            'endDate' => $end,
            'scheduleMode' => ScheduleMode::Fixed,
            'workHoursFrom' => $hoursFrom,
            'workHoursTo' => $hoursTo,
            'workDayFrom' => $dayFrom,
            'workDayTo' => $dayTo,
            'weeklyHours' => $this->weeklyHours($dayFrom, $dayTo, $hoursFrom, $hoursTo),
            // A new contract states the whole standard clause (the composer's default).
            'showDaysPerWeek' => true,
            'showHoursPerWeek' => true,
            'showHoursPerDay' => true,
            'showDayRange' => true,
            'payMode' => PayMode::Fixed,
            'salary' => is_numeric($args['salary'] ?? null) ? $args['salary'] : null,
            'currency' => $int('currency'),
            'salaryPer' => $int('salary_per') ?? SalaryPer::Month,
            'sessionDuration' => $int('session_duration'),
            'payTiers' => [],
        ];
    }

    /**
     * The confirmed proposal back into the screen's payload. Every id is
     * re-checked by the screen when it saves; the labels are not read.
     */
    private function payload(User $user, array $proposal): array
    {
        $payload = Arr::only($proposal, [
            'employee_id', 'position_id', 'city_id', 'contract_type_id', 'employment_type', 'place',
            'start_date', 'end_date', 'work_day_from', 'work_day_to', 'work_hours_from', 'work_hours_to',
            'salary', 'currency', 'salary_per', 'session_duration',
        ]);

        try {
            return $this->payloadFromArgs($user, $payload);
        } catch (ValidationException) {
            // The employee or city has left this user's reach since the draft.
            $this->forbidden();
        }
    }

    /** What draft_contract returns and the card shows: ids and readable labels. */
    private function proposal(array $payload): array
    {
        $locale = app()->getLocale();
        $employee = Employee::query()->find($payload['employeeId']);
        $position = Position::query()->with(['center', 'program'])->find($payload['positionId']);
        $currency = Currency::getLocalizedName((int) $payload['currency'])[$locale];
        $per = SalaryPer::getLocalizedName((int) $payload['salaryPer'])['value'];
        $session = (int) $payload['salaryPer'] === SalaryPer::Session && $payload['sessionDuration']
            ? SessionDuration::getLocalizedName((int) $payload['sessionDuration'])['value']
            : null;

        return [
            'employee_id' => (int) $payload['employeeId'],
            'employee' => $employee?->nameForLocale($locale),
            'position_id' => (int) $payload['positionId'],
            'position' => $this->join([$position?->title, $position?->center?->name, $position?->program?->name]),
            'city_id' => (int) $payload['cityId'],
            'city' => City::query()->find($payload['cityId'])?->name,
            'contract_type_id' => (int) $payload['contractTypeId'],
            'contract_type' => ContractType::query()->find($payload['contractTypeId'])?->name,
            'employment_type' => (int) $payload['type'],
            'employment_type_label' => ContractTypeEnum::getLocalizedName((int) $payload['type'])['value'],
            'place' => (int) $payload['place'],
            'place_label' => ContractPlace::getLocalizedName((int) $payload['place'])['value'],
            'start_date' => $payload['startDate'],
            'end_date' => $payload['endDate'],
            'work_day_from' => (int) $payload['workDayFrom'],
            'work_day_to' => (int) $payload['workDayTo'],
            'work_days' => $payload['workDayFrom'] === $payload['workDayTo']
                ? $this->dayName((int) $payload['workDayFrom'])
                : __('assistant-contract.day-range', ['from' => $this->dayName((int) $payload['workDayFrom']), 'to' => $this->dayName((int) $payload['workDayTo'])]),
            'work_hours_from' => $payload['workHoursFrom'],
            'work_hours_to' => $payload['workHoursTo'],
            'work_hours' => $payload['workHoursFrom'].' – '.$payload['workHoursTo'],
            'weekly_hours' => (int) $payload['weeklyHours'],
            'salary' => $payload['salary'],
            'currency' => (int) $payload['currency'],
            'salary_per' => (int) $payload['salaryPer'],
            'session_duration' => $payload['sessionDuration'] !== null ? (int) $payload['sessionDuration'] : null,
            'pay' => __('assistant-contract.pay', ['amount' => $payload['salary'], 'currency' => $currency, 'per' => $per])
                .($session ? ' ('.$session.')' : ''),
        ];
    }

    /**
     * The term the screen fills in for a contract type (ContractForm::
     * updatedContractTypeId and the composer's defaultEndFrom): what the user
     * gave is kept, only what is missing is defaulted.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function term(?ContractType $type, ?string $start, ?string $end): array
    {
        $date = fn (?string $value): ?string => $value !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
        $start = $date($start);
        $end = $date($end);

        if ($type === null || $end !== null) {
            return [$start, $end];
        }

        $isTrial = $type->category === 'trial';

        try {
            $from = $start !== null ? Carbon::parse($start) : Carbon::now()->addMonthNoOverflow()->startOfMonth();
        } catch (Throwable) {
            return [$start, $end];
        }

        if ($isTrial) {
            $to = $from->copy()->addMonthsNoOverflow(3);
        } else {
            $yearEnd = Carbon::now()->endOfYear()->startOfDay();
            $to = $yearEnd->gt($from) ? $yearEnd : $from->copy()->addYear()->subDay();
        }

        return [$start ?? $from->toDateString(), $to->toDateString()];
    }

    /** The composer's weeklyHours getter for a fixed schedule with one shift. */
    private function weeklyHours(int $dayFrom, int $dayTo, string $from, string $to): float
    {
        $minutes = fn (string $time): ?int => preg_match('/^(\d{1,2}):(\d{2})/', $time, $m) ? ((int) $m[1]) * 60 + (int) $m[2] : null;
        $start = $minutes($from);
        $finish = $minutes($to);
        $hoursPerDay = $start !== null && $finish !== null && $finish > $start ? ($finish - $start) / 60 : 0;
        $days = $dayTo >= $dayFrom ? $dayTo - $dayFrom + 1 : 0;

        return round($days * $hoursPerDay, 2);
    }

    // ────────────────────────────────────────────────
    // The screen's choices
    // ────────────────────────────────────────────────

    /**
     * The screen's employee list (employeeSearchOptions): contractable, in
     * the active role's cities.
     */
    private function employees(User $user): Builder
    {
        $cityIds = $user->activeCityIds();

        return Employee::query()
            ->contractable()
            ->when($cityIds !== null, fn (Builder $query) => $query->whereIn('employees.city_id', $cityIds));
    }

    /** The screen's city list: active, in the active role's cities. */
    private function cities(User $user): Builder
    {
        $cityIds = $user->activeCityIds();

        return City::query()
            ->where('is_active', true)
            ->when($cityIds !== null, fn (Builder $query) => $query->whereKey($cityIds));
    }

    /** @return list<array{id: int, name: string}> */
    private function labelled(array $values, Closure $label): array
    {
        return array_map(fn (int $value) => ['id' => $value, 'name' => $label($value)], $values);
    }

    private function dayName(int $day): string
    {
        return Carbon::now()->startOfWeek(Carbon::SUNDAY)->addDays($day)->translatedFormat('l');
    }

    private function join(array $parts): ?string
    {
        $parts = array_filter($parts, fn ($part) => filled($part));

        return $parts === [] ? null : implode(' · ', $parts);
    }

    private function forbidden(): never
    {
        throw ValidationException::withMessages(['draft' => __('assistant.draft.forbidden')]);
    }
}
