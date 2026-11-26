<?php

namespace App\Livewire\Onboarding;

use App\Livewire\Concerns\WithFeedback;
use App\Models\PolicyDocument;
use App\Models\User;
use App\Services\Assistant\GeminiException;
use App\Services\Assistant\PolicyFileReader;
use App\Services\PolicyAcknowledgements;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

/**
 * HR uploads the policy documents (PDF, Word or text; their text is what the
 * assistant and the policies pages read), replaces or removes them, and
 * chooses which ones a new volunteer must read and agree to (track 7).
 * HR roles and super-admin only.
 */
class RequiredPolicies extends Component
{
    use WithFeedback, WithFileUploads;

    /** @var TemporaryUploadedFile|null */
    public $upload;

    public string $uploadTitle = '';

    /** The document the upload replaces, or null for a new one. */
    public ?int $replacing = null;

    public function mount(): void
    {
        $this->authorizeHr();
    }

    public function startReplace(int $documentId): void
    {
        $this->authorizeHr();

        $document = PolicyDocument::query()->findOrFail($documentId);
        $this->replacing = $document->id;
        $this->uploadTitle = $document->title;
        $this->reset('upload');
        $this->resetValidation();
    }

    public function cancelReplace(): void
    {
        $this->reset('upload', 'uploadTitle', 'replacing');
        $this->resetValidation();
    }

    public function save(PolicyFileReader $reader): void
    {
        $this->authorizeHr();

        $this->validate([
            'upload' => ['required', 'file', 'mimes:'.implode(',', PolicyFileReader::EXTENSIONS), 'max:'.PolicyFileReader::MAX_KB],
            'uploadTitle' => ['nullable', 'string', 'max:255'],
        ]);

        $extension = strtolower($this->upload->getClientOriginalExtension());

        // Gemini reads a long PDF in several answers, with retries when it is
        // busy: that takes minutes, longer than PHP's default limit.
        set_time_limit(0);

        try {
            $text = $reader->read($this->upload->getRealPath(), $extension);
        } catch (Throwable $e) {
            report($e);
            $this->addError('upload', __('onboarding.required-policies.'.match (true) {
                $e instanceof GeminiException && $e->isTemporary() => 'pdf-busy',
                $e instanceof GeminiException && ($e->isUnavailableHere() || ! filled(config('assistant.gemini.key'))) => 'pdf-unavailable',
                default => 'unreadable',
            }));

            return;
        }

        $document = $this->replacing
            ? PolicyDocument::query()->findOrFail($this->replacing)
            : new PolicyDocument;

        $document->fill([
            'title' => filled($this->uploadTitle) ? trim($this->uploadTitle) : $reader->title($this->upload->getClientOriginalName()),
            'mime_type' => $reader->mimeType($extension),
            'content' => $text,
            'source_modified_at' => now(),
        ])->save();

        $this->reset('upload', 'uploadTitle', 'replacing');
        $this->toast(__('onboarding.required-policies.uploaded'), 'success');
    }

    public function delete(int $documentId): void
    {
        $this->authorizeHr();

        PolicyDocument::query()->findOrFail($documentId)->delete();

        if ($this->replacing === $documentId) {
            $this->cancelReplace();
        }

        $this->toast(__('onboarding.required-policies.deleted'), 'success');
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

    /**
     * Who may upload and manage the policies: an HR role (or super-admin)
     * that holds employees.update, as the route and authorizeHr() demand.
     * The Policies page shows its "Add policy" button by the same rule.
     */
    public static function canManage(?User $user): bool
    {
        $role = (string) $user?->myRole();

        return ($role === 'super-admin' || str_starts_with($role, 'HR-'))
            && $user->hasActivePermission('employees.update');
    }

    private function authorizeHr(): void
    {
        $role = (string) auth()->user()?->myRole();

        abort_unless($role === 'super-admin' || str_starts_with($role, 'HR-'), 403);
    }
}
