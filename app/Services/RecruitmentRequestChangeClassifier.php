<?php

namespace App\Services;

use App\Enums\WorkflowStepKey;
use App\Models\RecruitmentRequestVersion;
use DateTimeInterface;

/**
 * How severely an edit invalidates the approvals already given (RV-1).
 *
 * Comparing version N+1 to version N field by field answers one question: how
 * far back does the workflow have to go. Moving the position to another
 * programme is not the same as rewording the duties, and asking three people
 * to approve the second again wastes their time as surely as skipping them on
 * the first would waste the approval.
 *
 * Every editable field is classified. RV-1 is explicit that an unclassified
 * field means a change that quietly invalidates nothing when it should have
 * invalidated something, so a field added to the request details has to be
 * listed here before it ships.
 */
class RecruitmentRequestChangeClassifier
{
    /** Nothing changed. */
    public const NOTHING = 0;

    /** Wording. Nothing is invalidated; the workflow resumes where it stopped. */
    public const DESCRIPTIVE = 1;

    /** Money. Financial review and everything after it are invalidated. */
    public const FINANCIAL = 2;

    /** Structure. Every approval is invalidated; the workflow starts over. */
    public const SUBSTANTIVE = 3;

    /**
     * Columns on recruitment_request_position_details, by category.
     *
     * direct_supervisor_position_id is descriptive, not substantive, and that
     * is worth saying plainly because it looks structural. It names the parent
     * proposed *for the new post*. It does not route the request: the manager
     * who approves is found from the requester's own chair
     * (`position($user)->directManager`), never from this field. So changing it
     * invalidates nobody's approval, and restarting the workflow over it would
     * send three people round again for a decision none of them made.
     *
     * @var array<int, array<int, string>>
     */
    private const DETAIL_FIELDS = [
        self::SUBSTANTIVE => [
            'position_id', 'program_id', 'center_id', 'department_id', 'job_level_id',
            'current_headcount', 'requested_headcount',
        ],
        self::FINANCIAL => [
            'salary_amount', 'currency', 'salary_per', 'contract_type_id',
        ],
        self::DESCRIPTIVE => [
            'direct_supervisor_position_id',
            'recruitment_reason', 'other_reason', 'main_duties', 'required_qualifications',
            'required_experience_years', 'required_skills', 'detailed_explanation',
            'expected_start_date', 'volunteer_period_start_date', 'volunteer_period_end_date',
            'weekly_hours', 'priority', 'volunteer_type', 'role_title_en', 'role_title_ar',
            'reason', 'role_description_ready', 'finance_consulted', 'org_chart_updated',
        ],
    ];

    /**
     * Fields RV-1 lists as substantive that live on the request, not on the
     * details row. They are read from the version snapshot instead.
     *
     * @var array<int, string>
     */
    private const SNAPSHOT_FIELDS = ['request_type', 'city_id', 'vacancy_id'];

    /**
     * The draft payload's own keys, by category.
     *
     * A separate table from DETAIL_FIELDS on purpose: the draft names its
     * fields as the form does (`title_en`, `qualifications`, `responsibilities`)
     * while the details row names them as the records do (`role_title_en`,
     * `required_qualifications`, `main_duties`). Mapping one onto the other
     * would put a translation table between an edit and the record of it, and
     * a key falling through that map would silently stop being reported.
     *
     * `effective_date` is descriptive. It decides *when* the change takes
     * effect, never whether it should, so an approver who agreed to close a
     * post still agrees to close it a month later. Moving the date invalidates
     * nobody's judgement, and sending the request round all three approvers
     * again over a date would cost more than it protects.
     *
     * `direct_manager_id` is descriptive for the reason given on DETAIL_FIELDS:
     * it proposes a parent for the new post and routes nothing.
     *
     * @var array<int, array<int, string>>
     */
    private const PAYLOAD_FIELDS = [
        self::SUBSTANTIVE => [
            'request_type', 'position_id', 'vacancy_id', 'city_id', 'program_id',
            'center_id', 'department_id', 'job_level_id',
            'max_emp', 'additional_count', 'reduce_by',
        ],
        self::FINANCIAL => [
            'salary_amount', 'currency', 'salary_per', 'contract_type_id',
        ],
        self::DESCRIPTIVE => [
            'direct_manager_id', 'effective_date',
            'recruitment_reason', 'other_reason', 'priority', 'volunteer_type',
            'weekly_hours', 'volunteer_period_start_date', 'volunteer_period_end_date',
            'needed_by_date', 'detailed_explanation', 'title_en', 'title_ar',
            'qualifications', 'responsibilities', 'reason',
        ],
    ];

    /**
     * The draft keys that belong to a category.
     *
     * Exposed so the approval panel can offer each approver the fields their
     * own step is about: the financial approver reviews money, so money is
     * what they may send back for.
     *
     * @return array<int, string>
     */
    public function fieldsInCategory(int $category): array
    {
        return self::PAYLOAD_FIELDS[$category] ?? [];
    }

    /**
     * Every draft field that changed, with its old and new value (RV-1b).
     *
     * The edit screen writes the whole payload back whether or not anything
     * moved, so "the request was edited" on its own tells a reader nothing.
     * This is what turns that line into a record of what actually changed.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, array{category: int, from: mixed, to: mixed}>
     */
    public function payloadChanges(array $before, array $after): array
    {
        $changes = [];

        foreach (self::PAYLOAD_FIELDS as $category => $fields) {
            foreach ($fields as $field) {
                $from = $before[$field] ?? null;
                $to = $after[$field] ?? null;

                if (! $this->same($from, $to)) {
                    $changes[$field] = ['category' => $category, 'from' => $from, 'to' => $to];
                }
            }
        }

        return $changes;
    }

    /** The severest category among a payload comparison, or NOTHING. */
    public function classifyPayload(array $before, array $after): int
    {
        return (int) max([self::NOTHING, ...array_column($this->payloadChanges($before, $after), 'category')]);
    }

    /**
     * The severest category of anything that changed.
     *
     * Severest, because a single edit can touch more than one category and the
     * workflow has to go back as far as the worst of them requires.
     */
    public function classify(?RecruitmentRequestVersion $previous, RecruitmentRequestVersion $current): int
    {
        // The first version invalidates nothing; there is nothing before it.
        if ($previous === null) {
            return self::NOTHING;
        }

        $severest = self::NOTHING;

        foreach ($this->changes($previous, $current) as $change) {
            $severest = max($severest, $change['category']);
        }

        return $severest;
    }

    /**
     * Every field that changed, with its old and new value and its category.
     *
     * This is what RV-1b needs: a notification saying "the request was edited"
     * without saying which fields and to what is explicitly not acceptable.
     *
     * @return array<string, array{category: int, from: mixed, to: mixed}>
     */
    public function changes(?RecruitmentRequestVersion $previous, RecruitmentRequestVersion $current): array
    {
        if ($previous === null) {
            return [];
        }

        $before = $previous->positionDetail;
        $after = $current->positionDetail;
        $changes = [];

        foreach (self::DETAIL_FIELDS as $category => $fields) {
            foreach ($fields as $field) {
                $from = $before?->{$field};
                $to = $after?->{$field};

                if (! $this->same($from, $to)) {
                    $changes[$field] = ['category' => $category, 'from' => $from, 'to' => $to];
                }
            }
        }

        $beforeSnapshot = (array) ($previous->snapshot_json ?? []);
        $afterSnapshot = (array) ($current->snapshot_json ?? []);

        foreach (self::SNAPSHOT_FIELDS as $field) {
            $from = $beforeSnapshot[$field] ?? null;
            $to = $afterSnapshot[$field] ?? null;

            if (! $this->same($from, $to)) {
                $changes[$field] = ['category' => self::SUBSTANTIVE, 'from' => $from, 'to' => $to];
            }
        }

        return $changes;
    }

    /**
     * The step a restarted workflow picks up from.
     *
     * Returns null for a descriptive change, meaning "carry on where you were"
     * — there is no step to go back to.
     */
    public function restartsFrom(int $category): ?int
    {
        return match ($category) {
            self::SUBSTANTIVE => WorkflowStepKey::ManagerApproval,
            self::FINANCIAL => WorkflowStepKey::FinanceApproval,
            default => null,
        };
    }

    /**
     * Loose enough that 5 and '5' are the same answer, strict enough that null
     * and 0 are not: a cleared field and a zeroed one mean different things.
     */
    private function same(mixed $from, mixed $to): bool
    {
        if ($from === null || $to === null) {
            return $from === $to;
        }

        if ($from instanceof DateTimeInterface || $to instanceof DateTimeInterface) {
            return $this->asDate($from) === $this->asDate($to);
        }

        if (is_bool($from) || is_bool($to)) {
            return (bool) $from === (bool) $to;
        }

        return (string) $from === (string) $to;
    }

    private function asDate(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface ? $value->format('Y-m-d') : (string) $value;
    }
}
