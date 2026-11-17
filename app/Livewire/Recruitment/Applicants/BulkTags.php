<?php

namespace App\Livewire\Recruitment\Applicants;

use App\Models\RecruitmentApplication;
use App\Models\RecruitmentTag;
use App\Services\RecruitmentTagService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Adds or removes one tag on the applications selected in the table.
 *
 * The ids come from the browser and are treated as such: RecruitmentTagService
 * re-resolves each through the scope and skips any the user cannot see. The
 * permission is asked in every method, including the event that opens the
 * panel, because Livewire delivers events through a call of its own that the
 * permission map never sees.
 */
class BulkTags extends Component
{
    public const ATTACH = 'attach';

    public const DETACH = 'detach';

    /** @var array<int, int> from the browser; re-scoped before any write */
    public array $applicationIds = [];

    public bool $open = false;

    public string $mode = self::ATTACH;

    public string $tagId = '';

    public ?string $result = null;

    #[On('recruitment-bulk-tag')]
    public function open(array $applicationIds = []): void
    {
        Gate::authorize('bulkTag', RecruitmentApplication::class);

        $this->resetErrorBag();
        $this->result = null;
        $this->applicationIds = collect($applicationIds)
            ->filter(fn ($id): bool => is_scalar($id) && filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $this->open = true;

        // The panel sits above the table, often out of sight of the action that opened it.
        $this->dispatch('focus-element', id: 'bulk-tags-title');
    }

    public function apply(RecruitmentTagService $tags): void
    {
        Gate::authorize('bulkTag', RecruitmentApplication::class);

        if (! in_array($this->mode, [self::ATTACH, self::DETACH], true)) {
            $this->addError('mode', __('recruitment-applicant.tags.bulk_mode_invalid'));

            return;
        }

        try {
            $outcome = $this->mode === self::ATTACH
                ? $tags->bulkAttach(auth()->user(), $this->applicationIds, $this->tagId)
                : $tags->bulkDetach(auth()->user(), $this->applicationIds, $this->tagId);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return;
        }

        $this->result = __('recruitment-applicant.tags.bulk_'.$this->mode.'_done', $outcome);
        $this->reset('applicationIds', 'open', 'tagId');
        $this->dispatch('recruitment-tags-changed');
        $this->dispatch('focus-element', id: 'bulk-tags-result');
    }

    public function cancel(): void
    {
        $this->reset('applicationIds', 'open', 'tagId', 'mode', 'result');
        $this->resetErrorBag();
        $this->dispatch('focus-element', id: 'applicants-title');
    }

    public function render(RecruitmentTagService $tags)
    {
        return view('livewire.recruitment.applicants.bulk-tags', [
            'tags' => $this->open ? RecruitmentTag::query()->orderBy('name')->get() : collect(),
            'limit' => $tags->bulkLimit(),
        ]);
    }
}
