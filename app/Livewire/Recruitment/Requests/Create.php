<?php

namespace App\Livewire\Recruitment\Requests;

use App\Enums\RecruitmentRequestType;
use App\Livewire\Concerns\InitializesRecruitmentRequestSelects;
use App\Models\Center;
use App\Models\City;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\JobLevel;
use App\Models\Position;
use App\Models\Program;
use App\Models\RecruitmentRequest;
use App\Models\User;
use App\Models\Vacancy;
use App\Services\RecruitmentRequestDraftService;
use App\Services\RecruitmentScopeResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;
use Livewire\Component;

class Create extends Component
{
    use InitializesRecruitmentRequestSelects;

    public array $data = [
        'request_type' => RecruitmentRequestType::FillVacancy,
        'position_id' => null,
        'direct_manager_id' => null,
        'vacancy_id' => null,
        'volunteers_required' => null,
        'city_id' => null,
        'program_id' => null,
        'center_id' => null,
        'department_id' => null,
        'job_level_id' => null,
        'recruitment_reason' => null,
        'priority' => null,
        'volunteer_type' => null,
        'currency' => null,
        'salary_per' => null,
        'contract_type_id' => null,
    ];

    public bool $queryPrefilled = false;

    public function boot(): void
    {
        $this->authorizeScreen();
        $this->prefillFromQuery();
    }

    public function updatedDataRequestType(): void
    {
        $this->data = $this->withInitializedRequestSelects([
            'request_type' => (int) $this->data['request_type'],
        ]);
        $this->selectOnlyProgram();
        $this->resetValidation();
    }

    public function updatedDataPositionId(): void
    {
        $this->data['vacancy_id'] = null;
        if ((int) ($this->data['request_type'] ?? 0) === RecruitmentRequestType::FillVacancy) {
            $vacancies = $this->availableVacancies()->limit(2)->get();
            if ($vacancies->count() === 1) {
                $this->data['vacancy_id'] = $vacancies->first()->id;
            }
        }
    }

    public function updatedDataCityId(): void
    {
        $this->data['center_id'] = null;
    }

    public function save(RecruitmentRequestDraftService $drafts)
    {
        $this->resetValidation();
        $request = $drafts->create(auth()->user(), $this->data);
        $this->toast(__('recruitment.draft_saved'));

        return $this->redirectRoute('recruitment.requests.show', ['requestId' => $request->id]);
    }

    /**
     * Every picker is narrowed to what this requester may actually choose.
     *
     * A filtered list is a convenience, never the guard: the draft service
     * re-derives the same sets server-side and rejects anything outside them,
     * so editing the request by hand gains nothing.
     */
    public function render()
    {
        $this->authorizeScreen();

        $user = auth()->user();
        $scopes = app(RecruitmentScopeResolver::class);
        $isNewPosition = (int) ($this->data['request_type'] ?? 0) === RecruitmentRequestType::CreatePosition;

        $positions = $isNewPosition
            ? $this->proposableManagerPositions($user)
            : $this->targetablePositions($user);
        $type = (int) ($this->data['request_type'] ?? 0);
        if ($type === RecruitmentRequestType::ClosePosition) {
            $positions
                ->where('exist_emp', 0)
                ->where('max_emp', 1)
                ->whereDoesntHave('activeContracts');
        }
        if ($type === RecruitmentRequestType::CloseVacancy) {
            $positions
                ->where('is_active', true)
                ->where('max_emp', '>', 1)
                ->whereColumn('exist_emp', '<', 'max_emp');
        }
        $isFill = (int) ($this->data['request_type'] ?? 0) === RecruitmentRequestType::FillVacancy;
        if ($isFill) {
            // Room in the establishment, not an existing vacancies row: until a
            // request is approved and applied nothing opens one, so requiring it
            // would leave every genuinely empty seat unfillable.
            $positions->where('is_active', true)->whereColumn('exist_emp', '<', 'max_emp');
        }
        $positions = $positions->with(['city', 'program'])->orderBy(transColumn('title'))->get();

        $cityId = filter_var($this->data['city_id'] ?? null, FILTER_VALIDATE_INT) ?: null;

        $centers = $scopes->scopeCreatableCenters(Center::query(), $user, $cityId)
            ->orderBy(transColumn('name'))->get();
        $vacancies = $isFill
            ? $this->availableVacancies()->orderBy('id')->get()
            : Vacancy::query()
                ->visibleTo($user)
                ->when($type === RecruitmentRequestType::CloseVacancy, fn ($query) => $query->available())
                ->where('position_id', $this->data['position_id'] ?? 0)
                ->get();

        return view('livewire.recruitment.requests.create', [
            'positions' => $positions, 'centers' => $centers, 'vacancies' => $vacancies,
            'cities' => $scopes->scopeCreatableCities(City::query(), $user)->orderBy(transColumn('name'))->get(),
            'programs' => $scopes->scopeCreatablePrograms(Program::query(), $user)->orderBy('name')->get(),
            // A centre is named unless the work is remote or the programme runs
            // across many centres rather than out of one.
            'centerRequired' => $this->centerIsRequired(),
            'departments' => Department::query()->orderBy('name')->get(),
            // Strictly junior to the requester's own grade — nobody creates a
            // peer or a superior for himself. Enforced again on save.
            'jobLevels' => JobLevel::query()
                ->whereIn('id', $scopes->creatableJobLevelIds($user))
                ->orderBy('grade')->get(),
            'contractTypes' => ContractType::query()
                ->recruitmentOptions()
                ->orderBy(transColumn('name'))
                ->get(),
            // Nothing is locked while a request is being written; Edit says
            // otherwise once an approver has named what may change.
            'lockedFields' => $this->lockedFields(),
        ]);
    }

    /** Overridden by Edit, which authorizes against an existing request. */
    protected function authorizeScreen(): void
    {
        Gate::authorize('create', RecruitmentRequest::class);
    }

    /**
     * Fields this screen must show but refuse to let anybody type into.
     *
     * None, on a new request. Edit overrides this once the request has been
     * submitted, because from then on only what an approver asked for may
     * change — and a field that will be refused on save should look refused
     * before anybody types into it.
     *
     * @return array<int, string>
     */
    protected function lockedFields(): array
    {
        return [];
    }

    /**
     * Positions this requester may target for a fill or a close.
     *
     * The narrowed management subtree here — a manager reaches only the posts
     * below their own. Edit widens it to the whole governorate for a request
     * HR opened directly, since HR does not manage the posts they recruit for.
     *
     * @return Builder<Position>
     */
    protected function targetablePositions(User $user): Builder
    {
        return app(RecruitmentScopeResolver::class)->scopeRequestablePositions(Position::query(), $user);
    }

    /**
     * Positions a brand-new post may be proposed to report to.
     *
     * @return Builder<Position>
     */
    protected function proposableManagerPositions(User $user): Builder
    {
        return app(RecruitmentScopeResolver::class)->scopeProposedManagers(Position::query(), $user);
    }

    private function prefillFromQuery(): void
    {
        if ($this->queryPrefilled) {
            return;
        }

        $this->queryPrefilled = true;
        $type = filter_var(request()->query('type'), FILTER_VALIDATE_INT);
        $positionId = filter_var(request()->query('position_id'), FILTER_VALIDATE_INT);
        $parentPositionId = filter_var(request()->query('direct_manager_id'), FILTER_VALIDATE_INT);
        $vacancyId = filter_var(request()->query('vacancy_id'), FILTER_VALIDATE_INT);
        $types = array_values(array_diff(RecruitmentRequestType::getValues(), [RecruitmentRequestType::Unknown]));

        if ($type !== false && in_array((int) $type, $types, true)) {
            $this->data['request_type'] = (int) $type;
        }

        if ($positionId !== false && (int) $positionId > 0) {
            $this->data['position_id'] = (int) $positionId;
        }

        if ($parentPositionId !== false && (int) $parentPositionId > 0) {
            $this->data['direct_manager_id'] = (int) $parentPositionId;
        }

        // The staffing chart names the vacancy when the position has exactly
        // one open, so the form does not ask a question the card already
        // answered. Prefilling is a convenience only: the draft service
        // re-derives which vacancies this requester may target and refuses
        // anything outside them, so a hand-edited id gains nothing.
        if ($vacancyId !== false && (int) $vacancyId > 0) {
            $this->data['vacancy_id'] = (int) $vacancyId;
        }
    }

    private function selectOnlyProgram(): void
    {
        if ((int) ($this->data['request_type'] ?? 0) !== RecruitmentRequestType::CreatePosition) {
            return;
        }
        $programs = app(RecruitmentScopeResolver::class)
            ->scopeCreatablePrograms(Program::query(), auth()->user())->limit(2)->pluck('id');
        if ($programs->count() === 1) {
            $this->data['program_id'] = $programs->first();
        }
    }

    /** Mirrors the rule the draft service enforces on save. */
    private function centerIsRequired(): bool
    {
        if (($this->data['volunteer_type'] ?? null) === 'remote') {
            return false;
        }

        $exempt = config('recruitment.request_form.centerless_program_slugs', []);
        $slug = empty($this->data['program_id']) ? null
            : Program::query()->whereKey($this->data['program_id'])->value('slug');

        return $slug === null || ! in_array($slug, $exempt, true);
    }

    private function availableVacancies()
    {
        $positions = $this->targetablePositions(auth()->user())
            ->where('is_active', true)->whereColumn('exist_emp', '<', 'max_emp');

        // May legitimately be empty; the vacancy picker only appears when more
        // than one is open and the requester has to choose between them.
        return Vacancy::query()->available()
            ->whereIn('position_id', $positions->select('id'))
            ->where('position_id', $this->data['position_id'] ?? 0);
    }

    /**
     * Say what happened, the way the rest of the application says it.
     *
     * A toast rather than a coloured panel: a red or green block across a form
     * reads as part of the form rather than as a remark about it.
     */
    private function toast(string $message, bool $success = true): void
    {
        $alert = LivewireAlert::title($message);

        ($success ? $alert->success() : $alert->error())
            ->toast()
            ->position('top-start')
            ->show();
    }
}
