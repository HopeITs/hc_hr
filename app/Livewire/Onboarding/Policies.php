<?php

namespace App\Livewire\Onboarding;

use App\Livewire\Concerns\WithFeedback;
use App\Models\PolicyDocument;
use App\Services\PolicyAcknowledgements;
use Illuminate\View\View;
use Livewire\Component;

/**
 * The policies a new volunteer reads and agrees to, from their own account
 * (track 7). It only ever reads and writes for the signed-in user's own
 * employee, so one volunteer cannot acknowledge for another.
 */
class Policies extends Component
{
    use WithFeedback;

    public function mount(): void
    {
        abort_unless(auth()->user()?->employee_id !== null, 403);
    }

    public function acknowledge(int $documentId, PolicyAcknowledgements $policies): void
    {
        $employee = auth()->user()?->profile;
        abort_unless($employee !== null, 403);

        $document = PolicyDocument::query()->findOrFail($documentId);
        $done = $policies->acknowledge($employee, $document);

        $this->toast($done ? __('onboarding.policies.acknowledged') : __('employees.journey.nothing-changed'), $done ? 'success' : 'info');
    }

    public function render(PolicyAcknowledgements $policies): View
    {
        $employee = auth()->user()->profile;
        abort_unless($employee !== null, 403);

        return view('livewire.onboarding.policies', [
            'sections' => [
                PolicyDocument::KIND_POLICY => $policies->status($employee, PolicyDocument::KIND_POLICY),
                PolicyDocument::KIND_REVIEW => $policies->status($employee, PolicyDocument::KIND_REVIEW),
            ],
        ])->title(__('onboarding.policies.title'));
    }
}
