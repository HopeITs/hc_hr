<?php

namespace App\Livewire\Concerns;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Livewire\Attributes\Locked;
use Stringable;

/**
 * The close guard of an editable dialog (A5-M).
 *
 * The dialog passes `close-action="requestClose"` to <x-modal>/<x-modal.dialog>.
 * Its backdrop is then ignored, and Escape, the header close and Cancel all
 * call requestClose() with the dialog's id, which by default is the name of
 * the bool property that shows it (showModal, formModal, ...):
 *
 *  - entries equal to what the dialog opened with: it closes at once;
 *  - otherwise one confirmation, "Discard your changes?", whose dismiss button
 *    is "Keep editing". Only its "Discard changes" answer clears the form.
 *
 * Neither path writes a record or navigates. The baseline is held in memory,
 * per dialog, and never in browser storage.
 *
 * The defaults fit the project-basics modules (one `showModal` editing an
 * `$item` array); other components override the hooks below. Every
 * component calls markClean() once a dialog's form is filled, just before
 * showing it.
 *
 * Needs WithFeedback.
 */
trait GuardsModalClose
{
    /**
     * Normalized entries each dialog opened with, keyed by dialog. Locked: the
     * browser can neither fake nor clear it.
     *
     * @var array<string, mixed>
     */
    #[Locked]
    public array $closeBaselines = [];

    /** The one close path of a guarded dialog. */
    public function requestClose(string $dialog = 'showModal'): void
    {
        $this->assertGuardedDialog($dialog);

        // Already closed, e.g. a close queued behind a save that succeeded.
        if (! $this->guardedDialogIsOpen($dialog)) {
            return;
        }

        if (! $this->isDirty($dialog)) {
            $this->discardDialog($dialog);

            return;
        }

        $this->confirm(
            'discardChanges',
            $dialog,
            title: __('general.unsaved.title'),
            message: __('general.unsaved.message'),
            confirmLabel: __('general.unsaved.discard'),
            cancelLabel: __('general.unsaved.keep-editing'),
            danger: false,
        );
    }

    /** Only after the explicit "Discard changes" answer. */
    public function discardChanges(string $dialog = 'showModal'): void
    {
        $this->assertGuardedDialog($dialog);

        $this->discardDialog($dialog);
    }

    /** The dialogs this guard covers: the bool properties that show them. */
    protected function guardedDialogs(): array
    {
        return ['showModal'];
    }

    protected function guardedDialogIsOpen(string $dialog): bool
    {
        return (bool) $this->{$dialog};
    }

    /** What the user can change in $dialog. */
    protected function guardedEntries(string $dialog): array
    {
        return $this->item;
    }

    /** Hide $dialog and clear only its own transient form state. */
    protected function closeGuardedDialog(string $dialog): void
    {
        $this->{$dialog} = false;
        $this->resetValidation();
    }

    /** Remember $dialog's current entries as "unchanged" (on open, and after a save that keeps it open). */
    protected function markClean(string $dialog = 'showModal'): void
    {
        $this->closeBaselines[$dialog] = $this->normalizeEntries($this->guardedEntries($dialog));
    }

    /** A value changed and then restored is clean; so is a field that only received an input event. */
    protected function isDirty(string $dialog = 'showModal'): bool
    {
        return $this->normalizeEntries($this->guardedEntries($dialog)) !== ($this->closeBaselines[$dialog] ?? null);
    }

    private function discardDialog(string $dialog): void
    {
        $this->closeGuardedDialog($dialog);
        unset($this->closeBaselines[$dialog]);
    }

    /** $dialog arrives from the browser: only a listed dialog property may be touched. */
    private function assertGuardedDialog(string $dialog): void
    {
        abort_unless(in_array($dialog, $this->guardedDialogs(), true), 404);
    }

    /**
     * Compare what the user sees, not how PHP holds it: 5, "5" and " 5 " are
     * one entry, as are null and "", true and "1", "09:00:00" and "09:00", and
     * a multi-select's ids in any order. Strings are never parsed as numbers:
     * codes such as "001" and "1" stay different.
     */
    private function normalizeEntries(mixed $value): mixed
    {
        if (is_array($value)) {
            $value = array_map(fn ($entry) => $this->normalizeEntries($entry), $value);

            // A checkbox group or multi-select: a set of ids, where an empty
            // entry (explode() of an empty column) is no entry.
            if (array_is_list($value) && array_filter($value, 'is_array') === []) {
                $value = array_values(array_filter($value, fn ($entry) => $entry !== ''));
                sort($value, SORT_STRING);
            }

            return $value;
        }

        if ($value instanceof UploadedFile) {
            // A chosen file is always a change; the browser cannot restore it.
            return 'upload:'.$value->getFilename();
        }

        $value = match (true) {
            $value === null => '',
            is_bool($value) => $value ? '1' : '0',
            $value instanceof BackedEnum => (string) $value->value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            is_scalar($value), $value instanceof Stringable => (string) $value,
            default => json_encode($value),
        };

        $value = trim($value);

        return preg_match('/^(\d{2}:\d{2}):00$/', $value, $time) ? $time[1] : $value;
    }
}
