<?php

namespace App\Notifications;

use App\Models\BirthdayHolidayChoice;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * The direct manager approved or declined the day a volunteer asked for
 * instead of their birthday (meeting 19/11, track 3). The choice has no
 * reason field, so none is given.
 */
class BirthdayChoiceDecided extends Notification
{
    use Queueable;

    public function __construct(public BirthdayHolidayChoice $choice) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $approved = $this->choice->status === BirthdayHolidayChoice::APPROVED;
        $key = $approved ? 'approved' : 'rejected';

        return [
            'title' => [
                'en' => __('holidays.birthday.notify.'.$key.'-title', [], 'en'),
                'ar' => __('holidays.birthday.notify.'.$key.'-title', [], 'ar'),
            ],
            'body' => [
                'en' => __('holidays.birthday.notify.'.$key.'-body', ['date' => $this->choice->chosen_date->locale('en')->translatedFormat('l j F')], 'en'),
                'ar' => __('holidays.birthday.notify.'.$key.'-body', ['date' => $this->choice->chosen_date->locale('ar')->translatedFormat('l j F')], 'ar'),
            ],
            'url' => route('leaves.index'),
            'subject' => ['type' => 'birthday_holiday_choice', 'id' => $this->choice->id, 'action' => null],
        ];
    }
}
