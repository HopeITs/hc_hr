<?php

namespace App\Livewire\Concerns;

use App\Livewire\Features\SupportToasts;
use App\Models\RecruitmentRequest;
use App\Services\RecruitmentRequestSubmissionService;
use App\Services\RecruitmentWorkflowConfigurationException;
use Illuminate\Validation\ValidationException;

/**
 * Submit (T3) or resubmit (T11) a request and say what came back.
 *
 * Shared by the details screen and the request form's "save & submit", so the
 * two cannot drift apart in what they report: the service owns every rule, and
 * this only turns its answer into words.
 */
trait SubmitsRecruitmentRequests
{
    /**
     * Submit and toast the outcome; true when it went through.
     *
     * Authorization is the caller's: Details authorizes, the form checks
     * Gate::allows first so a draft it may not submit stays a saved draft.
     */
    protected function submitRecruitmentRequest(RecruitmentRequest $request, ?RecruitmentRequestSubmissionService $submissions = null): bool
    {
        $refusal = $this->attemptSubmission($request, $submissions);

        $this->queueSubmissionToast($refusal ?? __('recruitment.submitted'), $refusal === null);

        return $refusal === null;
    }

    /**
     * Hand the request to the submission service: null on success, otherwise
     * the refusal as one sentence.
     *
     * Every refusal the service raises is keyed to a form field
     * (data.position_id, data.salary_amount). A screen that does not render
     * those keys would leave the message in the error bag and nowhere else, so
     * it is returned to be said out loud instead — every message, not the
     * first, so the requester is not sent round the loop finding them one at
     * a time.
     */
    protected function attemptSubmission(RecruitmentRequest $request, ?RecruitmentRequestSubmissionService $submissions = null): ?string
    {
        $submissions ??= app(RecruitmentRequestSubmissionService::class);

        try {
            $submissions->submit(auth()->user(), $request);
        } catch (ValidationException $e) {
            return collect($e->errors())->flatten()->unique()->values()->implode(' ');
        } catch (RecruitmentWorkflowConfigurationException $e) {
            // No (or more than one) active approval workflow for this type: a
            // set-up fault, not the requester's. The submission ran in a
            // transaction, so the request is still an untouched draft.
            report($e);

            return __('recruitment.workflow_not_configured');
        }

        return null;
    }

    private function queueSubmissionToast(string $message, bool $success = true): void
    {
        SupportToasts::queue($this, [
            'type' => $success ? 'success' : 'error',
            'message' => $message,
            'undo' => null,
        ]);
    }
}
