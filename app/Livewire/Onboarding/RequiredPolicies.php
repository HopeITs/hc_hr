<?php

namespace App\Livewire\Onboarding;

use App\Livewire\Concerns\WithFeedback;
use App\Models\PolicyDocument;
use App\Services\PolicyAcknowledgements;
use Illuminate\View\View;
use Livewire\Component;

/**
 * HR chooses which policy documents a new volunteer must read and agree to
 * (track 7), e.g. the Media guideline. HR roles and super-admin only.
 */
class RequiredPolicies extends Component
{
    use WithFeedback;

    public function mount(): void
    {
        $this->authorizeHr();
    }

    public function setRequired(int $documentId, bool $required, PolicyAcknowledgements $policies): void
    {
        $this->authorizeHr();

        $policies->setRequired(PolicyDocument::query()->findOrFail($documentId), $required);

        $this->toast(__('onboarding.required-policies.saved'), 'success');
    }

    /** policy (read and agreed) or review (made by another team, only reviewed). */
    public function setKind(int $documentId, string $kind, PolicyAcknowledgements $policies): void
    {
        $this->authorizeHr();

        $policies->setKind(PolicyDocument::query()->findOrFail($documentId), $kind);

        $this->toast(__('onboarding.required-policies.saved'), 'success');
    }

    public function render(): View
    {
        $this->authorizeHr();

        return view('livewire.onboarding.required-policies', [
            'documents' => PolicyDocument::query()->orderBy('title')->get(),
        ])->title(__('onboarding.required-policies.title'));
    }

    private function authorizeHr(): void
    {
        $role = (string) auth()->user()?->myRole();

        abort_unless($role === 'super-admin' || str_starts_with($role, 'HR-'), 403);
    }
}
