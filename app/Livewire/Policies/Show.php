<?php

namespace App\Livewire\Policies;

use App\Models\PolicyDocument;
use App\Services\Assistant\PolicyLibrary;
use App\Support\Search\Highlight;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * One policy, in passages, for every signed-in employee. Opened from the search with the typed
 * words (`q`) and the passage that matched (`p`): the words are marked and the page scrolls to
 * that passage. Read only.
 */
class Show extends Component
{
    #[Locked]
    public int $documentId;

    #[Url(as: 'q', except: '')]
    public string $q = '';

    #[Url(as: 'p')]
    public ?int $p = null;

    public function mount(int $document): void
    {
        $this->documentId = PolicyDocument::query()->readable()->findOrFail($document)->id;
    }

    public function render(PolicyLibrary $library): View
    {
        $document = PolicyDocument::query()->readable()->findOrFail($this->documentId);
        $terms = $library->highlightTerms(mb_substr($this->q, 0, 80));

        $passages = array_map(
            fn (string $text): string => Highlight::html($text, $terms),
            $library->passagesOf((string) $document->content),
        );

        return view('livewire.policies.show', [
            'document' => $document,
            'passages' => $passages,
            'target' => $this->p !== null && isset($passages[$this->p]) ? $this->p : null,
            'marked' => $terms !== [],
        ])->title($document->title);
    }
}
