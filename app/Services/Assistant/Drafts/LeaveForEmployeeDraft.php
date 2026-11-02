<?php

namespace App\Services\Assistant\Drafts;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\User;
use App\Support\Leave\LeaveQuote;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A leave request HR files for an employee, as from the All leaves page's
 * Add dialog (leaves.all?new=1).
 *
 * Offered only under the page's own rule (LeaveForEmployeeScreen::allows()).
 * Every employee id from the model is checked against the page's own
 * dropdown (the HR role's cities) on each call, and the form checks it
 * again; the balance and alternative ids are checked by the form against
 * that employee. Only the user's Confirm creates the request.
 */
class LeaveForEmployeeDraft implements RecordDraft
{
    // The most employees one search returns.
    private const MAX_MATCHES = 10;

    // The longest list of alternatives sent to the model.
    private const MAX_ALTERNATIVES = 60;

    private const DRAFT_PARAMETERS = [
        'type' => 'object',
        'properties' => [
            'employee_id' => ['type' => 'integer', 'description' => 'The id of the employee the leave is for, from find_employee_for_leave.'],
            'leave_balance_id' => ['type' => 'integer', 'description' => 'The id of one of that employee\'s balances, from get_employee_leave_options.'],
            'type' => ['type' => 'string', 'enum' => ['day', 'hour'], 'description' => 'day for whole days; hour for part of one day (only for balances where hours_allowed is true).'],
            'start' => ['type' => 'string', 'description' => 'First day as YYYY-MM-DD, or for an hourly leave the day and start time as YYYY-MM-DD HH:MM (24-hour).'],
            'end' => ['type' => 'string', 'description' => 'Last day as YYYY-MM-DD (the same as start for one day), or for an hourly leave the same day and end time as YYYY-MM-DD HH:MM.'],
            'reason' => ['type' => 'string', 'description' => 'The reason for the leave. Required by the leave rules: ask the user for it if it is missing.'],
            'alternative_employee_id' => ['type' => 'integer', 'description' => 'Optional: the id of the colleague covering, only from the alternatives listed by get_employee_leave_options.'],
            'is_exceptional' => ['type' => 'boolean', 'description' => 'Only for leave in December, when the user explicitly asks for an exceptional December leave (it needs manager and HR approval).'],
        ],
        'required' => ['employee_id', 'leave_balance_id', 'type', 'start', 'end'],
    ];

    public function __construct(private LeaveForEmployeeDrafter $drafter) {}

    public function type(): string
    {
        return 'leave_for_employee';
    }

    public function declarations(User $user): array
    {
        // The page lists employees only for an HR-* role (super-admin sees none),
        // so the tools are not offered where every search would come back empty.
        if (! LeaveForEmployeeScreen::allows($user) || ! $user->activeRoleIs('HR')) {
            return [];
        }

        return [
            [
                'name' => 'find_employee_for_leave',
                'description' => 'Finds employees the user may file a leave request for (HR, from the All leaves page), by name in English or Arabic. Returns at most '.self::MAX_MATCHES.' matches with id, name, position and city. Use it to get the employee_id; if several match, ask the user which one.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'The employee\'s name, or part of it.'],
                    ],
                    'required' => ['name'],
                ],
            ],
            [
                'name' => 'get_employee_leave_options',
                'description' => 'What a leave request for that employee can use: the employee\'s leave balances on their current contract (id, leave type, remaining, whether it can be taken by the hour) and the colleagues who may cover for them (optional). Call it before drafting.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'employee_id' => ['type' => 'integer', 'description' => 'The id from find_employee_for_leave.'],
                    ],
                    'required' => ['employee_id'],
                ],
            ],
            [
                'name' => 'draft_leave_request_for_employee',
                'description' => 'Checks a new leave request for another employee with every rule of the All leaves page (balance, dates, overlap, December, working hours) and prepares it for the user to confirm. It never submits anything. Returns either the errors to tell the user, or the checked draft. Only the user can submit it, with the Confirm button shown under your answer.',
                'parameters' => self::DRAFT_PARAMETERS,
            ],
        ];
    }

    public function call(User $user, string $tool, array $args): array
    {
        if (! LeaveForEmployeeScreen::allows($user)) {
            return ['error' => "The tool {$tool} is not available."];
        }

        return match ($tool) {
            'find_employee_for_leave' => $this->drafter->asUser($user, fn () => $this->find((string) ($args['name'] ?? ''))),
            'get_employee_leave_options' => $this->drafter->asUser($user, fn () => $this->options($args['employee_id'] ?? null)),
            'draft_leave_request_for_employee' => $this->draft($user, $args),
            default => ['error' => "The tool {$tool} is not available."],
        };
    }

    public function card(array $proposal): array
    {
        return [
            'title' => __('assistant-leave-for.title'),
            'icon' => 'calendar',
            'rows' => array_filter([
                __('assistant-leave-for.fields.employee') => $proposal['employee'] ?? null,
                __('assistant-leave-for.fields.type') => $proposal['leave_type'] ?? null,
                __('assistant-leave-for.fields.from') => $proposal['start'] ?? null,
                __('assistant-leave-for.fields.to') => $proposal['end'] ?? null,
                __('assistant-leave-for.fields.duration') => $proposal['duration'] ?? null,
                __('assistant-leave-for.fields.reason') => $proposal['reason'] ?? null,
                __('assistant-leave-for.fields.alternative') => $proposal['alternative_employee'] ?? null,
            ], fn ($value) => filled($value)),
        ];
    }

    public function submit(User $user, array $proposal, string $token): array
    {
        // The page's own gates, again: the role or a permission may have gone
        // since the draft was shown.
        if (! LeaveForEmployeeScreen::allows($user)) {
            throw ValidationException::withMessages(['permission' => __('assistant.draft.forbidden')]);
        }

        try {
            // Re-checks the employee against the page's dropdown, then
            // validates and writes through the page's store().
            [$leaveRequest] = $this->drafter->store($user, $proposal, $token);
        } catch (ValidationException $e) {
            if (array_key_exists('form.employee_id', $e->errors())) {
                throw ValidationException::withMessages(['permission' => __('assistant.draft.forbidden')]);
            }

            throw $e;
        }

        // The page's processSave() sends no notification for a request HR
        // files, so neither does this.
        return [
            'message' => __('assistant-leave-for.submitted', ['name' => (string) ($proposal['employee'] ?? '')]),
            'url' => route('leaves.all', ['leave' => $leaveRequest->id]),
            'link' => __('assistant-leave-for.open'),
        ];
    }

    /** @return array<string, mixed> */
    private function find(string $name): array
    {
        $terms = array_values(array_filter(preg_split('/\s+/u', Str::lower(trim($name))) ?: []));

        if ($terms === []) {
            return ['employees' => [], 'note' => 'Give part of the employee\'s name.'];
        }

        $matches = (new LeaveForEmployeeScreen)->employees()->filter(function (Employee $employee) use ($terms): bool {
            $haystack = Str::lower(implode(' ', [
                $employee->first_name_en, $employee->last_name_en,
                $employee->first_name_ar, $employee->last_name_ar,
                $employee->username,
            ]));

            foreach ($terms as $term) {
                if (! str_contains($haystack, $term)) {
                    return false;
                }
            }

            return true;
        })->values();

        $locale = app()->getLocale();

        return array_filter([
            'employees' => $matches->take(self::MAX_MATCHES)->map(function (Employee $employee) use ($locale): array {
                $position = $employee->latestActiveContract()?->position;

                return array_filter([
                    'id' => (int) $employee->id,
                    'name' => $employee->nameForLocale($locale),
                    'position' => $position?->title,
                    'city' => $employee->city?->name,
                    'has_active_contract' => $position !== null,
                ], fn ($value) => $value !== null);
            })->all(),
            'truncated' => $matches->count() > self::MAX_MATCHES ?: null,
            'note' => $matches->isEmpty() ? 'No employee you may file leave for matches this name.' : null,
        ], fn ($value) => $value !== null);
    }

    /** @return array<string, mixed> */
    private function options(mixed $employeeId): array
    {
        $screen = new LeaveForEmployeeScreen;
        $employee = $screen->employee($employeeId);

        if (! $employee) {
            return ['error' => __('assistant-leave-for.errors.employee')];
        }

        $balances = $screen->balances($employee);
        $alternatives = $screen->alternatives($employee);
        $locale = app()->getLocale();

        return array_filter([
            'employee' => $employee->nameForLocale($locale),
            'balances' => $balances->map(fn (LeaveBalance $balance) => [
                'id' => (int) $balance->id,
                'leave_type' => $balance->leaveType?->name,
                'remaining' => $this->plain($balance->remaining),
                'hours_allowed' => $balance->isAnnual(),
            ])->values()->all(),
            'note' => $balances->isEmpty() ? 'This employee has no leave balance on a current contract; a leave cannot be requested for them.' : null,
            'alternative_employee_optional' => true,
            'alternatives' => $alternatives->take(self::MAX_ALTERNATIVES)->map(fn (Employee $candidate) => [
                'id' => (int) $candidate->id,
                'name' => $candidate->nameForLocale($locale),
            ])->values()->all(),
            'alternatives_truncated' => $alternatives->count() > self::MAX_ALTERNATIVES ?: null,
        ], fn ($value) => $value !== null);
    }

    /** @return array<string, mixed> */
    private function draft(User $user, array $args): array
    {
        try {
            ['proposal' => $proposal, 'quote' => $quote] = $this->drafter->draft($user, $args);
        } catch (ValidationException $e) {
            return [
                'status' => 'invalid',
                'errors' => array_values(array_unique(array_merge(...array_values($e->errors())))),
                'note' => 'Nothing was drafted. Tell the user what to change, then draft again.',
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
            'note' => 'Not created. Summarise the draft and ask the user to press Confirm below your answer.',
        ];
    }

    private function plain(?string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $text))) ?? '');
    }
}
