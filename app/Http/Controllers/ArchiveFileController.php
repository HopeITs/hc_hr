<?php

namespace App\Http\Controllers;

use App\Models\Folder;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ArchiveFileController extends Controller
{
    public function preview(Media $media): BinaryFileResponse
    {
        $this->authorizeMediaAccess($media);

        abort_unless($this->isPreviewable($media), 404);

        return response()->file($media->getPath(), [
            'Content-Type' => $this->safeContentType($media),
            'Content-Disposition' => 'inline; filename="'.$this->safeDownloadName($media).'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Frame-Options' => 'SAMEORIGIN',
        ]);
    }

    public function download(Media $media): BinaryFileResponse
    {
        $this->authorizeMediaAccess($media);

        abort_unless($this->isAllowedStoredFile($media), 404);

        return response()->download($media->getPath(), $this->safeDownloadName($media), [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    public function officePreview(Media $media): BinaryFileResponse
    {
        $this->authorizeMediaAccess($media);
        abort_unless(in_array(strtolower($media->extension ?? ''), ['docx', 'xlsx', 'pptx'], true), 404);

        $path = storage_path('app/private/office-previews/'.$this->officePreviewFileName($media));

        abort_unless(is_file($path), 404);

        return response()->file($path, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.Str::slug(pathinfo($media->name, PATHINFO_FILENAME)).'-preview.pdf"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Frame-Options' => 'SAMEORIGIN',
        ]);
    }

    private function authorizeMediaAccess(Media $media): void
    {
        abort_if($media->model_type !== Folder::class, 404);

        $folder = Folder::findOrFail($media->model_id);
        $user = auth()->user();

        // The folders page's own rule: by name, through a group, or from a parent folder.
        abort_unless($user && app(\App\Services\Folders\FolderAccess::class)->canView($user, $folder), 403);
    }

    private function officePreviewFileName(Media $media): string
    {
        return $media->id.'-'.sha1($media->file_name.'|'.$media->size).'.pdf';
    }

    private function isPreviewable(Media $media): bool
    {
        return in_array(strtolower($media->extension ?? ''), ['jpg', 'jpeg', 'png', 'pdf'], true);
    }

    private function isAllowedStoredFile(Media $media): bool
    {
        return in_array(strtolower($media->extension ?? ''), \App\Livewire\Archives\ArchivedFilesModule::ALLOWED_UPLOAD_EXTENSIONS, true);
    }

    private function safeContentType(Media $media): string
    {
        return match (strtolower($media->extension ?? '')) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'pdf' => 'application/pdf',
            default => 'application/octet-stream',
        };
    }

    private function safeDownloadName(Media $media): string
    {
        $extension = strtolower($media->extension ?? pathinfo($media->file_name, PATHINFO_EXTENSION));
        $displayName = $media->name ?: $media->file_name;
        $baseName = pathinfo($displayName, PATHINFO_FILENAME);
        $safeBase = Str::of($baseName)
            ->replaceMatches('/[^A-Za-z0-9\-_ .\p{Arabic}]/u', '')
            ->squish()
            ->trim()
            ->limit(120, '');

        return ($safeBase->isNotEmpty() ? (string) $safeBase : 'archive-file').'.'.$extension;
    }
}
