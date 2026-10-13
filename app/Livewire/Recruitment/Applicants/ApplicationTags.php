<?php

namespace App\Livewire\Recruitment\Applicants;

use App\Models\RecruitmentApplication;
use App\Models\RecruitmentApplicationTag;
use App\Models\RecruitmentTag;
use App\Services\RecruitmentTagService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The tags on one application, with who put each one there and when.
 *
 * Internal only. The application is re-resolved through the scope on every
 * request, and there is no status field here to carry: tagging never moves
 * an application.
 */
class ApplicationTags extends Component
{
    #[Locked]
    public int $applicationId;

    public string $tagId = '';

    public string $newTagName = '';

    public string $newTagColor = 'neutral';

    public function mount(int $applicationId): void
    {
        $this->applicationId = $applicationId;
        $this->resolveApplication();
    }

    public function attach(RecruitmentTagService $tags): void
    {
        $application = $this->resolveApplication();
        Gate::authorize('tag', $application);

        if ($this->run(fn () => $tags->attach(auth()->user(), $application, $this->tagId))) {
            $this->reset('tagId');
        }
    }

    public function detach(RecruitmentTagService $tags, mixed $tagId): void
    {
        $application = $this->resolveApplication();
        Gate::authorize('tag', $application);

        // The remove button went with the tag.
        if ($this->run(fn () => $tags->detach(auth()->user(), $application, $tagId))) {
            $this->dispatch('focus-element', id: 'tags-'.$this->applicationId.'-title');
        }
    }

    /** A new name goes on the shared tag list, so this also needs manage-tags. */
    public function createAndAttach(RecruitmentTagService $tags): void
    {
        $application = $this->resolveApplication();
        Gate::authorize('tag', $application);
        Gate::authorize('create', RecruitmentTag::class);

        // One transaction: a tag is never left on the list without the
        // assignment it was created for.
        $done = $this->run(fn () => DB::transaction(function () use ($tags, $application): void {
            $tag = $tags->create(auth()->user(), $this->newTagName, $this->newTagColor);
            $tags->attach(auth()->user(), $application, $tag->getKey());
        }));

        if ($done) {
            $this->reset('newTagName', 'newTagColor');
        }
    }

    #[On('recruitment-tags-changed')]
    public function refreshTags(): void
    {
        // Re-renders through the same scoped lookup; writes nothing.
    }

    public function render()
    {
        $application = $this->resolveApplication();

        // whereHas('tag') applies the tag's soft-delete scope: a deleted
        // tag's assignments stay in the table but leave the screen.
        $assignments = RecruitmentApplicationTag::query()
            ->where('application_id', $application->getKey())
            ->whereHas('tag')
            ->with(['tag', 'creator.profile'])
            ->oldest('created_at')
            ->get();

        $mayTag = Gate::allows('tag', $application);

        return view('livewire.recruitment.applicants.application-tags', [
            'assignments' => $assignments,
            'mayTag' => $mayTag,
            'mayCreate' => $mayTag && Gate::allows('create', RecruitmentTag::class),
            'available' => $mayTag
                ? RecruitmentTag::query()->whereNotIn('id', $assignments->pluck('tag_id'))->orderBy('name')->get()
                : collect(),
            'palette' => array_keys(config('recruitment.tags.palette', [])),
        ]);
    }

    private function resolveApplication(): RecruitmentApplication
    {
        $application = RecruitmentApplication::query()
            ->visibleTo(auth()->user())
            ->findOrFail($this->applicationId);

        Gate::authorize('view', $application);

        return $application;
    }

    private function run(callable $action): bool
    {
        $this->resetErrorBag();

        try {
            $action();
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return false;
        }

        $this->dispatch('recruitment-tags-changed');

        return true;
    }
}
