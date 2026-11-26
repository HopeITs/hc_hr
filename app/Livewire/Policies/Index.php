<?php

namespace App\Livewire\Policies;

use App\Livewire\Concerns\ValidatesArchiveUploads;
use App\Livewire\Concerns\WithFeedback;
use App\Models\PolicyDocument;
use App\Services\Assistant\PolicyFileReader;
use App\Services\Assistant\PolicyLibrary;
use App\Services\Policies\PolicyFolder;
use App\Services\Policies\PolicySync;
use App\Services\PolicyAcknowledgements;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * The organisation's policies, for every signed-in employee: a box that
 * searches what they say (results grouped by policy, the best passage first
 * and up to two more), then the list. A policy with a new version the
 * employee has to agree to again is marked.
 *
 * HR adds, replaces and removes policies right here. The file goes into the
 * Policies folder (so Folders shows it too) through the same upload checks as
 * the Folders page, and PolicySync turns it into a policy. HR also sees any
 * policy still being read or that failed.
 */
class Index extends Component
{
    use ValidatesArchiveUploads, WithFeedback, WithFileUploads;

    #[Url(as: 'q', except: '')]
    public string $q = '';

    public ?TemporaryUploadedFile $newPolicy = null;

    public ?TemporaryUploadedFile $replacement = null;

    public ?int $replacingId = null;

    public function updatedNewPolicy(PolicyFolder $folder): void
    {
        $this->authorizeManage();

        $this->validate(['newPolicy' => ['required', 'file', 'max:'.PolicyFileReader::MAX_KB]]);
        $safe = $this->validateArchiveUpload($this->newPolicy);

        if (! PolicyFolder::isReadable($safe['extension'])) {
            $this->addError('newPolicy', __('policies.file-types'));
            $this->reset('newPolicy');

            return;
        }

        $folder->library()
            ->addMedia($this->newPolicy->getRealPath())
            ->usingName($safe['displayName'])
            ->usingFileName($safe['storedName'])
            ->withCustomProperties(['validated_upload' => true])
            ->toMediaCollection('archived_files', 'local');

        $this->reset('newPolicy');
        $this->toast(__('policies.added'), 'success');
    }

    public function startReplace(int $documentId): void
    {
        $this->authorizeManage();

        $this->replacingId = PolicyDocument::query()->whereNotNull('media_id')->findOrFail($documentId)->id;
        $this->reset('replacement');
        $this->resetValidation();
    }

    public function cancelReplace(): void
    {
        $this->reset('replacingId', 'replacement');
    }

    public function updatedReplacement(PolicyFolder $folder, PolicySync $sync): void
    {
        $this->authorizeManage();
        abort_if($this->replacingId === null, 403);

        $document = PolicyDocument::query()->whereNotNull('media_id')->findOrFail($this->replacingId);

        $this->validate(['replacement' => ['required', 'file', 'max:'.PolicyFileReader::MAX_KB]]);
        $safe = $this->validateArchiveUpload($this->replacement);

        if (! PolicyFolder::isReadable($safe['extension'])) {
            $this->addError('replacement', __('policies.file-types'));

            return;
        }

        $new = $folder->library()
            ->addMedia($this->replacement->getRealPath())
            ->usingName($safe['displayName'])
            ->usingFileName($safe['storedName'])
            ->withCustomProperties(['validated_upload' => true, 'replaces_policy' => $document->id])
            ->toMediaCollection('archived_files', 'local');

        $sync->replace($document, $new);

        $this->cancelReplace();
        $this->toast(__('policies.replaced'), 'success');
    }

    /** The file goes with its policy (PolicySync::deleted does the rest). */
    public function remove(int $documentId): void
    {
        $this->authorizeManage();

        $document = PolicyDocument::query()->with('media')->findOrFail($documentId);

        $document->media !== null ? $document->media->delete() : $document->delete();

        $this->toast(__('policies.removed'), 'success');
    }

    public function render(PolicyLibrary $library, PolicyAcknowledgements $acknowledgements): View
    {
        $term = trim(mb_substr($this->q, 0, 80));
        $employee = auth()->user()?->profile;
        $canManage = PolicyFolder::canManage(auth()->user());

        return view('livewire.policies.index', [
            'term' => $term,
            'marks' => $library->highlightTerms($term),
            'hits' => mb_strlen($term) >= 2 ? $library->search($term, 12, perDocument: 3) : [],
            'documents' => PolicyDocument::query()->readable()->with('media')->orderBy('title')
                ->get(['id', 'media_id', 'title', 'mime_type', 'required_for_onboarding', 'source_modified_at', 'updated_at']),
            'outdated' => $employee ? $acknowledgements->outdatedFor($employee)->all() : [],
            'canManage' => $canManage,
            'unfinished' => $canManage
                ? PolicyDocument::query()->whereIn('read_status', [PolicyDocument::READING, PolicyDocument::FAILED])->orderBy('title')->get(['id', 'title', 'read_status', 'read_error'])
                : collect(),
        ])->title(__('policies.title'));
    }

    protected function uploadErrorKey(): string
    {
        return $this->replacingId === null ? 'newPolicy' : 'replacement';
    }

    private function authorizeManage(): void
    {
        abort_unless(PolicyFolder::canManage(auth()->user()), 403);
    }
}
