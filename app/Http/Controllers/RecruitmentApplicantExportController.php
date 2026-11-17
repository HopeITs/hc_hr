<?php

namespace App\Http\Controllers;

use App\Exceptions\RecruitmentExportRefusedException;
use App\Services\Recruitment\Privacy\RecruitmentApplicantExporter;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads the applications a user is entitled to see, as a CSV.
 *
 * A POST, so a link or an image on another site cannot start an export in a
 * signed-in user's browser. There is no permission middleware on the route on
 * purpose: the policy runs inside the exporter, so a refused attempt is
 * audited instead of stopping at the door. This only translates the outcome.
 */
class RecruitmentApplicantExportController extends Controller
{
    public function __construct(private readonly RecruitmentApplicantExporter $exports) {}

    public function __invoke(Request $request): StreamedResponse
    {
        $user = $request->user();

        try {
            $export = $this->exports->prepare($user, (array) $request->input('filters', []));
        } catch (RecruitmentExportRefusedException $exception) {
            if ($exception->status === Response::HTTP_UNPROCESSABLE_ENTITY) {
                throw ValidationException::withMessages(['export' => $exception->getMessage()]);
            }

            // A refusal for permission or scope says nothing about why.
            abort($exception->status, $exception->status === Response::HTTP_FORBIDDEN ? '' : $exception->getMessage());
        }

        return response()->streamDownload(
            fn () => $this->exports->write($user, $export),
            $export->filename(),
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Cache-Control' => 'private, no-store, max-age=0',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
