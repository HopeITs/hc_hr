<?php

namespace App\Http\Controllers;

use App\Models\BoardMeeting;
use App\Services\Board\BoardAccess;
use App\Services\Board\BoardMinutesView;
use App\Services\ContractPdfRenderer;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The minutes as a PDF. A member reads published minutes; the chair and the secretary also read the draft
 * (marked as one on the sheet). Same 503 contract as the contract export when Chrome fails.
 */
class BoardMinutesPdfController extends Controller
{
    public function __invoke(BoardMeeting $meeting, BoardAccess $access, ContractPdfRenderer $renderer)
    {
        $user = auth()->user();

        abort_unless($access->canView($user), 403);
        abort_unless($meeting->isPublished() || $access->canSeeDraft($user), 403);
        abort_if($meeting->isCancelled(), 404);

        try {
            $pdf = $renderer->renderHtml(view('pdf.board-minutes', BoardMinutesView::data($meeting, inlineAssets: true))->render());
        } catch (Throwable $exception) {
            Log::error('Board minutes PDF failed.', ['meeting_id' => $meeting->id, 'user_id' => $user->id]);

            return response(__('board.minutes.pdf-failed'), 503);
        }

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.BoardMinutesView::fileName($meeting).'"',
        ]);
    }
}
