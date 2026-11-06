<?php

namespace App\Livewire\Recruitment\Applicants;

use App\Models\RecruitmentApplication;
use App\Models\RecruitmentApplicationFlag;
use App\Services\RecruitmentApplicationFlagService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ApplicationFlags extends Component
{
    #[Locked]
    public int $applicationId;

    public string $type = RecruitmentApplicationFlag::TYPE_RED;

    public string $category = 'technical';

    public string $reason = 'below_requirement';

    public string $note = '';

    public function mount(int $applicationId): void
    {
        $this->applicationId = $applicationId;
        $this->resolveApplication();
    }

    public function updatedCategory(): void
    {
        $this->reason = array_key_first(RecruitmentApplicationFlag::reasons()[$this->category] ?? []) ?? '';
    }

    public function create(RecruitmentApplicationFlagService $flags): void
    {
        $application = $this->resolveApplication();
        Gate::authorize('tag', $application);

        try {
            $flags->create(auth()->user(), $application, $this->type, $this->category, $this->reason, $this->note);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return;
        }

        $this->reset('note');
        $this->dispatch('recruitment-flags-changed');
    }

    public function discardFlag(RecruitmentApplicationFlagService $flags, int $flagId): void
    {
        $application = $this->resolveApplication();
        Gate::authorize('tag', $application);

        $flag = $application->flags()->whereKey($flagId)->firstOrFail();
        $flags->discard(auth()->user(), $flag);
        $this->dispatch('recruitment-flags-changed');
    }

    public function render()
    {
        $application = $this->resolveApplication();

        return view('livewire.recruitment.applicants.application-flags', [
            'flags' => $application->flags()->with('creator.profile')->latest('id')->get(),
            'categories' => array_keys(RecruitmentApplicationFlag::reasons()),
            'reasons' => RecruitmentApplicationFlag::reasons()[$this->category] ?? [],
            'mayFlag' => Gate::allows('tag', $application),
        ]);
    }

    private function resolveApplication(): RecruitmentApplication
    {
        $application = RecruitmentApplication::query()->visibleTo(auth()->user())->findOrFail($this->applicationId);
        Gate::authorize('view', $application);

        return $application;
    }
}
