<?php

namespace App\Models;

use App\Enums\ContractType as ContractTypeEnum;
use App\Enums\LeaveRequestStatus;
use App\Services\DigitalIdManager;
use App\Services\LeaveRequestPricing;
use App\Traits\HasTranslation;
use App\Traits\RedactsSensitiveActivityLog;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Employee extends Model implements HasMedia
{
    use HasFactory, HasTranslation, InteractsWithMedia, LogsActivity, RedactsSensitiveActivityLog;

    /*
     * B5 (DEC-04, docs/ux/employee-lifecycle.md): an incomplete record being
     * entered by HR is a draft; everything else is complete. A draft is always
     * inactive, but inactive does not mean draft: never use is_active alone
     * for that. record_status is not fillable; only the onboarding code sets it.
     */
    public const RECORD_DRAFT = 'draft';

    public const RECORD_COMPLETE = 'complete';

    /** Days after which an untouched draft is listed for HR review (B5D-Q7). */
    public const DRAFT_REVIEW_DAYS = 90;

    /** Days after which a complete volunteer still awaiting a contract is listed for review (B5D-Q5). */
    public const AWAITING_REVIEW_DAYS = 60;

    protected $fillable = [
        'city_id',
        'first_name_en',
        'first_name_ar',
        'last_name_en',
        'last_name_ar',
        'father_name_en',
        'father_name_ar',
        'mother_name_en',
        'mother_name_ar',
        'username',
        'national_number',
        'actual_dob',
        'dob',
        'pob_en',
        'pob_ar',
        'civil_registry_office',
        'civil_registry_number',
        'gender',
        'nationality',
        'marital_status',
        'children',
        'work_phone',
        'personal_phone',
        'personal_email',
        'whatsapp_work',
        'telegram_work',
        'facebook',
        'instagram',
        'linkedin',
        'address_en',
        'address_ar',
        'skills',
        'interests',
        'is_active',
        'is_board_member',
        'board_member_started_at',
        'board_member_end_at',
        'joined_at',
    ];

    /**
     * Logged by name only, never by value. @see RedactsSensitiveActivityLog
     */
    protected array $sensitiveLogAttributes = [
        'national_number',
        'civil_registry_number',
        'civil_registry_office',
        'address_en',
        'address_ar',
        'dob',
        'actual_dob',
        'personal_phone',
        'work_phone',
        'personal_email',
        'whatsapp_work',
        'telegram_work',
        'father_name_en',
        'father_name_ar',
        'mother_name_en',
        'mother_name_ar',
        'children',
        'marital_status',
        'digital_id_token',
    ];

    protected $casts = [
        'dob' => 'datetime',
        'actual_dob' => 'datetime',
        'board_member_started_at' => 'datetime',
        'board_member_end_at' => 'datetime',
        'joined_at' => 'date',
        'is_active' => 'boolean',
        'is_board_member' => 'boolean',
        'children' => 'integer',
        'digital_id_enabled' => 'boolean',
        'digital_id_rotated_at' => 'datetime',
        'digital_id_revoked_at' => 'datetime',
        // Deliberately not fillable: only DigitalIdManager moves it, forward.
        'digital_id_card_index' => 'integer',
    ];

    /** Memoized result of latestActiveContract() — not a database column. */
    protected ?Contract $latestActiveContractMemo = null;

    protected bool $latestActiveContractResolved = false;

    /** Memoized result of isOnLeaveToday() — not a database column. */
    protected ?bool $onLeaveTodayMemo = null;

    /**
     * The public link of this employee's current card, for printing:
     * /ID/[city][program][employee id]-[index]. Null when no link can be built
     * (no city, no current position, or a code that is not 1–3 digits).
     *
     * @see DigitalIdManager
     */
    public function digitalIdUrl(): ?string
    {
        $card = app(DigitalIdManager::class)->cardCode($this);

        return $card === null ? null : route('digital-id.show', ['card' => $card]);
    }

    public function nameForLocale(string $locale): string
    {
        $locale = in_array($locale, ['en', 'ar'], true) ? $locale : app()->getLocale();
        $name = trim(implode(' ', array_filter([
            $this->getAttribute("first_name_{$locale}"),
            $this->getAttribute("last_name_{$locale}"),
        ])));

        return $name !== '' ? $name : trim((string) $this->name);
    }

    public function getDepartmentAttribute()
    {
        return $this->latestActiveContract()?->position->department;
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function lastSavedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_saved_by');
    }

    public function isDraft(): bool
    {
        return $this->record_status === self::RECORD_DRAFT;
    }

    /** Complete, not active, and never held a real contract: "Ready, awaiting contract". */
    public function isAwaitingContract(): bool
    {
        if ($this->isDraft() || $this->is_active) {
            return false;
        }

        // Lists load this once with withExists(['realContracts as has_real_contract']).
        $hasRealContract = array_key_exists('has_real_contract', $this->attributes)
            ? (bool) $this->attributes['has_real_contract']
            : $this->realContracts()->exists();

        return ! $hasRealContract;
    }

    /** Contracts other than Draft-type position reservations. */
    public function realContracts(): HasMany
    {
        return $this->hasMany(Contract::class, 'employee_id')->where('type', '!=', ContractTypeEnum::Draft);
    }

    /**
     * Employees inside $user's active-role city scope (User::activeCityIds()):
     * every city when that is null, none at all when it is an empty list (a
     * regional role whose city does not exist). Never test the list for
     * truthiness: `when([])` skips the filter and shows everything (G-C-13 / F13).
     */
    public function scopeInActiveCities(Builder $query, User $user): Builder
    {
        $cityIds = $user->activeCityIds();

        return $query->when($cityIds !== null, fn (Builder $q) => $q->whereIn($q->qualifyColumn('city_id'), $cityIds));
    }

    public function scopeDrafts(Builder $query): Builder
    {
        return $query->where('employees.record_status', self::RECORD_DRAFT);
    }

    public function scopeCompleteRecords(Builder $query): Builder
    {
        return $query->where('employees.record_status', self::RECORD_COMPLETE);
    }

    public function scopeAwaitingContract(Builder $query): Builder
    {
        return $query->completeRecords()
            ->where('employees.is_active', false)
            ->whereDoesntHave('realContracts');
    }

    /**
     * Who may be given a contract in the composer: active staff, as before,
     * plus complete volunteers awaiting their first contract. Never a draft
     * (AC-B5-02), and not a former employee: re-hiring still starts with HR
     * re-activating them, as it always has.
     */
    public function scopeContractable(Builder $query): Builder
    {
        return $query->completeRecords()->where(fn (Builder $q) => $q
            ->where('employees.is_active', true)
            ->orWhereDoesntHave('realContracts'));
    }

    public function allContracts()
    {
        return $this->hasMany(Contract::class, 'employee_id');
    }

    public function contracts()
    {
        return $this->hasMany(Contract::class, 'employee_id')
            ->activeNow();

    }

    public function latestActiveContract(): ?Contract
    {
        if ($this->latestActiveContractResolved) {
            return $this->latestActiveContractMemo;
        }

        // Reuse the eager-loaded `contracts` relation when present so callers
        // that iterate many employees don't trigger one query per row.
        $contract = $this->relationLoaded('contracts')
            ? $this->contracts->sortBy([
                ['start_date', 'desc'],
                ['id', 'desc'],
            ])->first()
            : $this->contracts()
                ->with(['position.department', 'leaveBalances'])
                ->orderByDesc('start_date')
                ->orderByDesc('id')
                ->first();

        $this->latestActiveContractResolved = true;

        return $this->latestActiveContractMemo = $contract;
    }

    /**
     * The contract shown as the employee's main position: the active one whose
     * position has the highest job level (lowest grade; no level ranks last),
     * newest first on a tie. Display only: leave balances keep following
     * latestActiveContract().
     */
    public function primaryContract(): ?Contract
    {
        $contracts = $this->relationLoaded('contracts')
            ? $this->contracts
            : $this->contracts()->with('position.jobLevel')->get();

        return $contracts
            ->sortBy([
                fn ($a, $b) => ($a->position?->jobLevel?->grade ?? PHP_INT_MAX) <=> ($b->position?->jobLevel?->grade ?? PHP_INT_MAX),
                fn ($a, $b) => $b->created_at <=> $a->created_at,
            ])
            ->first();
    }

    /**
     * Whether an accepted leave covers today: a day leave for the whole of
     * its dates, an hour leave only between its start and end times.
     */
    public function isOnLeaveToday(): bool
    {
        return $this->onLeaveTodayMemo ??= LeaveRequest::query()
            ->where('status', LeaveRequestStatus::Accepted)
            ->where(fn ($query) => $query
                ->where(fn ($query) => $query
                    ->where('type', 'day')
                    ->whereDate('start_at', '<=', today())
                    ->whereDate('end_at', '>=', today()))
                ->orWhere(fn ($query) => $query
                    ->where('type', 'hour')
                    ->where('start_at', '<=', now())
                    ->where('end_at', '>=', now())))
            ->whereHas('leaveBalance.contract', fn ($query) => $query->where('employee_id', $this->id))
            ->exists();
    }

    public function defaultDeliverItems(): Employee|HasMany
    {
        return $this->hasMany(DefaultDeliverable::class, 'employee_id');
    }

    public function positions(): BelongsToMany
    {
        return $this->belongsToMany(Position::class, 'contracts', 'employee_id', 'position_id')
            ->withPivot([
                'id', 'contract_type_id', 'start_date', 'end_date', 'terminated_at',
                'salary', 'currency', 'weekly_hours', transColumn('work_days'), 'work_hours_from', 'work_hours_to', 'is_active',
            ])
            ->wherePivot('is_active', true)
            ->where(function ($query) {
                $today = now()->toDateString();
                $query->whereDate('contracts.start_date', '<=', $today)
                    ->whereDate('contracts.end_date', '>=', $today);
            });
    }

    public function getDirectManagerAttribute()
    {
        $this->latestActiveContract()?->position?->directManager?->employee();
    }

    public function scopeInvolvedInProgram($query, $programId)
    {
        return $query->whereHas('contracts.position.center', function ($q) use ($programId) {
            $q->where('program_id', $programId);
        });
    }

    public function educations()
    {
        return $this->hasMany(Education::class);
    }

    public function courses()
    {
        return $this->hasMany(Course::class);
    }

    public function experiences()
    {
        return $this->hasMany(Experience::class);
    }

    public function badges()
    {
        return $this->hasMany(Badge::class);
    }

    public function evaluations()
    {
        return $this->hasMany(Evaluation::class);
    }

    public function relatives()
    {
        return $this->hasMany(Relative::class);
    }

    public function checklists()
    {
        return $this->hasMany(Checklist::class);
    }

    public function employeeAttachments()
    {
        return $this->getMedia('attachments');
    }

    public function items()
    {
        return $this->hasMany(EmployeeItem::class);
    }

    public function jobLevels(): BelongsToMany
    {
        return $this->belongsToMany(JobLevel::class, 'contracts', 'employee_id', 'job_level_id')->withPivot(['id', 'contract_type_id', 'department_id', 'job_level_id', 'is_active', 'start_date', 'end_date', 'terminated_at', 'salary', 'currency', 'weekly_hours', 'work_days', 'work_hours']);
    }

    public function contractTypes(): BelongsToMany
    {
        return $this->belongsToMany(ContractType::class, 'contracts', 'employee_id', 'contract_type_id')->withPivot(['id', 'contract_type_id', 'department_id', 'job_level_id', 'is_active', 'start_date', 'end_date', 'terminated_at', 'salary', 'currency', 'weekly_hours', 'work_days', 'work_hours']);
    }

    public function account()
    {
        return $this->hasOne(User::class, 'employee_id');
    }

    /** D4-I: the onboarding journey started by the first real contract, if any. */
    public function onboardingJourney(): HasOne
    {
        return $this->hasOne(OnboardingJourney::class);
    }

    public function availableLeaves()
    {
        $leaves = $this->latestActiveContract()?->leaveBalances()
            ->with(['leaveType', 'contract.position.center'])
            ->get();

        // Pending = hours reserved by pending requests, from the same pricing
        // service as the request preview (A3-D §2.3: one unit, hours). Split
        // into days + hours with the contract's own day length for the card.
        if ($leaves?->count() > 0) {
            $pricing = app(LeaveRequestPricing::class);

            foreach ($leaves as $leave) {
                $pending = $pricing->pendingHoursFor($leave);
                $perDay = $leave->contract ? $pricing->hoursPerDayFor($leave->contract) : null;
                [$days, $rest] = LeaveRequestPricing::splitDays($pending, $perDay);

                $leave->pending_total_hours = $pending;
                $leave->pending_days = $days;
                $leave->pending_hours = $rest;
            }
        }

        return $leaves;
    }

    public function allAvailableLeaves()
    {
        return $this->latestActiveContract()?->leaveBalances()->whereColumn('deserved', '>', 'used')->with('leaveType')->get();
    }

    public function registerMediaCollections(): void
    {
        // Photographs of staff working for a Syrian NGO are protection-sensitive
        // and must never be reachable by URL guessing, so this collection lives
        // on the private disk and is read back through EmployeeAvatarController.
        $this->addMediaCollection('avatar')
            ->useDisk('local')
            ->useFallbackUrl(asset('images/default-avatar.png'))
            ->useFallbackPath(public_path('images/default-avatar.png'))
            ->singleFile();

        $this->addMediaCollection('attachments');
    }

    /**
     * The authorized URL for this employee's photo.
     *
     * Every view uses this instead of getFirstMediaUrl('avatar'), which would
     * return a directly fetchable public path. Requests land on
     * EmployeeAvatarController::internal, which runs EmployeePolicy::view.
     *
     * The cache-busting query segment is derived from the media row so that
     * replacing a photo invalidates the browser cache without weakening the
     * short private max-age on the response.
     */
    public function getAvatarUrlAttribute(): string
    {
        $media = $this->relationLoaded('media')
            ? $this->getMedia('avatar')->first()
            : $this->getFirstMedia('avatar');

        if (! $media) {
            return asset('images/default-avatar.png');
        }

        return route('employees.avatar', [
            'employee' => $this->getKey(),
            'v' => $media->updated_at?->getTimestamp(),
        ]);
    }

    /**
     * A short-lived signed photo URL for the unauthenticated digital ID card.
     *
     * Only ShowDigitalId should call this, and only after the card link has
     * resolved to an active employee's current, enabled card.
     */
    public function signedAvatarUrl(): string
    {
        if (! $this->getFirstMedia('avatar')) {
            return asset('images/default-avatar.png');
        }

        return URL::temporarySignedRoute(
            'digital-id.avatar',
            now()->addMinutes((int) config('uploads.digital_id_avatar_ttl', 10)),
            ['employee' => $this->getKey()],
        );
    }

    /**
     * Start of the person's service. The stored `joined_at` is the
     * authoritative organisational joining date; the earliest contract's
     * start_date is only a fallback for employees whose joining date has not
     * been recorded yet (imported contracts often start well after the person
     * actually joined).
     */
    public function serviceStartDate(): ?Carbon
    {
        if ($this->joined_at) {
            return Carbon::parse($this->joined_at)->startOfDay();
        }

        $earliestContractStart = $this->allContracts()->min('start_date');

        return $earliestContractStart
            ? Carbon::parse($earliestContractStart)->startOfDay()
            : null;
    }

    /**
     * End of the person's service. Someone who has left stops accruing on the
     * day their last contract ended (or the day it was terminated early);
     * everyone still active keeps accruing up to today.
     */
    public function serviceEndDate(): Carbon
    {
        if ($this->is_active) {
            return today();
        }

        $lastContractEnd = $this->allContracts()
            ->select(['end_date', 'terminated_at'])
            ->get()
            ->map(fn (Contract $contract) => $contract->terminated_at ?? $contract->end_date)
            ->filter()
            ->max();

        if (! $lastContractEnd) {
            return today();
        }

        // A contract ending in the future does not accrue service in advance.
        return Carbon::parse($lastContractEnd)->startOfDay()->min(today());
    }

    /**
     * Completed years of service, by default over the person's whole service
     * span. Pass $asOf to get the seniority they had on a given day.
     *
     * Drives the annual leave entitlement — @see \App\Services\LeaveBalanceService
     */
    public function yearsOfServiceOn(Carbon|string|null $asOf = null): int
    {
        $start = $this->serviceStartDate();

        if (! $start) {
            return 0;
        }

        $end = $this->serviceEndDate();

        if ($asOf !== null) {
            $end = Carbon::parse($asOf)->startOfDay()->min($end);
        }

        return $end->lte($start) ? 0 : (int) floor($start->diffInYears($end));
    }

    /**
     * Service length as "3 years 5 months", counted continuously from the
     * joining date — gaps between contracts no longer interrupt it.
     */
    public function getYearsOfServiceFormattedAttribute(): string
    {
        $start = $this->serviceStartDate();
        $end = $this->serviceEndDate();

        if (! $start || $end->lte($start)) {
            return trans_choice('general.count.years', 0);
        }

        $years = $this->yearsOfServiceOn();
        $months = (int) floor($start->copy()->addYears($years)->diffInMonths($end));

        $parts = array_filter([
            $years > 0 ? trans_choice('general.count.years', $years) : null,
            $months > 0 ? trans_choice('general.count.months', $months) : null,
        ]);

        return join_counts($parts) ?: trans_choice('general.count.years', 0);
    }

    public function getYearsOfServiceAttribute(): int
    {
        return $this->yearsOfServiceOn();
    }

    public function abilityToTakeLeave($leaveType)
    {
        return $this->contracts()
            ->whereHas('leaveBalances.leaveRequests', function ($q) use ($leaveType) {
                $q->whereHas('leaveBalance', function ($b) use ($leaveType) {
                    $b->where('type_id', $leaveType);
                })->where('status', 'approved');
            })
            ->exists();

    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('employee')
            // Parents' names, home address, and dates of birth identify a
            // person and their family; for staff of an NGO operating in Syria
            // they are protection-sensitive. Only the fact that they changed is
            // logged — see $sensitiveLogAttributes.
            ->logOnly([
                'name',
                'city_id',
                'board_member_started_at',
                'board_member_end_at',
                'is_active',
                'is_board_member',
                // D1: the owner edits these themself (profile-contract.md §5.1), so who
                // changed them is kept. Not sensitive; contact fields are logged by name only.
                'skills',
                'interests',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected static function booted(): void
    {
        static::creating(function (Employee $employee): void {
            // No longer read by the card (its link is DigitalIdManager's), kept
            // so the previous release still works if it has to be redeployed.
            // A draft gets none (B5-D §5.3); it is issued at activation.
            if (! $employee->isDraft()) {
                $employee->digital_id_token ??= Str::random(64);
            }
        });
    }

    protected function long_fullname(): Attribute
    {
        return Attribute::get(
            fn () => "{$this->first_name} {$this->father_name} {$this->last_name}",
        );
    }

    protected function name(): Attribute
    {
        return Attribute::get(
            fn () => "{$this->first_name} {$this->last_name}",
        );
    }

    /**
     * The organization email — the linked account's login. Anything shown to
     * colleagues uses this and work_phone, never personal_email/personal_phone,
     * which stay on the HR-only private profile section.
     */
    protected function workEmail(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->account?->email);
    }

    protected function firstName(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->getTranslatableAttribute('first_name'),
            set: fn ($value) => $this->setTranslatableAttribute('first_name', $value),
        );
    }

    protected function lastName(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->getTranslatableAttribute('last_name'),
            set: fn ($value) => $this->setTranslatableAttribute('last_name', $value),
        );
    }

    protected function father_name(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->getTranslatableAttribute('father_name'),
            set: fn ($value) => $this->setTranslatableAttribute('father_name', $value),
        );
    }

    protected function mother_name(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->getTranslatableAttribute('mother_name'),
            set: fn ($value) => $this->setTranslatableAttribute('mother_name', $value),
        );
    }

    protected function address(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->getTranslatableAttribute('address'),
            set: fn ($value) => $this->setTranslatableAttribute('address', $value),
        );
    }
}
