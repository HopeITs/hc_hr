<?php

namespace App\Support\Leave;

/**
 * The priced result of a leave window (A3-D contract §2.2).
 *
 * Hours are the storage and arithmetic unit; days are a display conversion
 * only. The quote carries hours and dates ONLY: no salary, pay tier, pay per
 * day or anything derived from contracts.salary may ever be added here (§2.2
 * "Excluded on purpose", §9). A cost of leave is expressed as a warning code,
 * never an amount.
 *
 * Errors block a request, warnings advise. Both are stable codes; the
 * human text lives in resources/lang/{en,ar}/leave_quote.php under
 * `leave_quote.codes.<code>`.
 */
final class LeaveQuote
{
    public const RULE_VERSION = 'leave-calc/1';

    public const UNIT = 'hours';

    // --- Codes (§5). Kind noted where it is fixed. ---------------------------

    /** error: every date is a weekend or holiday */
    public const NO_COUNTABLE_DAYS = 'no_countable_days';

    /** error: weekly hours 0/missing, or a day request on an irregular schedule (D-8) */
    public const SCHEDULE_UNKNOWN = 'schedule_unknown';

    /** error for annual, warning for other types (D-6) */
    public const EXCEEDS_BALANCE = 'exceeds_balance';

    /** error: per-request days over leave_types.base_days */
    public const EXCEEDS_TYPE_MAX = 'exceeds_type_max';

    /** error: hourly request outside the center's open/close times */
    public const OUTSIDE_WORKING_HOURS = 'outside_working_hours';

    /** warning: one or more holidays were skipped */
    public const HOLIDAY_INSIDE = 'holiday_inside';

    /** error: December request not marked exceptional (D-7) */
    public const DECEMBER_RESTRICTED = 'december_restricted';

    /** error: service agreement or draft contract — no balance, nothing deducted */
    public const NO_LEAVE_ENTITLEMENT = 'no_leave_entitlement';

    /** error: hourly request shorter than 15 minutes (D-3a) */
    public const BELOW_MINIMUM = 'below_minimum';

    /** warning: probation leave beyond 3 days is deducted from pay (no amount) */
    public const PROBATION_EXCESS = 'probation_excess';

    /** warning: other pending requests already reserve hours */
    public const PENDING_RESERVED = 'pending_reserved';

    /** warning: weekend fell back to the code default */
    public const BALANCE_UNKNOWN_WEEKEND = 'balance_unknown_weekend';

    /**
     * error: end before start (hourly: end not after start). Not in §5 — an
     * input-shape guard so the service reports instead of throwing.
     */
    public const INVALID_WINDOW = 'invalid_window';

    /**
     * @param  array<int, array{date: string, reason: 'weekend'|'holiday'}>  $excludedDates
     * @param  array<int, array{key: string, params: array<string, int|float|string>}>  $explanation
     * @param  array<int, string>  $warnings
     * @param  array<int, string>  $errors
     */
    public function __construct(
        public readonly float $requestedHours,
        public readonly ?float $requestedDaysDisplay,
        public readonly int $countedDays,
        public readonly array $excludedDates,
        public readonly float $availableHours,
        public readonly float $pendingHours,
        public readonly float $projectedHours,
        public readonly array $explanation,
        public readonly array $warnings,
        public readonly array $errors,
        public readonly ?float $hoursPerDay,
        public readonly bool $calendarDays,
        public readonly string $ruleVersion = self::RULE_VERSION,
    ) {}

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function hasError(string $code): bool
    {
        return in_array($code, $this->errors, true);
    }

    public function hasWarning(string $code): bool
    {
        return in_array($code, $this->warnings, true);
    }

    /**
     * Translation key of a code's message.
     */
    public static function messageKey(string $code): string
    {
        return 'leave_quote.codes.'.$code;
    }

    /**
     * Plain scalars and arrays only — safe for a Livewire public property or
     * JSON. Never contains a model, and never pay data.
     *
     * @return array{
     *     unit: string,
     *     requested_hours: float,
     *     requested_days_display: float|null,
     *     counted_days: int,
     *     excluded_dates: array<int, array{date: string, reason: string}>,
     *     available_hours: float,
     *     pending_hours: float,
     *     projected_hours: float,
     *     explanation: array<int, array{key: string, params: array<string, int|float|string>}>,
     *     warnings: array<int, string>,
     *     errors: array<int, string>,
     *     rule_version: string,
     *     hours_per_day: float|null,
     *     calendar_days: bool,
     * }
     */
    public function toArray(): array
    {
        return [
            'unit' => self::UNIT,
            'requested_hours' => $this->requestedHours,
            'requested_days_display' => $this->requestedDaysDisplay,
            'counted_days' => $this->countedDays,
            'excluded_dates' => $this->excludedDates,
            'available_hours' => $this->availableHours,
            'pending_hours' => $this->pendingHours,
            'projected_hours' => $this->projectedHours,
            'explanation' => $this->explanation,
            'warnings' => $this->warnings,
            'errors' => $this->errors,
            'rule_version' => $this->ruleVersion,
            'hours_per_day' => $this->hoursPerDay,
            'calendar_days' => $this->calendarDays,
        ];
    }

    /**
     * Rebuild a quote from toArray() output (e.g. a Livewire property).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            requestedHours: (float) ($data['requested_hours'] ?? 0),
            requestedDaysDisplay: isset($data['requested_days_display']) ? (float) $data['requested_days_display'] : null,
            countedDays: (int) ($data['counted_days'] ?? 0),
            excludedDates: (array) ($data['excluded_dates'] ?? []),
            availableHours: (float) ($data['available_hours'] ?? 0),
            pendingHours: (float) ($data['pending_hours'] ?? 0),
            projectedHours: (float) ($data['projected_hours'] ?? 0),
            explanation: (array) ($data['explanation'] ?? []),
            warnings: array_values((array) ($data['warnings'] ?? [])),
            errors: array_values((array) ($data['errors'] ?? [])),
            hoursPerDay: isset($data['hours_per_day']) ? (float) $data['hours_per_day'] : null,
            calendarDays: (bool) ($data['calendar_days'] ?? false),
            ruleVersion: (string) ($data['rule_version'] ?? self::RULE_VERSION),
        );
    }
}
