<?php

namespace App\Services\Assistant;

use App\Enums\AttachmentCategory;
use App\Enums\Currency;
use App\Enums\LeaveRequestStatus;
use App\Enums\PayMode;
use App\Enums\SalaryPer;
use App\Models\Checklist;
use App\Models\Contract;
use App\Models\ContractPayTier;
use App\Models\Employee;
use App\Models\Folder;
use App\Models\Holiday;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\News;
use App\Models\User;
use App\Services\Assistant\Drafts\DraftRegistry;
use App\Services\LeaveAlternativeEmployees;
use App\Services\LeaveEligibility;
use App\Services\LeaveRequestPricing;
use App\Services\News\NewsFeed;
use App\Services\TeamCoverage;
use App\Services\WorkInbox;
use App\Support\Leave\LeaveQuote;
use App\Support\Navigation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * The functions the assistant may call to read the asking employee's record.
 *
 * Whose record is read always comes from the signed-in user, never from the
 * model, so no wording of a question can point a tool at a colleague. The
 * parameters a tool takes are filters and sizes, clamped here; the one that
 * takes ids, draft_leave_request, takes those the model read from
 * get_leave_options, and the page's own form checks each one against the
 * user's record again (a balance of their latest active contract, an
 * alternative from their allowed list). A tool the user may not use is not
 * declared at all, and call() refuses it again in case the model names it.
 * snapshot() is the same record, in prose, for the system prompt.
 */
class EmployeeTools
{
    // The same list ArchivedFilesModule shows; anything else is never listed there.
    private const ARCHIVE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'svg', 'pdf', 'docx', 'xlsx', 'pptx'];

    private const DESCRIPTIONS = [
        'get_my_profile' => "The employee's own name, positions (title, department, center, city), contract start and end dates and work schedule.",
        'get_my_leave_balances' => "The employee's own leave balances per leave type: entitled, used, pending and remaining.",
        'get_my_leave_requests' => "The employee's own most recent leave requests (15 by default, up to 30): type, start, end, duration and status. Can be filtered by status.",
        'get_my_salary' => "The employee's own pay on their current contract: amount (or pay tiers), currency and period.",
        'get_my_items' => 'The items handed to the employee (laptop, phone, badge, keys…) as the My items page lists them: item, brand, model, serial, status (on hold, delivered, returned) and dates.',
        'get_holidays' => "The public holidays and one-off grants that apply to the employee's own project and governorate in the coming days (default 60, up to 366): name, first and last day, length. The birthday holiday is personal and not included.",
        'get_news' => 'The latest live news, announcements and events addressed to this employee, newest publication first: title, type, publication date, event date and time (events), what it is about and a short excerpt.',
        'get_app_pages' => 'The pages of this app the employee may open, with their menu area and link, plus the "New …" actions they may start. Use it to answer "where do I…", "how do I open…" and to give a link.',
        'get_my_inbox' => 'What waits on the employee in their work inbox right now (leave requests to decide or review, items to hand out or take back, profile changes to approve): counts and the first rows, as the inbox page shows them.',
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
        'get_my_leave_requests' => [
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['pending', 'accepted', 'refused', 'all'], 'description' => 'Only requests with this status. Default: all.'],
                'limit' => ['type' => 'integer', 'description' => 'How many of the most recent requests to return, 1 to 30. Default: 15.'],
            ],
        ],
        'get_holidays' => [
            'type' => 'object',
            'properties' => [
                'days' => ['type' => 'integer', 'description' => 'How many days ahead to look, from today, 1 to 366. Default: 60.'],
            ],
        ],
        'get_news' => [
            'type' => 'object',
            'properties' => [
                'limit' => ['type' => 'integer', 'description' => 'How many of the latest news items to return, 1 to 10. Default: 5.'],
            ],
        ],
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

    // Rows of the My items page and of the inbox sent to the model.
    private const MAX_ITEMS = 50;

    private const MAX_INBOX_ROWS = 10;

    // The snapshot sits in every system prompt, so it stays short.
    private const SNAPSHOT_CHARS = 1200;

    public function __construct(
        private ?LeaveRequestDrafter $drafter = null,
        private ?DraftRegistry $drafts = null,
        private ?HrTools $hr = null,
    ) {
        $this->drafter ??= app(LeaveRequestDrafter::class);
        $this->drafts ??= app(DraftRegistry::class);
        $this->hr ??= app(HrTools::class);
    }

    /**
     * The employee's own tools, then the HR/manager ones (HrTools) and those
     * of the records this user may create from the chat (DraftRegistry),
     * each behind its own permission.
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

        return [...$declarations, ...$this->hr->declarations($user), ...$this->drafts->declarations($user)];
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

        // Scoped by the pages that show the same data; HrTools refuses it again itself.
        if (in_array($name, HrTools::TOOLS, true)) {
            return $this->hr->call($user, $name, $args);
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
            'get_my_leave_requests' => $this->leaveRequests($employee, $args),
            'get_my_salary' => $this->salary($employee),
            'get_my_files' => $this->files($user, $employee),
            'get_my_items' => $this->items($employee),
            'get_holidays' => $this->holidays($employee, $args),
            'get_news' => $this->news($user, $args),
            'get_app_pages' => $this->appPages($user),
            'get_my_inbox' => $this->inbox($user),
            'get_team_absences' => $this->teamAbsences($user, $args),
            'get_leave_options' => $this->leaveOptions($employee),
            'draft_leave_request' => $this->draftLeaveRequest($user, $args),
            default => ['error' => "The tool {$name} is not available to this employee."],
        };
    }

    /**
     * The signed-in employee's own record as a short plain-text block for the
     * system prompt, read now: what the model would otherwise fetch with a
     * tool round for the most common questions (who am I, my contract, my
     * leave left). The same readers as the tools, so it says the same thing.
     * Never pay: that stays behind get_my_salary and config('assistant.share_salary').
     */
    public function snapshot(User $user): string
    {
        $employee = $user->profile;

        if (! $employee) {
            return '';
        }

        $profile = $this->profile($employee);
        $lines = ['Name: '.$profile['name']];

        if (filled($profile['city'])) {
            $lines[] = 'City: '.$profile['city'];
        }

        $contract = $profile['active_contracts'][0] ?? null;

        if ($contract === null) {
            $lines[] = 'Current contract: none active.';
        } else {
            $lines[] = 'Position: '.implode(', ', array_filter([
                $contract['position'] ?? null,
                isset($contract['department']) ? 'department '.$contract['department'] : null,
                isset($contract['center']) ? 'center '.$contract['center'] : null,
                isset($contract['city']) ? 'city '.$contract['city'] : null,
            ]));
            $lines[] = 'Contract: '.implode(', ', array_filter([
                $contract['contract_type'] ?? null,
                isset($contract['start_date']) ? 'from '.$contract['start_date'] : null,
                isset($contract['end_date']) ? 'to '.$contract['end_date'] : null,
            ]));
            $schedule = array_filter([
                $contract['work_days'] ?? null,
                $contract['work_hours'] ?? null,
                isset($contract['second_shift']) ? 'second shift '.$contract['second_shift'] : null,
                isset($contract['weekly_hours']) ? $contract['weekly_hours'].' h/week' : null,
            ]);

            if ($schedule !== []) {
                $lines[] = 'Work schedule: '.implode(', ', $schedule);
            }

            if (count($profile['active_contracts']) > 1) {
                $lines[] = 'Other active contracts: '.(count($profile['active_contracts']) - 1).' (get_my_profile lists them).';
            }
        }

        if ($user->hasActivePermission('leaves.view-own')) {
            $balances = $this->leaveBalances($employee)['balances'];
            $lines[] = $balances === [] ? 'Leave balances: none on the current contract.' : 'Leave balances:';

            foreach ($balances as $balance) {
                $lines[] = sprintf('- %s: remaining %s of %s (pending %s)', $balance['leave_type'], $balance['remaining'], $balance['entitled'], $balance['pending_approval']);
            }
        }

        return Str::limit(implode("\n", $lines), self::SNAPSHOT_CHARS, "\n…");
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
            // The My items page's own gate (route checklist-items.index).
            $user->hasActivePermission('checklists.view-own') ? 'get_my_items' : null,
            // Every signed-in employee sees their holidays (dashboard tile, team calendar).
            'get_holidays',
            // The news page's own gate (route news.index).
            $user->hasActivePermission('news.view') ? 'get_news' : null,
            'get_app_pages',
            // The inbox menu item shows for the same roles (Navigation::inbox).
            app(WorkInbox::class)->hasSources($user) ? 'get_my_inbox' : null,
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

    private function leaveRequests(Employee $employee, array $args = []): array
    {
        $status = match (strtolower(trim((string) ($args['status'] ?? 'all')))) {
            'pending' => LeaveRequestStatus::Pending,
            'accepted', 'approved' => LeaveRequestStatus::Accepted,
            'refused', 'rejected' => LeaveRequestStatus::Rejected,
            default => null,
        };
        $limit = min(max((int) ($args['limit'] ?? 15) ?: 15, 1), 30);

        // Same ownership rule as MyLeaveRequestModule: through the balance's contract.
        $requests = LeaveRequest::query()
            ->whereHas('leaveBalance.contract', fn (Builder $query) => $query->where('employee_id', $employee->id))
            ->when($status !== null, fn (Builder $query) => $query->where('status', $status))
            ->with(['leaveBalance.leaveType', 'leaveBalance.contract.position.center'])
            ->latest()
            ->limit($limit)
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
     * The balances the page's request form offers (LeaveEligibility:
     * what the employee may request now) and the alternatives
     * LeaveAlternativeEmployees allows. The form validates against the same.
     */
    private function leaveOptions(Employee $employee): array
    {
        $balances = app(LeaveEligibility::class)->requestableBalances($employee);
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

    /** The same rows and the same status words as the My items page (AllItems). */
    private function items(Employee $employee): array
    {
        $items = Checklist::query()
            ->with('item')
            ->where('employee_id', $employee->id)
            ->latest('updated_at')
            ->limit(self::MAX_ITEMS + 1)
            ->get();

        return [
            'items' => $items->take(self::MAX_ITEMS)->map(fn (Checklist $row) => array_filter([
                'item' => $row->item?->name,
                'brand' => $row->brand,
                'model' => $row->model,
                'serial' => $row->serial,
                'status' => match (true) {
                    $row->returned_status === 'received' => 'returned',
                    $row->delivered_status === 'delivered' => 'delivered',
                    default => 'on hold',
                },
                'delivered_at' => $row->delivered_at?->format('Y-m-d'),
                'returned_at' => $row->returned_at?->format('Y-m-d'),
            ], fn ($value) => filled($value)))->values()->all(),
            'truncated' => $items->count() > self::MAX_ITEMS ?: null,
            'note' => $items->isEmpty() ? 'No item is recorded for the employee.' : null,
        ];
    }

    /**
     * The holidays of the employee's own project and governorate, on the
     * reader the dashboard tile and the leave quote use (LeaveRequestPricing):
     * national ones for everyone, project ones for their program × city.
     */
    private function holidays(Employee $employee, array $args): array
    {
        $days = min(max((int) ($args['days'] ?? 60) ?: 60, 1), 366);
        $position = $employee->latestActiveContract()?->position;
        $cityId = $position?->center?->city_id ?? $position?->city_id;
        $from = CarbonImmutable::today();
        $to = $from->addDays($days);

        $byDate = app(LeaveRequestPricing::class)->holidaysOn(
            $position?->program_id ? (int) $position->program_id : null,
            $cityId !== null ? (int) $cityId : null,
            $from,
            $to,
        );

        // One line per holiday, with the run of dates it covers in the window.
        $holidays = [];

        foreach ($byDate as $date => $rows) {
            /** @var Holiday $holiday */
            foreach ($rows as $holiday) {
                $holidays[$holiday->id] ??= [
                    'name' => (string) $holiday->name,
                    'kind' => $holiday->kind,
                    'from' => $date,
                    'to' => $date,
                    'days' => 0,
                ];
                $holidays[$holiday->id]['to'] = $date;
                $holidays[$holiday->id]['days']++;
            }
        }

        $holidays = collect($holidays)->sortBy('from')->values()->all();

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'holidays' => $holidays,
            'note' => $holidays === [] ? 'No holiday in this window for the employee.' : null,
        ];
    }

    /**
     * The live news, announcements and events the user is in the audience
     * of (NewsFeed::live: no drafts, scheduled or expired items), newest
     * publication first. 'about' is the Related To subject, not the audience.
     */
    private function news(User $user, array $args): array
    {
        $limit = min(max((int) ($args['limit'] ?? 5) ?: 5, 1), 10);

        $news = app(NewsFeed::class)->live($user)
            ->orderByDesc('news.published_at')
            ->orderByDesc('news.id')
            ->limit($limit)
            ->get();

        return [
            'news' => $news->map(fn (News $item) => array_filter([
                'title' => $item->localizedTitle(),
                'type' => $item->type->value,
                'published' => $item->published_at?->format('Y-m-d'),
                'event_date' => $item->isEvent() ? $item->event_starts_at?->format('Y-m-d H:i') : null,
                'event_ends' => $item->isEvent() ? $item->event_ends_at?->format('Y-m-d H:i') : null,
                'about' => $item->newsable_name,
                // At most 300 characters, ellipsis included.
                'excerpt' => Str::limit($this->plain($item->localizedDescription()), 299, '…'),
            ], fn ($value) => filled($value)))->values()->all(),
            'note' => $news->isEmpty() ? 'No published news.' : null,
        ];
    }

    /**
     * The active role's menu (Navigation::pages) and New actions, each with
     * its link. The menu shows nothing the role may not open, and every page
     * still checks its own middleware on the visit.
     */
    private function appPages(User $user): array
    {
        $deliverResponsible = Navigation::isDeliverResponsible($user);

        $pages = collect(Navigation::pages($user, $deliverResponsible))
            ->map(fn (array $page) => array_filter([
                'label' => $page['label'],
                'area' => $page['area'] ?: null,
                'url' => $this->url($page['route']),
            ]))
            ->filter(fn (array $page) => isset($page['url']))
            ->values()
            ->all();

        $actions = collect(Navigation::newActions($user))
            ->map(fn (array $action) => array_filter([
                'label' => $action['label'],
                'url' => $this->url($action['route'], $action['params']),
            ]))
            ->filter(fn (array $action) => isset($action['url']))
            ->values()
            ->all();

        return ['pages' => $pages, 'new_actions' => $actions];
    }

    /**
     * The inbox page's own rows and counts (WorkInbox), at the level the page
     * shows them: who, what and since when; never a reason, pay or id number.
     */
    private function inbox(User $user): array
    {
        $snapshot = app(WorkInbox::class)->snapshot($user);
        $locale = app()->getLocale();

        $rows = $snapshot['rows']->take(self::MAX_INBOX_ROWS)->map(function (array $row) use ($locale) {
            $subject = $row['subject'];
            $who = $subject instanceof Employee ? $subject->nameForLocale($locale) : (string) ($subject?->name ?? '');

            return array_filter([
                'action' => __("inbox.action.{$row['action']}"),
                'who' => $who,
                'former_employee' => ($row['former'] ?? false) ?: null,
                'what' => $row['source'] === 'leave' ? $row['leave_type'] : $row['item'],
                'from' => $row['source'] === 'leave' ? $row['start_at']?->format($row['type'] === 'hour' ? 'Y-m-d H:i' : 'Y-m-d') : null,
                'to' => $row['source'] === 'leave' ? $row['end_at']?->format($row['type'] === 'hour' ? 'Y-m-d H:i' : 'Y-m-d') : null,
                'duration' => $row['source'] === 'leave' ? $this->plain($row['duration']) : null,
                'city' => $row['city'] ?? null,
                'waiting_since' => $row['waiting_since']?->format('Y-m-d'),
                'urgent' => ($row['urgent'] ?? false) ?: null,
            ], fn ($value) => filled($value));
        })->values()->all();

        return [
            'counts' => $snapshot['counts'],
            'rows' => $rows,
            'truncated' => $snapshot['counts']['all'] > count($rows) ?: null,
            'url' => $this->url('inbox'),
            'note' => $rows === [] ? 'Nothing is waiting on the employee.' : null,
        ];
    }

    private function url(string $route, array $params = []): ?string
    {
        try {
            return route($route, $params);
        } catch (Throwable) {
            return null;
        }
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
