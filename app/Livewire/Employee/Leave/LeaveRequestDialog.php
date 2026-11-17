<?php

namespace App\Livewire\Employee\Leave;

use App\Models\LeaveRequest;
use App\Services\LeaveAlternativeEmployees;

/**
 * The new-request dialog of My Leaves on its own, for the team calendar and
 * the dashboard's calendar tile: a day from today on opens it on that date and
 * the employee stays on the page.
 * Closed, it reads nothing, so mounting it costs a page no query.
 * Same form, pricing, confirmation and save as MyLeaveRequestModule.
 *
 * The parent's query-string properties are redeclared without #[Url]: on the
 * calendar ?date= is the month shown, not a day to request.
 */
class LeaveRequestDialog extends MyLeaveRequestModule
{
    public ?int $linkedLeaveId = null;

    public bool $startNew = false;

    public string $startDate = '';

    protected $listeners = ['openLeaveRequestOn' => 'createOn'];

    public function mount()
    {
        // No counts, details or ?new=1 here: only the dialog is on this page.
    }

    /** Opens a new request starting (and, until changed, ending) on $date. */
    public function createOn(string $date): void
    {
        $this->create();
        $this->startDate = $date;
        $this->prefillStartDate();
        $this->startDate = '';
    }

    public function render()
    {
        $alternativeEmployees = $this->showModal
            ? $this->guardedLoad(
                fn () => app(LeaveAlternativeEmployees::class)->for(auth()->user()->profile),
                fallback: collect(),
            )
            : collect();

        return view('livewire.employee.leave.request-dialog', compact('alternativeEmployees'));
    }

    protected function finishSave(LeaveRequest $leaveRequest, string $kind): void
    {
        parent::finishSave($leaveRequest, $kind);

        // The calendars on the page redraw with the new request on its days.
        $this->dispatch('leaveRequestSaved');
    }
}
