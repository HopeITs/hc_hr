<?php

namespace App\Http\Controllers;

use App\Models\RecruitmentInterview;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class RecruitmentInterviewAttachmentController extends Controller
{
    public function __invoke(Media $media): BinaryFileResponse
    {
        abort_unless(
            $media->model_type === RecruitmentInterview::class
            && $media->collection_name === RecruitmentInterview::ATTACHMENTS,
            404,
        );

        $interview = RecruitmentInterview::query()
            ->visibleTo(auth()->user())
            ->findOrFail($media->model_id);
        Gate::authorize('view', $interview);
        abort_unless($media->disk === 'local' && is_file($media->getPath()), 404);

        $original = basename((string) $media->getCustomProperty('original_name', $media->file_name));
        $safeName = Str::limit((string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $original), 180, '');

        return response()->download($media->getPath(), $safeName ?: 'interview-attachment', [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
