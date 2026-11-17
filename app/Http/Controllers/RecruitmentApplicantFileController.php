<?php

namespace App\Http\Controllers;

use App\Models\RecruitmentApplicantFile;
use App\Services\ActivityLogger;
use App\Services\Monitoring\SecurityMonitor;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class RecruitmentApplicantFileController extends Controller
{
    public const AUDIT_LOG = 'recruitment-file-download';

    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly SecurityMonitor $monitor,
    ) {}

    /**
     * The file is resolved here, not by route model binding, and the permission
     * is checked by the policy, not by middleware: every attempt, including an
     * unknown id, must reach this method so it can be audited.
     */
    public function download(Request $request, int $file): BinaryFileResponse
    {
        $record = RecruitmentApplicantFile::query()->find($file);
        if (! $record instanceof RecruitmentApplicantFile) {
            $this->audit($request, $file, null, 'not_found', 'no_record');
            abort(Response::HTTP_NOT_FOUND);
        }

        $decision = Gate::inspect('download', $record);
        if ($decision->denied()) {
            $this->audit($request, $file, $record, 'denied', $decision->code() ?? 'denied');
            abort(Response::HTTP_FORBIDDEN);
        }

        $media = $record->getFirstMedia('document');
        if (! $media instanceof Media
            || $media->disk !== config('recruitment-uploads.clean_disk')
            || ! is_file($media->getPath())) {
            $this->audit($request, $file, $record, 'not_found', 'missing_on_disk');
            abort(Response::HTTP_NOT_FOUND);
        }

        $this->audit($request, $file, $record, 'success');

        // One person opening a great many CVs in a short while is what a
        // copied-out candidate pool looks like; the route's own throttle (60 a
        // minute) is far above what reviewing ever needs.
        $this->monitor->burst(SecurityMonitor::DOWNLOAD_REPEATED, 'downloads', (string) $request->user()->getKey());

        $response = response()->download($media->getPath(), $this->downloadName($record, $media), [
            // Detected from the bytes on upload; the browser's claim was never stored.
            'Content-Type' => $media->getCustomProperty('detected_mime', 'application/octet-stream'),
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');

        return $response;
    }

    private function downloadName(RecruitmentApplicantFile $file, Media $media): string
    {
        $extension = strtolower(pathinfo($media->file_name, PATHINFO_EXTENSION));

        // media.name is the name sanitized on upload, already ending in the
        // detected extension. Files stored before that held the field key.
        if (filled($media->name) && str_ends_with(strtolower($media->name), ".{$extension}")) {
            return $media->name;
        }

        return $file->file_type.($extension === '' ? '' : ".{$extension}");
    }

    private function audit(
        Request $request,
        int $fileId,
        ?RecruitmentApplicantFile $file,
        string $outcome,
        ?string $reason = null,
    ): void {
        $this->activity->log(
            "recruitment_file_download.{$outcome}",
            subject: $file,
            properties: [
                'file_id' => $fileId,
                'outcome' => $outcome,
                'reason' => $reason,
                'role' => $request->user()?->myRole(),
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
            ],
            logName: self::AUDIT_LOG,
        );
    }
}
