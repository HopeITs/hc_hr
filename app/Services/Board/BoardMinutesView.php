<?php

namespace App\Services\Board;

use App\Models\BoardMeeting;
use App\Models\BoardMeetingAttendee;

/** What the printed minutes (page and PDF) are made of, in the order of the board's own sheet. */
class BoardMinutesView
{
    /** @return array<string, mixed> */
    public static function data(BoardMeeting $meeting, bool $inlineAssets = false): array
    {
        $meeting->load(['sections', 'followUps.employee', 'attendees.employee']);

        $names = fn (array $statuses) => $meeting->attendees
            ->whereIn('status', $statuses)
            ->map(fn (BoardMeetingAttendee $a) => $a->employee?->name)
            ->filter()->sort()->values();

        return [
            'meeting' => $meeting,
            'present' => $names([BoardMeetingAttendee::PRESENT, BoardMeetingAttendee::ONLINE]),
            'online' => $names([BoardMeetingAttendee::ONLINE]),
            'absent' => $names([BoardMeetingAttendee::ABSENT]),
            'draft' => ! $meeting->isPublished(),
            'inlineAssets' => $inlineAssets,
            'fileName' => self::fileName($meeting),
        ];
    }

    public static function fileName(BoardMeeting $meeting): string
    {
        return sprintf('board-minutes-%s-%s.pdf', str_pad((string) $meeting->number, 3, '0', STR_PAD_LEFT), $meeting->starts_at->format('Ymd'));
    }
}
