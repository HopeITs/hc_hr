<?php

namespace App\Http\Controllers;

use App\Models\BoardMeeting;
use App\Services\Board\BoardAccess;
use App\Services\Board\BoardArchiveImporter;
use Illuminate\Support\Facades\Storage;

/**
 * The original file of old minutes (from before the system), read back from the private disk for the board only.
 * A PDF opens in the page (?download=1 saves it); a Word file is always a download.
 */
class BoardArchiveFileController extends Controller
{
    public function __invoke(BoardMeeting $meeting, BoardAccess $access)
    {
        abort_unless($access->canView(auth()->user()), 403);
        abort_unless($meeting->isArchive() && $meeting->archive_path, 404);

        $disk = Storage::disk(BoardArchiveImporter::DISK);
        abort_unless($disk->exists($meeting->archive_path), 404);

        $extension = strtolower(pathinfo($meeting->archive_path, PATHINFO_EXTENSION));
        $name = 'board-minutes-'.str_pad((string) $meeting->number, 3, '0', STR_PAD_LEFT).'.'.$extension;
        $inline = $extension === 'pdf' && ! request()->boolean('download');

        return $disk->response($meeting->archive_path, $name, [
            'Content-Type' => $meeting->archive_mime ?: ($extension === 'pdf' ? 'application/pdf' : 'application/octet-stream'),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], $inline ? 'inline' : 'attachment');
    }
}
