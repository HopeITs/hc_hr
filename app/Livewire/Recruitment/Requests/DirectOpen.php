<?php

namespace App\Livewire\Recruitment\Requests;

use App\Enums\ExternalConfirmationDecision;
use App\Enums\ExternalConfirmationMethod;
use App\Enums\RecruitmentRequestType;
use App\Livewire\Concerns\InitializesRecruitmentRequestSelects;
use App\Models\Center;
use App\Models\City;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\JobLevel;
use App\Models\Program;
use App\Models\RecruitmentRequest;
use App\Models\Vacancy;
use App\Services\RecruitmentDirectOpenService;
use App\Services\RecruitmentScopeResolver;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;
use Livewire\Component;

/**
 * Recording a hire that was settled before anybody opened a screen.
 *
 * The request half of this form is the ordinary one — a direct opening may
 * shorten the approvals, never the facts. What is added is the documentation
 * that makes it honest: who actually asked, why the workflow was bypassed, and
 * lines saying how the manager and finance approvals were really given. The
 * HR action is recorded automatically through the signed-in opener.
 *
 * The lists here are narrowed to the governorate this HR answers for, which is
 * a different boundary from the management tree a manager's own request is
 * scoped by — HR does not manage the posts they recruit for. The service
 * re-derives the same boundary and refuses anything outside it.
 */
class DirectOpen extends Component
{
    use InitializesRecruitmentRequestSelects;

    /** @var array<string, mixed> */
    public array $data = ['request_type' => RecruitmentRequestType::FillVacancy];

    /** @var array<string, mixed> */
    public array $documentation = [
        'actual_requester_user_id' => null,
        'direct_open_reason' => '',
        'confirmations' => [],
    ];

    public function mount(RecruitmentDirectOpenService $directOpen): void
    {
        $this->data = $this->withInitializedRequestSelects($this->data);

        foreach ($directOpen->confirmableSteps() as $stepKey) {
            $this->documentation['confirmations'][$stepKey] = [
                'decision' => null,
                'confirmer_user_id' => null,
                'method' => null,
                'confirmed_at' => null,
                'not_applicable_reason' => '',
                'notes' => '',
            ];
        }
    }

    public function boot(): void
    {
        $this->authorizeScreen();
    }

    public function updatedDataRequestType(): void
    {
        $this->data = $this->withInitializedRequestSelects([
            'request_type' => (int) $this->data['request_type'],
        ]);
        $this->resetValidation();
    }

    public function updatedDataPositionId(): void
    {
        $this->data['vacancy_id'] = null;
        $this->resetConfirmationPeople();
    }

    public function updatedDataCityId(): void
    {
        $this->data['center_id'] = null;
        $this->resetConfirmationPeople();
    }

    public function updatedDocumentationActualRequesterUserId(): void
    {
        $this->data['position_id'] = null;
        $this->data['direct_manager_id'] = null;
        $this->data['vacancy_id'] = null;
        $this->resetConfirmationPeople();
    }

    public function save(RecruitmentDirectOpenService $directOpen)
    {
        $this->resetValidation();

        try {
            $request = $directOpen->open(auth()->user(), $this->data, $this->documentation);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return null;
        }

        $this->toast(__('recruitment.direct_open.opened'));

        return $this->redirectRoute('recruitment.requests.show', ['requestId' => $request->id]);
    }

    public function render()
    {
        $this->authorizeScreen();

        $user = auth()->user();
        $scopes = app(RecruitmentScopeResolver::class);
        $directOpen = app(RecruitmentDirectOpenService::class);
        $type = (int) ($this->data['request_type'] ?? 0);
        $newPosition = $type === RecruitmentRequestType::CreatePosition;

        $requesters = $directOpen->requesterCandidates($user);
        $requester = $requesters->first(
            fn ($candidate): bool => (int) $candidate->getKey() === (int) ($this->documentation['actual_requester_user_id'] ?? 0),
        );
        $steps = $directOpen->confirmableStepsForRequester($requester, $type);
        $positions = $directOpen->positionsForRequester($user, $requester, $type);
        $vacancies = $this->vacanciesFor($type);
        $cityId = $this->targetCityId($positions);
        $confirmationCandidates = [];
        if ($requester !== null) {
            foreach ($steps as $stepKey) {
                $confirmationCandidates[$stepKey] = $directOpen->confirmationCandidates($requester, $stepKey, $cityId);
            }
        }

        return view('livewire.recruitment.requests.direct-open', [
            'positions' => $positions->with(['program', 'city'])->orderBy(transColumn('title'))->get(),
            'requesters' => $requesters,
            'vacancies' => $vacancies,
            // cities, centres and contract types name themselves per locale;
            // programmes and departments have one name column between them.
            'cities' => City::query()->orderBy(transColumn('name'))->get(),
            'programs' => Program::query()->where('is_active', true)->orderBy('name')->get(),
            'centers' => Center::query()
                ->when(! empty($this->data['city_id']), fn ($query) => $query->where('city_id', $this->data['city_id']))
                ->orderBy(transColumn('name'))->get(),
            'departments' => Department::query()->orderBy('name')->get(),
            'jobLevels' => JobLevel::query()->orderBy('grade')->get(),
            'contractTypes' => ContractType::query()
                ->recruitmentOptions()
                ->orderBy(transColumn('name'))
                ->get(),
            'centerRequired' => ! $newPosition || $this->centerIsRequired(),
            'steps' => $steps,
            'confirmationCandidates' => $confirmationCandidates,
            'decisions' => [ExternalConfirmationDecision::Approved, ExternalConfirmationDecision::NotApplicable],
            'methods' => [
                ExternalConfirmationMethod::Phone, ExternalConfirmationMethod::Email,
                ExternalConfirmationMethod::Meeting, ExternalConfirmationMethod::Other,
            ],
        ]);
    }

    /** @return \Illuminate\Support\Collection<int, Vacancy> */
    private function vacanciesFor(int $type)
    {
        if ($type !== RecruitmentRequestType::FillVacancy
            || empty($this->data['position_id'])) {
            return collect();
        }

        return Vacancy::query()
            ->available()
            ->where('position_id', $this->data['position_id'])
            ->orderBy('id')
            ->get();
    }

    private function centerIsRequired(): bool
    {
        $slugs = config('recruitment.request_form.centerless_program_slugs', []);
        $program = Program::query()->find($this->data['program_id'] ?? null);

        return ($this->data['volunteer_type'] ?? null) !== 'remote'
            && ! in_array($program?->slug, $slugs, true);
    }

    private function authorizeScreen(): void
    {
        Gate::authorize('directOpen', RecruitmentRequest::class);
    }

    private function targetCityId($positions): ?int
    {
        if ($this->data['request_type'] === RecruitmentRequestType::CreatePosition) {
            return filter_var($this->data['city_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
        }

        $positionId = filter_var($this->data['position_id'] ?? null, FILTER_VALIDATE_INT);
        if (! $positionId) {
            return null;
        }

        $position = $positions->whereKey($positionId)->with('center')->first();

        return $position?->city_id ?? $position?->center?->city_id;
    }

    private function resetConfirmationPeople(): void
    {
        foreach (app(RecruitmentDirectOpenService::class)->confirmableSteps() as $stepKey) {
            $this->documentation['confirmations'][$stepKey]['confirmer_user_id'] = null;
        }
    }

    /**
     * Say what happened, the way the rest of the application says it.
     *
     * A toast rather than a coloured panel: the panel pushed the page down on
     * every save, and a red or green block across a form reads as part of the
     * form rather than as a remark about it.
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
