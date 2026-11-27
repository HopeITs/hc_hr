<?php

namespace App\Livewire\Onboarding;

use App\Livewire\Concerns\WithFeedback;
use App\Models\PolicyDocument;
use App\Services\Policies\PolicyRenderer;
use App\Services\PolicyAcknowledgements;
use Illuminate\View\View;
use Livewire\Component;

/**
 * The policies a new volunteer reads and agrees to, from their own account
 * (track 7). It only ever reads and writes for the signed-in user's own
 * employee, so one volunteer cannot acknowledge for another.
 *
 * Each text is rendered like its own page (PolicyRenderer: HTML in it stays
 * escaped). A long one is shown collapsed with a link to the whole policy,
 * and one the employee agreed to an earlier version of says so, with the
 * date of the new version.
 */
class Policies extends Component
{
    use WithFeedback;

    /** Characters of text above which a policy is shown collapsed. */
    public const LONG = 1200;

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

    public function render(PolicyAcknowledgements $policies, PolicyRenderer $renderer): View
    {
        $employee = auth()->user()->profile;
        abort_unless($employee !== null, 403);

        $sections = [
            PolicyDocument::KIND_POLICY => $policies->status($employee, PolicyDocument::KIND_POLICY),
            PolicyDocument::KIND_REVIEW => $policies->status($employee, PolicyDocument::KIND_REVIEW),
        ];

        $texts = [];

        foreach ($sections as $status) {
            foreach ($status['required'] as $document) {
                $content = (string) $document->content;
                $texts[$document->id] = [
                    'html' => $renderer->html($content),
                    'long' => mb_strlen($content) > self::LONG,
                ];
            }
        }

        return view('livewire.onboarding.policies', [
            'sections' => $sections,
            'texts' => $texts,
            'outdated' => $policies->outdatedFor($employee),
        ])->title(__('onboarding.policies.title'));
    }
}
