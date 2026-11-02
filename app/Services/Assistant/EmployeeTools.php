<?php

namespace App\Services\Assistant;

use App\Enums\AttachmentCategory;
use App\Enums\Currency;
use App\Enums\LeaveRequestStatus;
use App\Enums\PayMode;
use App\Enums\SalaryPer;
use App\Models\Contract;
use App\Models\ContractPayTier;
use App\Models\Employee;
use App\Models\Folder;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Assistant\Drafts\DraftRegistry;
use App\Services\LeaveAlternativeEmployees;
use App\Services\TeamCoverage;
use App\Support\Leave\LeaveQuote;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * The functions the assistant may call to read the asking employee's record.
 *
 * Whose record is read always comes from the signed-in user, never from the
 * model, so no wording of a question can point a tool at a colleague. The
 * only tool with parameters, draft_leave_request, takes ids the model read
 * from get_leave_options, and the page's own form checks each one against
 * the user's record again (a balance of their latest active contract, an
 * alternative from their allowed list). A tool the user may not use is not
 * declared at all, and call() refuses it again in case the model names it.
 */
class EmployeeTools
{
    // The same list ArchivedFilesModule shows; anything else is never listed there.
    private const ARCHIVE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'svg', 'pdf', 'docx', 'xlsx', 'pptx'];

    private const DESCRIPTIONS = [
        'get_my_profile' => "The employee's own name, positions (title, department, center, city), contract start and end dates and work schedule.",
        'get_my_leave_balances' => "The employee's own leave balances per leave type: entitled, used, pending and remaining.",
        'get_my_leave_requests' => "The employee's own 15 most recent leave requests: type, start, end, duration and status.",
        'get_my_salary' => "The employee's own pay on their current contract: amount (or pay tiers), currency and period.",
        'get_team_absences' => 'Who is away on APPROVED leave on a day or between two dates, among the people this user may see on the team calendar (the same people and the same details): name, position, dates and leave type. Pending or refused requests are never included, and nothing else about anyone (no reason, balance or pay).',
        'get_my_files' => 'Names, categories and dates of the files in the employee\'s own record and in the archive folders shared with them. No file contents.',
        'get_leave_options' => 'What the employee can request a new leave from: their own leave balances (id, leave type, remaining, whether it can be taken by the hour) and the colleagues they may name as their alternative while away (optional). Call it before drafting a leave request.',
        'draft_leave_request' => 'Checks a new leave request of the employee with every rule of the leave page (balance, dates, overlap, December, working hours) and prepares it for the employee to confirm. It never submits anything. Returns either the errors to tell the employee, or the checked draft. Only the employee can submit it, with the Confirm button shown under your answer.',
    ];

    /**
     * Gemini parameter schemas (OpenAPI subset) of the tools that take any.
     * The values are only a request: every id is checked against the asking
     * employee's own record before anything is read or drafted.
     */
    private const PARAMETERS = [
        'get_team_absences' => [
            'type' => 'object',
            'properties' => [
                'from' => ['type' => 'string', 'description' => 'First day as YYYY-MM-DD. Default: today.'],
                'to' => ['type' => 'string', 'description' => 'Last day as YYYY-MM-DD (at most 31 days after from). Default: the same as from.'],
                // The enum is the user's own scopes, set in declarations().
                'scope' => ['type' => 'string', 'description' => 'Whose leave: one of the listed teams. Default: the team the calendar opens on.'],
            ],
        ],
        'draft_leave_request' => [
            'type' => 'object',
            'properties' => [
                'leave_balance_id' => ['type' => 'integer', 'description' => 'The id of one of the employee\'s own balances, from get_leave_options.'],
                'type' => ['type' => 'string', 'enum' => ['day', 'hour'], 'description' => 'day for whole days; hour for part of one day (only for balances where hours_allowed is true).'],
                'start' => ['type' => 'string', 'description' => 'First day as YYYY-MM-DD, or for an hourly leave the day and start time as YYYY-MM-DD HH:MM (24-hour).'],
                'end' => ['type' => 'string', 'description' => 'Last day as YYYY-MM-DD (the same as start for one day), or for an hourly leave the same day and end time as YYYY-MM-DD HH:MM.'],
                'reason' => ['type' => 'string', 'description' => 'The reason in the employee\'s own words. Required by the leave rules: ask for it if it is missing.'],
                'alternative_employee_id' => ['type' => 'integer', 'description' => 'Optional: the id of the colleague covering, only from the alternatives listed by get_leave_options.'],
                'is_exceptional' => ['type' => 'boolean', 'description' => 'Only for leave in December, when the employee explicitly asks for an exceptional December leave (it needs manager and HR approval).'],
            ],
            'required' => ['leave_balance_id', 'type', 'start', 'end'],
        ],
    ];

    // The longest list of alternatives sent to the model.
    private const MAX_ALTERNATIVES = 60;

    public function __construct(
        private ?LeaveRequestDrafter $drafter = null,
        private ?DraftRegistry $drafts = null,
    ) {
        $this->drafter ??= app(LeaveRequestDrafter::class);
        $this->drafts ??= app(DraftRegistry::class);
    }

    /**
     * The employee's own tools, then those of the records this user may
     * create from the chat (DraftRegistry), each behind its own permission.
     *
     * @return list<array{name: string, description: string, parameters?: array<string, mixed>}>
     */
    public function declarations(User $user): array
    {
        $declarations = array_map(
            fn (string $name) => ['name' => $name, 'description' => self::DESCRIPTIONS[$name]]
                + (isset(self::PARAMETERS[$name]) ? ['parameters' => self::PARAMETERS[$name]] : []),
            $this->available($user),
        );

        foreach ($declarations as $i => $declaration) {
            if ($declaration['name'] === 'get_team_absences') {
                $declarations[$i]['parameters']['properties']['scope']['enum'] = app(TeamCoverage::class)->scopesFor($user);
            }
        }

        return [...$declarations, ...$this->drafts->declarations($user)];
    }

    /**
     * @param  array<string, mixed>  $args  the model's arguments, untrusted
     * @return array<string, mixed>
     */
    public function call(User $user, string $name, array $args = []): array
    {
        if ($name === DraftRegistry::CHOICE_TOOL) {
            return $this->drafts->offerChoice($user, $args);
        }

        if (! in_array($name, $this->available($user), true) && $draft = $this->drafts->owner($user, $name)) {
            $result = $draft->call($user, $name, $args);

            return ($result['status'] ?? null) === 'drafted' ? $result + ['draft_type' => $draft->type()] : $result;
        }

        $employee = $user->profile;

        if (! $employee || ! in_array($name, $this->available($user), true)) {
            return ['error' => "The tool {$name} is not available to this employee."];
        }

        return match ($name) {
            'get_my_profile' => $this->profile($employee),
            'get_my_leave_balances' => $this->leaveBalances($employee),
            'get_my_leave_requests' => $this->leaveRequests($employee),
            'get_my_salary' => $this->salary($employee),
            'get_my_files' => $this->files($user, $employee),
            'get_team_absences' => $this->teamAbsences($user, $args),
            'get_leave_options' => $this->leaveOptions($employee),
            'draft_leave_request' => $this->draftLeaveRequest($user, $args),
        };
    }

    /**
     * Approved leave of the people the user may see on the team calendar, on
     * the calendar's own reader (TeamCoverage): the same people, scopes and
     * details, at the same level (a colleague sees a neutral type for
     * sensitive leave). Pending requests are left out even for a manager.
     */
    private function teamAbsences(User $user, array $args): array
    {
        $coverage = app(TeamCoverage::class);
        $scopes = $coverage->scopesFor($user);
        $scope = in_array($args['scope'] ?? null, $scopes, true) ? $args['scope'] : $coverage->defaultScope($user);

        try {
            $from = filled($args['from'] ?? null) ? CarbonImmutable::createFromFormat('!Y-m-d', (string) $args['from']) : CarbonImmutable::today();
            $to = filled($args['to'] ?? null) ? CarbonImmutable::createFromFormat('!Y-m-d', (string) $args['to']) : $from;
        } catch (Throwable) {
            return ['error' => 'Dates must be YYYY-MM-DD.'];
        }

        if (! $from || ! $to || $to->lt($from) || $from->diffInDays($to) > 31) {
            return ['error' => 'Give a from date and a to date at most 31 days later, as YYYY-MM-DD.'];
        }

        $away = $coverage->absences($user, $scope, $from, $to, ['status' => 'accepted'])
            ->reject(fn (array $entry) => $entry['is_self'] || $entry['status'] === 'pending')
            ->sortBy([['from', 'asc'], ['name', 'asc']])
            ->map(fn (array $entry) => array_filter([
                'name' => $entry['name'],
                'position' => $entry['position'] ?: null,
                'from' => $entry['from'],
                'to' => $entry['to'],
                'type' => $entry['type'],
                'hours' => $entry['hours'],
            ], fn ($value) => $value !== null))
            ->values();

        return [
            'scope' => __('team_calendar.scopes.'.$scope),
            'other_scopes' => array_values(array_diff($scopes, [$scope])),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'away' => $away->take(60)->all(),
            'truncated' => $away->count() > 60 ?: null,
            'note' => $away->isEmpty() ? 'Nobody the user may see is on approved leave then.' : null,
        ];
    }

    /** @return list<string> */
    private function available(User $user): array
    {
        if (! $user->profile) {
            return [];
        }

        $canSeeLeaves = $user->hasActivePermission('leaves.view-own');
        // The same permission the My leaves page requires for a new request.
        $canRequestLeave = $user->hasActivePermission('leaves.create');

        return array_values(array_filter([
            'get_my_profile',
            $canSeeLeaves ? 'get_my_leave_balances' : null,
            $canSeeLeaves ? 'get_my_leave_requests' : null,
            config('assistant.share_salary') ? 'get_my_salary' : null,
            'get_my_files',
            // The team calendar's own gate (route team.calendar).
            $canSeeLeaves ? 'get_team_absences' : null,
            $canRequestLeave ? 'get_leave_options' : null,
            $canRequestLeave ? 'draft_leave_request' : null,
        ]));
    }

    private function profile(Employee $employee): array
    {
        $locale = app()->getLocale();
        $contracts = $employee->contracts()
            ->with(['position.department', 'position.center.city', 'position.city', 'contractType'])
            ->latest('start_date')
            ->get();

        return [
            'name' => $employee->nameForLocale($locale),
            'city' => $employee->city?->name,
            'active_contracts' => $contracts->map(fn (Contract $contract) => array_filter([
                'position' => $contract->position?->title,
                'department' => $contract->position?->department?->name,
                'center' => $contract->position?->center?->name,
                'city' => ($contract->position?->center?->city ?? $contract->position?->city)?->name,
                'contract_type' => $contract->contractType?->{"name_{$locale}"},
                'start_date' => $contract->start_date?->format('Y-m-d'),
                'end_date' => $contract->end_date?->format('Y-m-d'),
                'weekly_hours' => $contract->weekly_hours,
                'work_days' => $contract->{"work_days_{$locale}"},
                'work_hours' => $this->hours($contract->work_hours_from, $contract->work_hours_to),
                'second_shift' => $this->hours($contract->work_hours_2_from, $contract->work_hours_2_to),
            ], fn ($value) => filled($value)))->values()->all(),
        ];
    }

    private function leaveBalances(Employee $employee): array
    {
        $balances = $employee->availableLeaves();

        if (! $balances || $balances->isEmpty()) {
            return ['balances' => [], 'note' => 'No leave balances on the current contract.'];
        }

        return ['balances' => $balances->map(fn (LeaveBalance $balance) => [
            'leave_type' => $balance->leaveType?->name,
            'entitled' => $this->plain($balance->deserved_formatted),
            'used' => $this->plain($balance->used_formatted),
            'pending_approval' => $this->plain($balance->getFormattedLabel($balance->pending_days ?? 0, $balance->pending_hours ?? 0)),
            'remaining' => $this->plain($balance->remaining),
        ])->values()->all()];
    }

    private function leaveRequests(Employee $employee): array
    {
        // Same ownership rule as MyLeaveRequestModule: through the balance's contract.
        $requests = LeaveRequest::query()
            ->whereHas('leaveBalance.contract', fn (Builder $query) => $query->where('employee_id', $employee->id))
            ->with(['leaveBalance.leaveType', 'leaveBalance.contract.position.center'])
            ->latest()
            ->limit(15)
            ->get();

        return ['requests' => $requests->map(fn (LeaveRequest $request) => [
            'leave_type' => $request->leaveBalance?->leaveType?->name,
            'unit' => $request->type,
            'start' => $request->type === 'hour' ? $request->start_at?->format('Y-m-d H:i') : $request->start_at?->format('Y-m-d'),
            'end' => $request->type === 'hour' ? $request->end_at?->format('Y-m-d H:i') : $request->end_at?->format('Y-m-d'),
            'duration' => $this->plain($request->duration),
            'status' => LeaveRequestStatus::of($request->status)->label(),
        ])->values()->all()];
    }

    /**
     * The balances the page's request form offers (allAvailableLeaves: the
     * latest active contract's, with something left) and the alternatives
     * LeaveAlternativeEmployees allows. The form validates against the same.
     */
    private function leaveOptions(Employee $employee): array
    {
        $balances = $employee->allAvailableLeaves() ?? collect();
        $alternatives = app(LeaveAlternativeEmployees::class)->for($employee);
        $locale = app()->getLocale();

        return array_filter([
            'balances' => $balances->map(fn (LeaveBalance $balance) => [
                'id' => $balance->id,
                'leave_type' => $balance->leaveType?->name,
                'remaining' => $this->plain($balance->remaining),
                'hours_allowed' => $balance->isAnnual(),
            ])->values()->all(),
            'note' => $balances->isEmpty() ? 'No balance on the current contract can be requested from; the employee should contact HR.' : null,
            'alternative_employee_optional' => true,
            'alternatives' => $alternatives->take(self::MAX_ALTERNATIVES)->map(fn (Employee $candidate) => [
                'id' => $candidate->id,
                'name' => $candidate->nameForLocale($locale),
            ])->values()->all(),
            'alternatives_truncated' => $alternatives->count() > self::MAX_ALTERNATIVES ?: null,
        ], fn ($value) => $value !== null);
    }

    /**
     * Never writes. On success the result carries the proposal Assistant
     * hands to the page (AssistantReply::$leaveProposal).
     */
    private function draftLeaveRequest(User $user, array $args): array
    {
        try {
            ['proposal' => $proposal, 'quote' => $quote] = $this->drafter->draft($user, $args);
        } catch (ValidationException $e) {
            return [
                'status' => 'invalid',
                'errors' => LeaveRequestDrafter::messages($e),
                'note' => 'Nothing was drafted. Tell the employee what to change, then draft again.',
            ];
        }

        return [
            'status' => 'drafted',
            'proposal' => $proposal,
            'counted_days' => $quote?->countedDays,
            'balance_after' => $quote
                ? ($quote->projectedHours < 0 ? '-' : '').$this->drafter->formatSplit(abs($quote->projectedHours), $quote->hoursPerDay)
                : null,
            'warnings' => $quote ? array_map(fn (string $code) => __(LeaveQuote::messageKey($code)), $quote->warnings) : [],
            'note' => 'Not submitted. Summarise the draft and ask the employee to review it and press Confirm below your answer.',
        ];
    }

    private function salary(Employee $employee): array
    {
        $contract = $employee->latestActiveContract();

        if (! $contract) {
            return ['salary' => null, 'note' => 'The employee has no active contract.'];
        }

        $locale = app()->getLocale();
        $result = [
            'position' => $contract->position?->title,
            'currency' => Currency::getLocalizedName((int) $contract->currency)[$locale] ?? null,
            'per' => SalaryPer::getLocalizedName((int) $contract->salary_per)['value'],
        ];

        if ((int) $contract->pay_mode === PayMode::Flexible) {
            $tiers = $contract->payTiers()->get();
            // A null amount is a figure this host cannot decrypt, not a zero.
            $result['pay_tiers'] = $tiers->map(fn (ContractPayTier $tier) => [
                'condition' => $tier->condition,
                'amount' => $tier->amount,
            ])->values()->all();

            if ($tiers->isEmpty() || $tiers->contains(fn (ContractPayTier $tier) => $tier->amount === null)) {
                $result['note'] = 'Some or all pay figures are unavailable here; the employee should ask HR.';
            }

            return $result;
        }

        $result['salary'] = $contract->salary;

        if ($contract->salary === null) {
            $result['note'] = 'The pay figure is unavailable here; the employee should ask HR.';
        }

        return $result;
    }

    private function files(User $user, Employee $employee): array
    {
        $result = [
            'my_record_files' => $employee->employeeAttachments()->map(fn (Media $media) => array_filter([
                'name' => $media->getCustomProperty('name') ?: $media->name,
                'category' => AttachmentCategory::tryFrom((string) $media->getCustomProperty('category'))?->label(),
                'added' => $media->created_at?->format('Y-m-d'),
            ]))->values()->all(),
        ];

        // Only folders the user was explicitly given (folder_user), as on the
        // shared-folders page, which itself needs archives.view.
        if ($user->hasActivePermission('archives.view')) {
            $result['shared_folders'] = $user->accessibleFolders()
                ->with('media')
                ->orderBy('name')
                ->get()
                ->map(fn (Folder $folder) => [
                    'folder' => $folder->name,
                    'files' => $folder->getMedia('archived_files')
                        ->filter(fn (Media $media) => in_array(strtolower((string) $media->extension), self::ARCHIVE_EXTENSIONS, true))
                        ->map(fn (Media $media) => [
                            'name' => $media->file_name,
                            'added' => $media->created_at?->format('Y-m-d'),
                        ])->values()->all(),
                ])->values()->all();
        }

        return $result;
    }

    private function hours(?string $from, ?string $to): ?string
    {
        return $from && $to ? substr($from, 0, 5).'–'.substr($to, 0, 5) : null;
    }

    /** The formatted labels carry <strong> markup for the cards. */
    private function plain(?string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $text))) ?? '');
    }
}
