<?php

namespace App\Livewire\Policies;

use App\Livewire\Archives\ArchivedFilesModule;
use App\Models\PolicyDocument;
use App\Services\Assistant\PolicyLibrary;
use App\Services\Policies\PolicyFolder;
use App\Services\PolicyAcknowledgements;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The organisation's policies, for every signed-in employee: a box that
 * searches what they say (results grouped by policy, the best passage first
 * and up to two more), then the list. A policy with a new version the
 * employee has to agree to again is marked. HR sees where policies are
 * managed (the Policies folder) and any still being read or that failed.
 */
class Index extends Component
{
    #[Url(as: 'q', except: '')]
    public string $q = '';

    public function render(PolicyLibrary $library, PolicyAcknowledgements $acknowledgements, PolicyFolder $folder): View
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
            'folderUrl' => $canManage ? ArchivedFilesModule::folderUrl($folder->library()->id) : null,
            'unfinished' => $canManage
                ? PolicyDocument::query()->whereIn('read_status', [PolicyDocument::READING, PolicyDocument::FAILED])->orderBy('title')->get(['id', 'title', 'read_status', 'read_error'])
                : collect(),
        ])->title(__('policies.title'));
    }
}
