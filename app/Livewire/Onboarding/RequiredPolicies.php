<?php

namespace App\Livewire\Onboarding;

use App\Livewire\Archives\ArchivedFilesModule;
use App\Livewire\Concerns\WithFeedback;
use App\Models\PolicyDocument;
use App\Models\User;
use App\Services\Policies\PolicyFolder;
use App\Services\PolicyAcknowledgements;
use Illuminate\View\View;
use Livewire\Component;

/**
 * HR's settings for the policies: which ones a new volunteer must read and
 * agree to, and of which kind (track 7), and where each one stands (being
 * read, failed and why). The files themselves are added, replaced and removed
 * in the Policies folder (PolicyFolder), the one place for them.
 * HR roles and super-admin holding employees.update only.
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

    /** A policy from before the folder (text only, no file): HR removes it once its file is in the folder. */
    public function deleteTextOnly(int $documentId): void
    {
        $this->authorizeHr();

        PolicyDocument::query()->whereNull('media_id')->findOrFail($documentId)->delete();

        $this->toast(__('onboarding.required-policies.deleted'), 'success');
    }

    public function render(PolicyFolder $folder): View
    {
        $this->authorizeHr();

        return view('livewire.onboarding.required-policies', [
            'documents' => PolicyDocument::query()->with('media')->orderBy('title')->get(),
            'folderUrl' => ArchivedFilesModule::folderUrl($folder->library()->id, shared: true),
        ])->title(__('onboarding.required-policies.title'));
    }

    /** Kept for callers from before PolicyFolder::canManage. */
    public static function canManage(?User $user): bool
    {
        return PolicyFolder::canManage($user);
    }

    private function authorizeHr(): void
    {
        abort_unless(PolicyFolder::canManage(auth()->user()), 403);
    }
}
