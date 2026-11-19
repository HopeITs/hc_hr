<?php

namespace App\Notifications;

use App\Models\BirthdayHolidayChoice;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * A volunteer asked for a working day instead of a birthday that is not one
 * (meeting 19/11, track 3). The direct manager decides; HR of the volunteer's
 * city gets the same notice for information only ($forHr), since the decision
 * stays with the direct manager (BirthdayHoliday::canDecide).
 */
class BirthdayChoiceSubmitted extends Notification
{
    use Queueable;

    public function __construct(public BirthdayHolidayChoice $choice, public bool $forHr = false) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $employee = $this->choice->employee;
        $key = $this->forHr ? 'submitted-hr-body' : 'submitted-body';

        return [
            'title' => [
                'en' => __('holidays.birthday.notify.submitted-title', [], 'en'),
                'ar' => __('holidays.birthday.notify.submitted-title', [], 'ar'),
            ],
            'body' => [
                'en' => __('holidays.birthday.notify.'.$key, ['name' => $employee?->nameForLocale('en'), 'date' => $this->date('en')], 'en'),
                'ar' => __('holidays.birthday.notify.'.$key, ['name' => $employee?->nameForLocale('ar'), 'date' => $this->date('ar')], 'ar'),
            ],
            'url' => $this->forHr && $employee ? route('employees.show', $employee->username) : route('leaves.index'),
            'subject' => ['type' => 'birthday_holiday_choice', 'id' => $this->choice->id, 'action' => $this->forHr ? null : 'decide'],
        ];
    }

    private function date(string $locale): string
    {
        return $this->choice->chosen_date->locale($locale)->translatedFormat('l j F');
    }
}
