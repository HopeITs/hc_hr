<?php

namespace App\Livewire\Policies;

use App\Models\PolicyDocument;
use App\Services\Assistant\PolicyLibrary;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The organisation's policies, for every signed-in employee: the list, and a box that searches
 * what the policies say (the same search as the header's "Policies" results). Read only.
 */
class Index extends Component
{
    #[Url(as: 'q', except: '')]
    public string $q = '';

    public function render(PolicyLibrary $library): View
    {
        $term = trim(mb_substr($this->q, 0, 80));

        return view('livewire.policies.index', [
            'term' => $term,
            'marks' => $library->highlightTerms($term),
            'hits' => mb_strlen($term) >= 2 ? $library->search($term, 12) : [],
            'documents' => PolicyDocument::query()->readable()->orderBy('title')->get(['id', 'title', 'source_modified_at', 'updated_at']),
        ])->title(__('policies.title'));
    }
}
