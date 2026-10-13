<?php

namespace App\Notifications;

use App\Models\Employee;
use App\Models\RecruitmentJobOffer;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Phase 2 Task 10: the manager who requested the position learns that the
 * candidate he was waiting for is now an employee. In the application only;
 * the link opens the offer, which he follows read-only (PositionRequester),
 * where access is checked again.
 *
 * Not queued, like the other recruitment notices: the bell reads the
 * database row, and this deployment runs no queue worker.
 */
class RecruitmentCandidateHired extends Notification
{
    use Queueable;

    public function __construct(
        public RecruitmentJobOffer $offer,
        public Employee $employee,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $position = $this->offer->position;
        $name = fn (string $locale): string => $this->employee->nameForLocale($locale);
        $title = fn (string $locale): string => (string) ($position?->getAttribute('title_'.$locale) ?? '');

        return [
            'title' => [
                'en' => 'Your candidate was hired',
                'ar' => 'تم توظيف مرشّحك',
            ],
            'body' => [
                'en' => "{$name('en')} is now an employee for the position {$title('en')}.",
                'ar' => "أصبح {$name('ar')} موظفًا على منصب {$title('ar')}.",
            ],
            'url' => route('recruitment.offers.show', ['offerId' => $this->offer->getKey()]),
        ];
    }
}
