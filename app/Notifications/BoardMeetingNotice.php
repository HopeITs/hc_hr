<?php

namespace App\Notifications;

use App\Models\BoardMeeting;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Everything the board hears about its meetings, in the app: a meeting opened, moved or cancelled,
 * the reminder the day before, a member's request to be absent and its answer, and the minutes.
 */
class BoardMeetingNotice extends Notification
{
    use Queueable;

    public const SCHEDULED = 'scheduled';

    public const RESCHEDULED = 'rescheduled';

    public const CANCELLED = 'cancelled';

    public const REMINDER = 'reminder';

    public const ABSENCE_REQUESTED = 'absence-requested';

    public const ABSENCE_DECIDED = 'absence-decided';

    public const MINUTES = 'minutes';

    public const REOPENED = 'reopened';

    public const FOLLOW_UP = 'follow-up';

    public function __construct(
        public string $kind,
        public int $meetingId,
        public int $number,
        public string $startsAt,
        public ?string $detail = null,
    ) {}

    public static function for(string $kind, BoardMeeting $meeting, ?string $detail = null): self
    {
        return new self($kind, $meeting->id, $meeting->number, $meeting->starts_at->toIso8601String(), $detail);
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $when = \Illuminate\Support\Carbon::parse($this->startsAt);
        $en = $when->copy()->locale('en')->translatedFormat('l j F Y, H:i');
        $ar = $when->copy()->locale('ar')->translatedFormat('l j F Y، H:i');
        $n = $this->number;
        $d = $this->detail;

        $text = match ($this->kind) {
            self::SCHEDULED => [
                ['Board meeting scheduled', 'تم تحديد اجتماع للبورد'],
                ["Meeting {$n} is set for {$en}.", "اجتماع رقم {$n} في {$ar}."],
            ],
            self::RESCHEDULED => [
                ['Board meeting moved', 'تم تعديل موعد اجتماع البورد'],
                ["Meeting {$n} is now on {$en}.", "اجتماع رقم {$n} صار في {$ar}."],
            ],
            self::CANCELLED => [
                ['Board meeting cancelled', 'تم إلغاء اجتماع البورد'],
                ["Meeting {$n} ({$en}) is cancelled".($d ? ": {$d}" : '.'), "أُلغي اجتماع رقم {$n} ({$ar})".($d ? "، السبب: {$d}" : '.')],
            ],
            self::REMINDER => [
                ['Board meeting tomorrow', 'تذكير: اجتماع البورد غداً'],
                ["Meeting {$n} is on {$en}.", "اجتماع رقم {$n} في {$ar}."],
            ],
            self::ABSENCE_REQUESTED => [
                ['Absence request', 'طلب تغيّب عن اجتماع'],
                ["{$d} asks to miss meeting {$n} ({$en}).", "{$d} يطلب التغيّب عن اجتماع رقم {$n} ({$ar})."],
            ],
            self::ABSENCE_DECIDED => [
                ['Your absence request', 'ردّ على طلب تغيّبك'],
                ["Your request to miss meeting {$n}: {$d}.", "طلب تغيّبك عن اجتماع رقم {$n}: {$d}."],
            ],
            self::REOPENED => [
                ['Board minutes reopened', 'أُعيد فتح محضر اجتماع البورد'],
                ["The minutes of meeting {$n} were taken back for a correction: {$d}.", "أُعيد محضر اجتماع رقم {$n} للتصحيح: {$d}."],
            ],
            self::FOLLOW_UP => [
                ['A follow-up waits on you', 'بند متابعة مُسند إليك'],
                ["From the minutes of meeting {$n}: {$d}.", "من محضر اجتماع رقم {$n}: {$d}."],
            ],
            default => [
                ['Board minutes published', 'تم نشر محضر اجتماع البورد'],
                ["The minutes of meeting {$n} are ready to read.", "محضر اجتماع رقم {$n} جاهز للقراءة."],
            ],
        };

        return [
            'title' => ['en' => $text[0][0], 'ar' => $text[0][1]],
            'body' => ['en' => $text[1][0], 'ar' => $text[1][1]],
            'kind' => $this->kind,
            'meeting_id' => $this->meetingId,
            'url' => route('board.meetings.show', ['meeting' => $this->meetingId], false),
        ];
    }
}
