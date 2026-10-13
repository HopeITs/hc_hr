<?php

namespace App\Livewire\Recruitment\Applicants;

use App\Models\RecruitmentApplication;
use App\Services\RecruitmentApplicationStatusService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Rejects the applications selected in the table, and optionally emails each
 * candidate an apology.
 *
 * The ids come from the browser and are treated as such: the service
 * re-resolves each through the scope, drops what the user cannot see, and
 * leaves anything past screening alone. The permission is asked in every
 * method, including the event that opens the panel, because Livewire
 * delivers events through a call of its own.
 */
class BulkReject extends Component
{
    /** @var array<int, int> from the browser; re-scoped before any write */
    public array $applicationIds = [];

    public bool $open = false;

    public string $reasonCode = '';

    /** Internal: stored on each decision, never sent to anyone. */
    public string $reason = '';

    public bool $notify = true;

    /** @var array<string, int>|null */
    public ?array $result = null;

    #[On('recruitment-bulk-reject')]
    public function open(array $applicationIds = []): void
    {
        Gate::authorize('bulkReject', RecruitmentApplication::class);

        $this->resetErrorBag();
        $this->result = null;
        $this->applicationIds = collect($applicationIds)
            ->filter(fn ($id): bool => is_scalar($id) && filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $this->reasonCode = '';
        $this->reason = '';
        $this->notify = true;
        $this->open = true;

        // The panel sits above the table, often out of sight of the action that opened it.
        $this->dispatch('focus-element', id: 'bulk-reject-title');
    }

    public function apply(RecruitmentApplicationStatusService $statuses): void
    {
        Gate::authorize('bulkReject', RecruitmentApplication::class);
        $this->resetErrorBag();

        try {
            $this->result = $statuses->bulkReject(auth()->user(), $this->applicationIds, $this->reasonCode, $this->reason, $this->notify);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return;
        }

        $this->reset('applicationIds', 'open', 'reasonCode', 'reason', 'notify');
        $this->dispatch('recruitment-decisions-changed');
        $this->dispatch('focus-element', id: 'bulk-reject-result');
    }

    public function cancel(): void
    {
        $this->reset('applicationIds', 'open', 'reasonCode', 'reason', 'notify', 'result');
        $this->resetErrorBag();
        $this->dispatch('focus-element', id: 'applicants-title');
    }

    public function render(RecruitmentApplicationStatusService $statuses)
    {
        return view('livewire.recruitment.applicants.bulk-reject', [
            'reasons' => $statuses->rejectionReasons(),
            'limit' => $statuses->bulkLimit(),
            'needsReason' => $this->reasonCode === RecruitmentApplicationStatusService::REASON_OTHER,
        ]);
    }
}
