<?php

namespace App\Livewire\Concerns;

use App\Models\Employee;
use Illuminate\Validation\ValidationException;

/**
 * Phase 2 of saving an employee draft: server-side autosave (DEC-04,
 * B5D-Q10, docs/ux/employee-lifecycle.md §5.6).
 *
 * The browser asks for it a few seconds after the last change to the record
 * fields (resources/js/employee-autosave.js); nothing is ever kept in browser
 * storage. Each component's autosaveDraft() re-authorizes on the server and
 * then runs the same draft save as the "Save draft" button through here, so
 * the two can never accept different data.
 *
 * Results, as the browser reads them:
 *   - saved:      the draft row was written (or already held exactly this);
 *   - incomplete: the draft minimum does not validate yet. Nothing written,
 *                 and no error shown: the person is still typing;
 *   - complete:   the record is no longer a draft. Nothing written: a complete
 *                 record never goes back to draft;
 *   - busy:       an explicit save of the same form is running; try later.
 */
trait AutosavesEmployeeDraft
{
    /**
     * Run a draft save without touching the error list on screen: a failed
     * autosave adds no errors, and a successful one does not clear errors an
     * explicit save is still showing.
     *
     * @param  callable(): Employee  $save
     * @return array{status: string, time?: string, message?: string}
     */
    private function autosaveSilently(callable $save): array
    {
        $errors = $this->getErrorBag();

        try {
            $employee = $save();
        } catch (ValidationException) {
            return ['status' => 'incomplete'];
        } finally {
            $this->setErrorBag($errors);
        }

        return $this->autosaveSaved($employee);
    }

    /** @return array{status: string, time: string, message: string} */
    private function autosaveSaved(Employee $employee): array
    {
        $employee->loadMissing('lastSavedBy.profile');

        $time = $employee->updated_at?->timezone(config('app.timezone'))->format('H:i') ?? '';
        $by = $employee->lastSavedBy;

        return [
            'status' => 'saved',
            'time' => $time,
            'message' => __('employees.onboarding.autosave-saved', [
                'time' => $time,
                'name' => $by?->profile?->nameForLocale(app()->getLocale()) ?? $by?->email ?? __('general.unknown'),
            ]),
        ];
    }
}
