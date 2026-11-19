<?php

namespace App\Notifications;

use App\Models\Holiday;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * The volunteer's birthday falls on a weekend or holiday and no day has been
 * chosen instead (meeting 19/11, track 3). Sent once per (employee, year,
 * holiday) by birthday:notify-conflicts; the card on the leaves page picks the day.
 */
class BirthdayChoiceNeeded extends Notification
{
    use Queueable;

    public function __construct(public Holiday $holiday, public int $year, public CarbonImmutable $birthday) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => [
                'en' => __('holidays.birthday.notify.needed-title', [], 'en'),
                'ar' => __('holidays.birthday.notify.needed-title', [], 'ar'),
            ],
            'body' => [
                'en' => __('holidays.birthday.notify.needed-body', ['date' => $this->birthday->locale('en')->translatedFormat('l j F')], 'en'),
                'ar' => __('holidays.birthday.notify.needed-body', ['date' => $this->birthday->locale('ar')->translatedFormat('l j F')], 'ar'),
            ],
            'url' => route('leaves.index'),
            'subject' => ['type' => 'birthday_holiday', 'id' => $this->holiday->id, 'year' => $this->year, 'action' => 'choose'],
        ];
    }
}
