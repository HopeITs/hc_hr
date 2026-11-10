<?php

namespace App\Livewire\Recruitment\Applicants;

use App\Models\RecruitmentApplicant;
use App\Models\RecruitmentApplicantNote;
use App\Models\RecruitmentApplication;
use App\Services\RecruitmentApplicantNoteService;
use App\Services\RecruitmentScopeResolver;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Internal notes on one applicant.
 *
 * Deliberately knows nothing about application status: there is no field
 * here to carry one and no method that could write one. Moving an
 * application is the profile's separate, confirmed action.
 */
class Notes extends Component
{
    #[Locked]
    public int $applicantId;

    /** Chosen in the browser, so re-resolved through the scope on save. */
    public ?int $applicationId = null;

    public string $body = '';

    public bool $sensitive = false;

    #[Locked]
    public ?int $deletingNoteId = null;

    public string $deletionReason = '';

    public function mount(int $applicantId): void
    {
        $this->applicantId = $applicantId;
        $this->resolveApplicant();

        $applications = $this->visibleApplications();
        if ($applications->count() === 1) {
            $this->applicationId = $applications->first()->getKey();
        }
    }

    public function addNote(RecruitmentApplicantNoteService $notes): void
    {
        $applicant = $this->resolveApplicant();

        $application = null;
        if ($this->applicationId !== null) {
            $application = RecruitmentApplication::query()
                ->visibleTo(auth()->user())
                ->where('applicant_id', $applicant->getKey())
                ->find($this->applicationId);

            if ($application === null) {
                $this->addError('applicationId', __('recruitment-applicant.notes.application_mismatch'));

                return;
            }
        }

        Gate::authorize('create', [RecruitmentApplicantNote::class, $applicant, $application]);

        try {
            $notes->add(auth()->user(), $applicant, $application, $this->body, $this->sensitive);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return;
        }

        $this->reset('body', 'sensitive');
        $this->resetErrorBag();
    }

    public function confirmDelete(int $noteId): void
    {
        $note = $this->resolveNote($noteId);
        Gate::authorize('delete', $note);

        $this->resetErrorBag();
        $this->deletingNoteId = $note->getKey();
        $this->deletionReason = '';

        $this->dispatch('focus-element', id: 'deletionReason');
    }

    public function deleteNote(RecruitmentApplicantNoteService $notes): void
    {
        abort_if($this->deletingNoteId === null, 404);

        $note = $this->resolveNote($this->deletingNoteId);
        Gate::authorize('delete', $note);

        try {
            $notes->delete(auth()->user(), $note, $this->deletionReason);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return;
        }

        $this->closeDeletePanel();

        // The note, and every button on it, is gone.
        $this->dispatch('focus-element', id: 'notes-title');
    }

    /** Back to the delete button that opened the panel. */
    public function cancelDelete(): void
    {
        $opener = 'note-'.$this->deletingNoteId.'-delete';

        $this->closeDeletePanel();
        $this->dispatch('focus-element', id: $opener);
    }

    /** The profile archived a CV, which may have written a note. */
    #[On('recruitment-notes-changed')]
    public function refreshNotes(): void {}

    public function render(RecruitmentScopeResolver $scopes)
    {
        $user = auth()->user();
        $applicant = $this->resolveApplicant();

        $notes = RecruitmentApplicantNote::query()
            ->visibleTo($user)
            ->where('applicant_id', $applicant->getKey())
            ->with(['actor.profile', 'application.campaign.vacancy.position'])
            ->latest('created_at')
            ->latest('id')
            ->get();

        $hasApplications = $applicant->applications()->exists();

        return view('livewire.recruitment.applicants.notes', [
            'notes' => $notes,
            'applications' => $this->visibleApplications(),
            'mayAdd' => $user->hasActivePermission('recruitment-applicants.add-notes'),
            // A note on the applicant alone only where there is no application to scope it by.
            'mayAddUnattached' => ! $hasApplications && $scopes->ownsApplicantRecord($user, $applicant),
            'deletingNote' => $this->deletingNoteId === null ? null : $notes->firstWhere('id', $this->deletingNoteId),
        ]);
    }

    private function closeDeletePanel(): void
    {
        $this->reset('deletingNoteId', 'deletionReason');
        $this->resetErrorBag('deletionReason');
    }

    private function visibleApplications()
    {
        return RecruitmentApplication::query()
            ->visibleTo(auth()->user())
            ->where('applicant_id', $this->applicantId)
            ->with('campaign.vacancy.position')
            ->latest('submitted_at')
            ->get();
    }

    private function resolveApplicant(): RecruitmentApplicant
    {
        $applicant = RecruitmentApplicant::query()->visibleTo(auth()->user())->findOrFail($this->applicantId);
        Gate::authorize('view', $applicant);

        return $applicant;
    }

    private function resolveNote(int $noteId): RecruitmentApplicantNote
    {
        $this->resolveApplicant();

        return RecruitmentApplicantNote::query()
            ->visibleTo(auth()->user())
            ->where('applicant_id', $this->applicantId)
            ->findOrFail($noteId);
    }
}
