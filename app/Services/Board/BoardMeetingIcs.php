<?php

namespace App\Services\Board;

use App\Models\BoardMeeting;

/**
 * A calendar invitation (iCalendar, RFC 5545) for one board meeting, so it lands in a member's own calendar app.
 * The UID stays the same for the meeting, so a moved or cancelled meeting updates the entry already there.
 */
class BoardMeetingIcs
{
    public static function make(BoardMeeting $meeting): string
    {
        $utc = fn ($at) => $at->copy()->utc()->format('Ymd\THis\Z');
        $ends = $meeting->ends_at ?? $meeting->starts_at->copy()->addMinutes((int) config('board.duration_minutes', 90));
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'hope-hr';
        $title = 'اجتماع مجلس الإدارة رقم '.$meeting->number;
        $place = trim(($meeting->location ?? '').($meeting->room ? ' - الغرفة '.$meeting->room : ''));

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Hope Center//HopeHR Board//AR',
            'CALSCALE:GREGORIAN',
            'METHOD:'.($meeting->isCancelled() ? 'CANCEL' : 'PUBLISH'),
            'BEGIN:VEVENT',
            'UID:board-meeting-'.$meeting->id.'@'.$host,
            'SEQUENCE:'.$meeting->updated_at?->timestamp,
            'DTSTAMP:'.$utc(now()),
            'DTSTART:'.$utc($meeting->starts_at),
            'DTEND:'.$utc($ends),
            'SUMMARY:'.self::escape($title),
            $place !== '' ? 'LOCATION:'.self::escape($place) : null,
            $meeting->purpose ? 'DESCRIPTION:'.self::escape($meeting->purpose) : null,
            'URL:'.route('board.meetings.show', $meeting->id),
            'STATUS:'.($meeting->isCancelled() ? 'CANCELLED' : 'CONFIRMED'),
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        return implode("\r\n", array_filter($lines, fn ($l) => $l !== null))."\r\n";
    }

    public static function fileName(BoardMeeting $meeting): string
    {
        return 'board-meeting-'.str_pad((string) $meeting->number, 3, '0', STR_PAD_LEFT).'.ics';
    }

    private static function escape(string $text): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\,', '\n', '\n'], $text);
    }
}
