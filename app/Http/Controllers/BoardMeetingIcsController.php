<?php

namespace App\Http\Controllers;

use App\Models\BoardMeeting;
use App\Services\Board\BoardAccess;
use App\Services\Board\BoardMeetingIcs;

/** "Add to calendar": the meeting as an .ics file, for whoever may see the board's meetings. */
class BoardMeetingIcsController extends Controller
{
    public function __invoke(BoardMeeting $meeting, BoardAccess $access)
    {
        abort_unless($access->canView(auth()->user()), 403);

        return response(BoardMeetingIcs::make($meeting), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.BoardMeetingIcs::fileName($meeting).'"',
        ]);
    }
}
