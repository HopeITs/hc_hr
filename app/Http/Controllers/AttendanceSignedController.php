<?php

namespace App\Http\Controllers;

use App\Models\AttendanceMonth;
use App\Services\Attendance\Roster;
use Carbon\CarbonImmutable;

/**
 * The signed paper of a person's month, for anyone who may see that person's timesheet (Roster).
 * It lives on the private disk and is never reachable by URL guessing.
 */
class AttendanceSignedController extends Controller
{
    public function __invoke(AttendanceMonth $month, Roster $roster)
    {
        $employee = $month->employee;
        $media = $month->getFirstMedia('signed');

        abort_unless($employee && $media, 404);
        abort_unless($roster->covers(auth()->user(), $employee, CarbonImmutable::instance($month->month)), 403);

        return response()->file($media->getPath(), [
            'Content-Type' => $media->mime_type,
            'Content-Disposition' => 'inline; filename="'.$media->file_name.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
