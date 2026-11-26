<?php

namespace App\Livewire\Policies;

use App\Livewire\Concerns\WithFeedback;
use App\Livewire\Onboarding\MyFirstDays;
use App\Models\PolicyDocument;
use App\Services\Assistant\PolicyLibrary;
use App\Services\Policies\PolicyFolder;
use App\Services\Policies\PolicyRenderer;
use App\Services\PolicyAcknowledgements;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * One policy for every signed-in employee: its text as a formatted page, laid
 * out by the policy's own language, with its original file to open or
 * download. Opened from a search with the typed words (`q`) and the passage
 * that matched (`p`): the words are marked and the page scrolls there.
 *
 * A required policy asks for agreement when it is due: a new volunteer who
 * has not agreed yet, or anyone who agreed to an earlier version.
 */
class Show extends Component
{
    use WithFeedback;

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

    public function agree(PolicyAcknowledgements $acknowledgements): void
    {
        $document = PolicyDocument::query()->readable()->findOrFail($this->documentId);
        $employee = auth()->user()?->profile;

        abort_unless($employee !== null && $this->agreementDue($document, $acknowledgements), 403);

        $acknowledgements->acknowledge($employee, $document);
        $this->toast(__('policies.agreed'), 'success');
    }

    public function render(PolicyLibrary $library, PolicyRenderer $renderer, PolicyAcknowledgements $acknowledgements): View
    {
        $document = PolicyDocument::query()->readable()->with('media')->findOrFail($this->documentId);
        $terms = $library->highlightTerms(mb_substr($this->q, 0, 80));
        $employee = auth()->user()?->profile;

        $passages = array_map(
            fn (string $markdown): string => $renderer->html($markdown, $terms),
            $library->passagesOf((string) $document->content),
        );

        return view('livewire.policies.show', [
            'document' => $document,
            'passages' => $passages,
            'target' => $this->p !== null && isset($passages[$this->p]) ? $this->p : null,
            'direction' => $document->direction(),
            'standing' => $employee ? $acknowledgements->standing($employee, $document) : null,
            'agreementDue' => $this->agreementDue($document, $acknowledgements),
            'canManage' => PolicyFolder::canManage(auth()->user()),
        ])->title($document->title);
    }

    private function agreementDue(PolicyDocument $document, PolicyAcknowledgements $acknowledgements): bool
    {
        $user = auth()->user();
        $employee = $user?->profile;

        if ($employee === null || ! $document->required_for_onboarding || $document->onboarding_kind !== PolicyDocument::KIND_POLICY) {
            return false;
        }

        return match ($acknowledgements->standing($employee, $document)) {
            'agreed' => false,
            'outdated' => true,
            default => MyFirstDays::inMenuFor($user),
        };
    }
}
