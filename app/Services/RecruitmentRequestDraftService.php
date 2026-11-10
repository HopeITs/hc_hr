<?php

namespace App\Services;

use App\Enums\Currency;
use App\Enums\RecruitmentEventType;
use App\Enums\RecruitmentRequestMode;
use App\Enums\RecruitmentRequestStatus;
use App\Enums\RecruitmentRequestType as Type;
use App\Enums\RecruitmentScopeType;
use App\Enums\SalaryPer;
use App\Models\Center;
use App\Models\City;
use App\Models\ContractType;
use App\Models\Position;
use App\Models\Program;
use App\Models\RecruitmentRequest;
use App\Models\User;
use App\Models\Vacancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class RecruitmentRequestDraftService
{
    public function __construct(
        private readonly RecruitmentScopeResolver $scopes,
        private readonly RecruitmentRequestSubmissionService $submissions,
    ) {}

    /** Recheck mutable organizational assignments and vacancy availability at submit time. */
    public function assertCurrentScope(User $actor, RecruitmentRequest $request): void
    {
        $data = $this->validate((array) $request->draft_payload_json);
        [$target] = $this->resolveTarget($actor->fresh(), $data);
        if (($data['request_type'] !== Type::CreatePosition && (int) $target->id !== (int) $request->target_position_id)
            || (int) ($data['vacancy_id'] ?? 0) !== (int) $request->target_vacancy_id) {
            throw ValidationException::withMessages(['data.vacancy_id' => __('recruitment.invalid_vacancy')]);
        }
    }

    /** Only normal draft creation; no HR master data or submitted version is written. */
    public function create(User $actor, array $input): RecruitmentRequest
    {
        // Fresh identity/assignment prevents reuse of a memoized employee contract.
        $actor = $actor->fresh();
        Gate::forUser($actor)->authorize('create', RecruitmentRequest::class);
        $data = $this->validate($input);

        return DB::transaction(function () use ($actor, $data) {
            $isNewPosition = $data['request_type'] === Type::CreatePosition;
            [$target, $cityId] = $this->resolveTarget($actor, $data);

            $numbers = app(RecruitmentRequestNumber::class);

            $request = RecruitmentRequest::query()->create([
                'request_number' => $numbers->next(
                    $cityId,
                    $numbers->programIdFor($data, $isNewPosition ? null : $target->id),
                ),
                'requester_user_id' => $actor->id,
                'request_type' => $data['request_type'],
                'mode' => RecruitmentRequestMode::Normal,
                'status' => RecruitmentRequestStatus::Draft,
                'scope_city_id' => $cityId,
                'target_position_id' => $isNewPosition ? null : $target->id,
                'target_vacancy_id' => $data['vacancy_id'] ?? null,
                'draft_payload_json' => $data,
            ]);

            $this->recordEvent($request, $actor, [
                'event_type' => RecruitmentEventType::RequestCreated,
                'to_status' => RecruitmentRequestStatus::Draft,
            ]);

            return $request;
        });
    }

    /**
     * Edit a draft (T2) or a request sent back for changes (T10).
     *
     * The same validation and the same scope checks as creation, because the
     * target may be swapped for one the requester was never allowed to touch.
     * Ownership and status are re-read under a lock so an edit cannot land on a
     * request that has meanwhile been submitted or decided.
     */
    public function update(User $actor, RecruitmentRequest $request, array $input): RecruitmentRequest
    {
        $actor = $actor->fresh();
        Gate::forUser($actor)->authorize('update', $request);
        $data = $this->validate($input);

        return DB::transaction(function () use ($actor, $request, $data) {
            $fresh = RecruitmentRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            $isDirectOpen = (int) $fresh->mode === RecruitmentRequestMode::DirectHr;
            $editableDirectOpen = $isDirectOpen
                && (int) $fresh->status === RecruitmentRequestStatus::UnderReview
                && app(RecruitmentEditableFields::class)->anyEditable($fresh);

            if (! in_array((int) $fresh->status, [RecruitmentRequestStatus::Draft, RecruitmentRequestStatus::ChangesRequested], true)
                && ! $editableDirectOpen) {
                throw ValidationException::withMessages(['request' => __('recruitment.not_editable')]);
            }

            // Normal requests are owned by the requester; direct openings are
            // owned by whoever HR opened them, already checked by the policy.
            if (! $isDirectOpen && (int) $fresh->requester_user_id !== (int) $actor->getKey()) {
                throw ValidationException::withMessages(['request' => __('recruitment.not_your_request')]);
            }

            // The type is fixed once the request exists: changing it would make
            // the draft's own fields meaningless.
            if ((int) $data['request_type'] !== (int) $fresh->request_type) {
                throw ValidationException::withMessages(['data.request_type' => __('recruitment.type_is_fixed')]);
            }

            $mode = $isDirectOpen ? RecruitmentRequestMode::DirectHr : null;
            [$target, $cityId] = $this->resolveTarget($actor, $data, $mode);

            // Read before the write, so the event can say what moved. RV-1b:
            // "the request was edited" without naming the fields is explicitly
            // not enough for the approver who has to decide whether their own
            // approval still stands.
            $before = (array) ($fresh->draft_payload_json ?? []);

            $this->assertOnlyPermittedFieldsChanged($fresh, $before, $data);

            $fresh->forceFill([
                'scope_city_id' => $cityId,
                'target_position_id' => $data['request_type'] === Type::CreatePosition ? null : $target->getKey(),
                'target_vacancy_id' => $data['vacancy_id'] ?? null,
                'draft_payload_json' => $data,
            ])->save();

            // A direct opening's frozen version must stay current: the
            // approver sees the version, not the draft payload.
            if ($editableDirectOpen) {
                $this->submissions->freezeVersion($fresh, $actor, $data);
            }

            $changes = app(RecruitmentRequestChangeClassifier::class)->payloadChanges($before, $data);

            $this->recordEvent($fresh, $actor, [
                'event_type' => RecruitmentEventType::RequestEdited,
                'to_status' => (int) $fresh->status,
                'meta' => $changes === [] ? null : ['changes' => $changes],
            ]);

            return $fresh;
        });
    }

    /** Drafts may be incomplete, but scope/IDs and any supplied data must be valid. */
    /**
     * The request form's own rules, without any question of who may raise it.
     *
     * Public because the direct-open path needs exactly this and nothing else
     * around it: a direct opening may shorten the approvals, never the facts.
     */
    public function validate(array $input): array
    {
        $type = Validator::make(['data' => $input], [
            'data.request_type' => ['required', 'integer', Rule::in(array_values(array_diff(Type::getValues(), [Type::Unknown])))],
        ], [], ['data.request_type' => __('recruitment.fields.request_type')])->validate()['data']['request_type'];
        $type = (int) $type;
        $rules = ['request_type' => ['required', 'integer']];
        $rules['position_id'] = ['required', 'integer'];

        if ($type === Type::CreatePosition) {
            unset($rules['position_id']);
            $rules += [
                'direct_manager_id' => ['required', 'integer'],
                'city_id' => ['required', 'integer', 'exists:cities,id'],
                'program_id' => ['required', 'integer', 'exists:programs,id'],
                'center_id' => [$this->centerRule($input), 'integer', 'exists:centers,id'],
                'department_id' => ['required', 'integer', 'exists:departments,id'],
                'job_level_id' => ['required', 'integer', 'exists:job_levels,id'],
                // One alphabet each. The Unicode ranges are named rather
                // than \p{Arabic}, which also matches Persian and Urdu letters
                // nobody would be typing here.
                'title_en' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z\s]+$/u'],
                'title_ar' => ['required', 'string', 'max:255', 'regex:/^[\x{0621}-\x{064A}\s]+$/u'],
                'qualifications' => ['required', 'string'],
                'responsibilities' => ['required', 'string'],
                'max_emp' => ['required', 'integer', 'min:1'],
            ];
        }

        // The three types that end in somebody being hired share the form the
        // paper request asks for: why, when, how long, how often and how paid.
        if (in_array($type, [Type::FillVacancy, Type::IncreaseHeadcount, Type::CreatePosition], true)) {
            $form = config('recruitment.request_form');

            $rules += [
                'recruitment_reason' => ['required', 'string', Rule::in($form['recruitment_reasons'])],
                // Only meaningful when the list itself has no answer for it.
                'other_reason' => ['required_if:data.recruitment_reason,other', 'nullable', 'string', 'max:1000'],
                'priority' => ['required', 'string', Rule::in($form['priorities'])],
                'volunteer_type' => ['required', 'string', Rule::in($form['volunteer_types'])],
                'weekly_hours' => ['required', 'integer', 'min:1', 'max:168'],
                // Strictly future: a post needed by yesterday is a post
                // nobody can still be recruited for.
                'needed_by_date' => ['required', 'date_format:Y-m-d', 'after:today'],
                'volunteer_period_start_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:data.needed_by_date'],
                'volunteer_period_end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:data.volunteer_period_start_date'],
                'salary_amount' => ['required', 'integer', 'min:0'],
                'currency' => ['required', 'integer', Rule::in([Currency::SYP, Currency::USD, Currency::EUR])],
                'salary_per' => ['required', 'integer', Rule::in([SalaryPer::Month, SalaryPer::Hour, SalaryPer::Session])],
                'contract_type_id' => [
                    'required',
                    'integer',
                    Rule::exists(ContractType::class, 'id')->whereIn(
                        'name_en',
                        config('recruitment.request_form.contract_types', []),
                    ),
                ],
                'detailed_explanation' => ['required', 'string'],
            ];
        }
        if (in_array($type, [Type::FillVacancy, Type::CreatePosition], true)) {
            $rules['volunteers_required'] = ['required', 'integer', 'min:1'];
        }
        if ($type === Type::FillVacancy) {
            $rules['vacancy_id'] = ['nullable', 'integer'];
        }
        if ($type === Type::IncreaseHeadcount) {
            $rules['additional_count'] = ['required', 'integer', 'min:1'];
        }
        if ($type === Type::DecreaseHeadcount) {
            $rules['reduce_by'] = ['required', 'integer', 'min:1'];
        }
        if (in_array($type, [Type::DecreaseHeadcount, Type::ClosePosition, Type::CloseVacancy], true)) {
            $rules['reason'] = ['required', 'string'];
        }
        if (in_array($type, [Type::DecreaseHeadcount, Type::ClosePosition, Type::CloseVacancy], true)) {
            $rules['effective_date'] = ['required', 'date_format:Y-m-d'];
        }

        // Strip fields for other request types and all caller-supplied internal state.
        $input = array_intersect_key($input, $rules);
        $input = array_map(fn ($value) => $value === '' ? null : $value, $input);
        $prefixed = [];
        foreach ($rules as $field => $rule) {
            $prefixed['data.'.$field] = $rule;
        }
        $attributes = collect(array_keys($rules))
            ->mapWithKeys(fn (string $field): array => ['data.'.$field => __('recruitment.'.$field)])
            ->all();
        $data = Validator::make(['data' => $input], $prefixed, [], $attributes)->validate()['data'];
        $data['request_type'] = $type;

        if ($type === Type::CreatePosition && (int) $data['volunteers_required'] > (int) $data['max_emp']) {
            throw ValidationException::withMessages([
                'data.volunteers_required' => __('recruitment.volunteers_required_exceeds_available', ['count' => $data['max_emp']]),
            ]);
        }

        return $data;
    }

    /**
     * Resolve and lock the position this request is about, and its governorate.
     *
     * Shared by create and update because an edit can swap the target for one
     * the requester never had: the tree is re-checked every time, not once.
     *
     * @return array{0: Position, 1: int}
     */
    private function resolveTarget(User $actor, array &$data, ?int $mode = null): array
    {
        $isNewPosition = $data['request_type'] === Type::CreatePosition;
        $targetId = $isNewPosition ? $data['direct_manager_id'] : $data['position_id'];

        // A direct opening is scoped to the governorate, not the requester's
        // management subtree — HR does not manage the posts they recruit for.
        if ($mode === RecruitmentRequestMode::DirectHr) {
            $targets = $this->scopes->scopeGovernoratePositions(Position::query(), $actor);
        } else {
            $targets = $isNewPosition
                ? $this->scopes->scopeProposedManagers(Position::query(), $actor)
                : $this->scopes->scopeRequestablePositions(Position::query(), $actor);
        }
        $target = $targets
            ->whereKey($targetId)->lockForUpdate()->first();

        if (! $target) {
            throw ValidationException::withMessages([
                'data.'.($isNewPosition ? 'direct_manager_id' : 'position_id') => __('recruitment.outside_tree'),
            ]);
        }

        // Direct openings are authorized by governorate scope above; the
        // per-position gate checks the management tree which HR is not in.
        if ($mode !== RecruitmentRequestMode::DirectHr) {
            Gate::forUser($actor)->authorize(
                $isNewPosition ? 'createUnderPosition' : 'createForPosition',
                [RecruitmentRequest::class, $target],
            );
        }

        $cityId = $isNewPosition ? $data['city_id'] : ($target->city_id ?? $target->center?->city_id);

        if (! $cityId) {
            throw ValidationException::withMessages(['data.position_id' => __('recruitment.missing_city')]);
        }

        if ($isNewPosition) {
            $this->assertWithinCreationScope($actor, $data);

            if (! empty($data['center_id'])) {
                $center = Center::query()->findOrFail($data['center_id']);
                if (! City::isNational((int) $cityId) && $center->city_id !== null && (int) $center->city_id !== (int) $cityId) {
                    throw ValidationException::withMessages(['data.center_id' => __('recruitment.center_city_mismatch')]);
                }
            }
        }

        if ($data['request_type'] === Type::DecreaseHeadcount
            && (int) $target->max_emp - (int) ($data['reduce_by'] ?? 0) < (int) $target->exist_emp) {
            throw ValidationException::withMessages([
                'data.reduce_by' => __('recruitment.below_existing_employees'),
            ]);
        }

        if ($data['request_type'] === Type::CloseVacancy
            && (int) $target->max_emp <= (int) $target->exist_emp) {
            throw ValidationException::withMessages([
                'data.position_id' => __('recruitment.no_vacant_position'),
            ]);
        }

        if ($data['request_type'] === Type::CloseVacancy && (int) $target->max_emp <= 1) {
            throw ValidationException::withMessages([
                'data.position_id' => __('recruitment.close_vacancy_many_seats_only'),
            ]);
        }

        if ($data['request_type'] === Type::ClosePosition && (int) $target->max_emp !== 1) {
            throw ValidationException::withMessages([
                'data.position_id' => __('recruitment.close_position_single_seat_only'),
            ]);
        }

        if ($data['request_type'] === Type::ClosePosition
            && ((int) $target->exist_emp > 0 || $target->activeContracts()->exists())) {
            throw ValidationException::withMessages([
                'data.position_id' => __('recruitment.position_has_active_contracts'),
            ]);
        }

        if ($data['request_type'] === Type::FillVacancy) {
            // Room in the establishment is what makes the request legitimate.
            // A vacancies row is not required: nothing creates one until a
            // request is approved and applied, so requiring one first would
            // make filling a genuinely empty seat impossible.
            if (! $target->is_active || ! $target->canAssign()) {
                throw ValidationException::withMessages(['data.position_id' => __('recruitment.no_headcount_room')]);
            }

            $available = $target->vacancies()->available()->orderBy('id')->lockForUpdate()->get();

            if (! empty($data['vacancy_id']) && ! $available->contains('id', (int) $data['vacancy_id'])) {
                throw ValidationException::withMessages(['data.vacancy_id' => __('recruitment.invalid_vacancy')]);
            }

            if (! empty($data['vacancy_id'])) {
                $selectedVacancy = $available->firstWhere('id', (int) $data['vacancy_id']);
                $availableSeats = $selectedVacancy
                    ? max(0, (int) $selectedVacancy->needed_count - (int) $selectedVacancy->filled_count)
                    : 0;
            } else {
                $availableSeats = $available->isNotEmpty()
                    ? $available->sum(fn (Vacancy $vacancy): int => max(0, (int) $vacancy->needed_count - (int) $vacancy->filled_count))
                    : max(0, (int) $target->max_emp - (int) $target->exist_emp);
            }
            if ((int) ($data['volunteers_required'] ?? 0) > $availableSeats) {
                throw ValidationException::withMessages([
                    'data.volunteers_required' => __('recruitment.volunteers_required_exceeds_available', ['count' => $availableSeats]),
                ]);
            }

            // One open vacancy is picked for the requester; several mean he has
            // to say which; none at all is fine, and one is opened on approval.
            if ($available->count() === 1) {
                $data['vacancy_id'] = $available->first()->id;
            } elseif ($available->count() > 1 && empty($data['vacancy_id'])) {
                throw ValidationException::withMessages(['data.vacancy_id' => __('recruitment.choose_available_vacancy')]);
            }
        } elseif (! empty($data['vacancy_id'])) {
            // Vacancy visibility is still D30/D42, independent of D49's position selector.
            $vacancy = Vacancy::query()->visibleTo($actor)
                ->where('position_id', $target->id)
                ->whereKey($data['vacancy_id'])->lockForUpdate()->first();

            if (! $vacancy) {
                throw ValidationException::withMessages(['data.vacancy_id' => __('recruitment.invalid_vacancy')]);
            }
        }

        return [$target, (int) $cityId];
    }

    /**
     * Whether a new position has to name the centre it sits in.
     *
     * Two cases have none to name: remote work, which sits in no centre at
     * all, and the programmes that run across many centres rather than out of
     * one, where pinning the position to a single centre would be a fiction.
     */
    private function centerRule(array $input): string
    {
        if (($input['volunteer_type'] ?? null) === 'remote') {
            return 'nullable';
        }

        $exempt = config('recruitment.request_form.centerless_program_slugs', []);
        $slug = empty($input['program_id']) ? null
            : Program::query()->whereKey($input['program_id'])->value('slug');

        return $slug !== null && in_array($slug, $exempt, true) ? 'nullable' : 'required';
    }

    /** D8: a failed audit write is reported and must not roll back the request. */
    /**
     * Refuse any change the approver did not ask for.
     *
     * Once a request has been submitted it is a fixed document, and the only
     * way back in is an approver naming the fields they want changed. Enforced
     * here rather than only by disabling inputs, because a disabled input is a
     * courtesy to the person using the screen and no obstacle at all to a
     * request sent straight at the endpoint.
     *
     * Every field that was touched without permission is named at once, so the
     * requester is not sent round the loop discovering them one at a time.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function assertOnlyPermittedFieldsChanged(RecruitmentRequest $request, array $before, array $after): void
    {
        $permitted = app(RecruitmentEditableFields::class)->for($request);

        if ($permitted === RecruitmentEditableFields::ALL) {
            return;
        }

        $refused = [];

        foreach (app(RecruitmentRequestChangeClassifier::class)->payloadChanges($before, $after) as $field => $ignored) {
            if (! in_array($field, $permitted, true)) {
                $refused['data.'.$field] = __('recruitment.field_is_locked', [
                    'field' => __('recruitment.fields.'.$field),
                ]);
            }
        }

        if ($refused !== []) {
            throw ValidationException::withMessages($refused);
        }
    }

    private function recordEvent(RecruitmentRequest $request, User $actor, array $attributes): void
    {
        // A nested transaction/savepoint also keeps the outer transaction usable.
        try {
            DB::transaction(fn () => $request->events()->create(array_merge([
                'actor_user_id' => $actor->id,
                'actor_role' => $actor->myRole(),
                'scope_type' => $this->scopes->scopeType($actor),
                'scope_id' => $this->scopes->scopeType($actor) === RecruitmentScopeType::DirectReports
                    ? $this->scopes->position($actor)?->id : null,
            ], $attributes)));
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /** Organizational consistency supplements, and never widens, the tree. */
    private function assertWithinCreationScope(User $actor, array $data): void
    {
        $cityId = (int) $data['city_id'];
        $programId = empty($data['program_id']) ? null : (int) $data['program_id'];
        $checks = [
            'city_id' => $this->scopes->scopeCreatableCities(City::query(), $actor)->whereKey($cityId)->exists(),
            'program_id' => $programId === null
                ? ! $this->scopes->hasProgramCreationScope($actor)
                : $this->scopes->scopeCreatablePrograms(Program::query(), $actor)->whereKey($programId)->exists(),
            'center_id' => empty($data['center_id']) || $this->scopes
                ->scopeCreatableCenters(Center::query(), $actor, $cityId, $programId)
                ->whereKey($data['center_id'])->exists(),
        ];
        foreach ($checks as $field => $valid) {
            if (! $valid) {
                throw ValidationException::withMessages(['data.'.$field => __('recruitment.outside_organization')]);
            }
        }

        if (empty($data['job_level_id'])) {
            return;
        }

        if (! in_array((int) $data['job_level_id'], $this->scopes->creatableJobLevelIds($actor), true)) {
            throw ValidationException::withMessages([
                'data.job_level_id' => __('recruitment.job_level_not_junior'),
            ]);
        }
    }
}
