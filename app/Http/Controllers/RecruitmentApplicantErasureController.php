<?php

namespace App\Http\Controllers;

use App\Models\RecruitmentApplicant;
use App\Models\RecruitmentPrivacyAudit;
use App\Services\Recruitment\Privacy\RecruitmentErasureService;
use App\Services\Recruitment\Privacy\RecruitmentPrivacyAuditor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * HR records that a candidate asked for their data to be erased.
 *
 * The applicant is resolved here, not by route binding, and the permission is
 * checked by the policy rather than by middleware, for the reason the file
 * download does the same: every attempt has to reach the audit. The permission
 * is checked before the id is looked up, so a caller without it learns nothing
 * about which ids exist.
 */
class RecruitmentApplicantErasureController extends Controller
{
    public function __construct(
        private readonly RecruitmentErasureService $erasures,
        private readonly RecruitmentPrivacyAuditor $audit,
    ) {}

    public function store(Request $request, int $applicant): JsonResponse|RedirectResponse
    {
        $user = $request->user();

        $decision = Gate::inspect('requestErasure', RecruitmentApplicant::class);
        if ($decision->denied()) {
            $this->refused($request, $applicant, $decision->code() ?? 'denied');
            abort(Response::HTTP_FORBIDDEN);
        }

        $record = RecruitmentApplicant::query()->find($applicant);
        if (! $record instanceof RecruitmentApplicant) {
            $this->refused($request, $applicant, 'not_found');
            abort(Response::HTTP_NOT_FOUND);
        }

        $decision = Gate::inspect('requestErasure', $record);
        if ($decision->denied()) {
            $this->refused($request, $applicant, $decision->code() ?? 'denied');
            abort(Response::HTTP_FORBIDDEN);
        }

        $validated = $request->validate([
            'channel' => ['required', 'string', Rule::in(config('recruitment-privacy.erasure.channels'))],
            'confirm' => ['accepted'],
        ]);

        $result = $this->erasures->request($user, $record, $validated['channel']);

        if ($request->expectsJson()) {
            return response()->json($result, $result['status'] === RecruitmentErasureService::COMPLETED
                ? Response::HTTP_OK
                : Response::HTTP_ACCEPTED);
        }

        return redirect()
            ->route('recruitment.applicants.show', ['applicantId' => $record->getKey()])
            ->with('status', __("recruitment-privacy.erasure.{$result['status']}", [
                'blocker' => $result['blocker'] === null ? '' : __("recruitment-privacy.erasure.blockers.{$result['blocker']}"),
            ]));
    }

    private function refused(Request $request, int $applicantId, string $reason): void
    {
        $this->audit->record(
            RecruitmentPrivacyAudit::ACTION_ERASURE_REQUEST,
            RecruitmentPrivacyAudit::OUTCOME_REFUSED,
            $request->user(),
            subjectType: RecruitmentPrivacyAuditor::SUBJECT_APPLICANT,
            subjectId: $applicantId,
            reason: $reason,
        );
    }
}
