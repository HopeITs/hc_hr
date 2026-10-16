<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Concerns\WithFeedback;
use App\Models\EmployeeNote;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The signed-in user's personal notes on the dashboard.
 *
 * Two defects were fixed here and both are worth stating, because the second
 * hid the first:
 *
 *  1. The employee id arrived as a public, client-writable Livewire property.
 *     Anything the browser sent was trusted, so editing the snapshot let a
 *     user read, create, edit, and delete another employee's notes.
 *  2. The view mounted the component with `auth()->id()` — a *User* id — while
 *     the component treated it as an *Employee* id. Notes were therefore filed
 *     against whichever employee happened to share the user's numeric id.
 *
 * There is now no client-supplied identifier at all: the owner is derived from
 * the session on every single operation. #[Locked] on the cached id is
 * defence-in-depth, not the control.
 */
class EmployeeNotesCard extends Component
{
    use WithFeedback;

    #[Locked]
    public ?int $employeeId = null;

    public string $newText = '';

    public function mount(): void
    {
        $this->employeeId = $this->ownerId();
    }

    public function getNotesProperty()
    {
        return $this->notesQuery()->orderBy('order')->get();
    }

    public function addText(): void
    {
        $this->createNote($this->newText === '' ? 'New Note' : $this->newText, 'note');

        $this->newText = '';
    }

    public function createNote(string $content, string $type): void
    {
        $ownerId = $this->ownerId();

        if ($ownerId === null) {
            $this->skipRender();

            return;
        }

        $content = trim($content);

        if ($content === '') {
            return;
        }

        // Only the two types the card renders; 'type' reaches this method
        // straight from the browser.
        $type = in_array($type, ['note', 'task'], true) ? $type : 'note';

        EmployeeNote::create([
            'employee_id' => $ownerId,
            'type' => $type,
            'content' => $content,
            'is_done' => false,
            'order' => ($this->notesQuery()->max('order') ?? 0) + 1,
            'color' => '#58595b',
        ]);
    }

    public function toggleDone(int $id): void
    {
        $note = $this->findOwned($id);
        $note->update(['is_done' => ! $note->is_done]);
    }

    public function updateContent(int $id, string $content): void
    {
        $content = trim($content);

        if ($content === '') {
            return;
        }

        $this->findOwned($id)->update(['content' => $content]);
    }

    public function delete(int $id): void
    {
        $note = $this->findOwned($id);
        $snapshot = $note->only(['type', 'content', 'is_done', 'order']);

        $note->delete();

        $this->toast(__('general.note-deleted'), undo: 'restoreNote', undoPayload: $snapshot);
    }

    /**
     * Undo for delete(). The snapshot makes a round trip through the browser,
     * so it is treated like createNote() input: the owner comes from the
     * session and every field is re-checked.
     */
    public function restoreNote(array $snapshot): void
    {
        $ownerId = $this->ownerId();
        $content = trim((string) ($snapshot['content'] ?? ''));

        if ($ownerId === null || $content === '') {
            return;
        }

        EmployeeNote::create([
            'employee_id' => $ownerId,
            'type' => in_array($snapshot['type'] ?? null, ['note', 'task'], true) ? $snapshot['type'] : 'note',
            'content' => $content,
            'is_done' => (bool) ($snapshot['is_done'] ?? false),
            'order' => (int) ($snapshot['order'] ?? 0),
            'color' => '#111827',
        ]);

        $this->toast(__('general.note-restored'));
    }

    public function render()
    {
        return view('livewire.dashboard.employee-notes-card', [
            'notes' => $this->notes,
        ]);
    }

    /**
     * The employee record behind the current session.
     *
     * Resolved fresh from the authenticated user on every call rather than
     * read back from component state, so a tampered snapshot changes nothing.
     */
    private function ownerId(): ?int
    {
        $employeeId = auth()->user()?->employee_id;

        return $employeeId ? (int) $employeeId : null;
    }

    /** Every query and mutation goes through this scope. */
    private function notesQuery(): Builder
    {
        $ownerId = $this->ownerId();

        // A user without a linked employee record has no notes and cannot
        // create any; returning an impossible query keeps the card rendering
        // empty instead of erroring on the dashboard.
        if ($ownerId === null) {
            return EmployeeNote::query()->whereRaw('1 = 0');
        }

        return EmployeeNote::query()->forEmployee($ownerId);
    }

    /**
     * Look a note up *through* the owner scope.
     *
     * findOrFail on the scoped query means a note id belonging to someone else
     * is a 404, not a permission decision made after the row was already read.
     */
    private function findOwned(int $id): EmployeeNote
    {
        return $this->notesQuery()->findOrFail($id);
    }
}
