<?php

namespace App\Notifications;

use App\Models\RecruitmentInterview;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a staff participant that an interview they are on was scheduled,
 * moved or cancelled, or that they were taken off it.
 *
 * A pointer, like RecruitmentApprovalAwaiting: a time and a link into the
 * system, where the interview — and its meeting link, for those allowed to
 * see it — is read live. No candidate details and no meeting link travel in
 * the mail or in the stored notification.
 */
class RecruitmentInterviewParticipantNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public const SCHEDULED = 'scheduled';

    public const RESCHEDULED = 'rescheduled';

    public const CANCELLED = 'cancelled';

    public const REMOVED = 'removed';

    /** Held: evaluation is open and the reviewer's own is needed. */
    public const EVALUATION_OPEN = 'evaluation_open';

    public function __construct(
        public RecruitmentInterview $interview,
        public string $kind,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('recruitment-applicant.interviews.notify.subject.'.$this->kind))
            ->line(__('recruitment-applicant.interviews.notify.line.'.$this->kind, ['when' => $this->when()]))
            ->action(__('recruitment-applicant.interviews.notify.open'), $this->url());
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => [
                'en' => __('recruitment-applicant.interviews.notify.subject.'.$this->kind, [], 'en'),
                'ar' => __('recruitment-applicant.interviews.notify.subject.'.$this->kind, [], 'ar'),
            ],
            'body' => [
                'en' => __('recruitment-applicant.interviews.notify.line.'.$this->kind, ['when' => $this->when()], 'en'),
                'ar' => __('recruitment-applicant.interviews.notify.line.'.$this->kind, ['when' => $this->when()], 'ar'),
            ],
            'url' => $this->url(),
        ];
    }

    private function when(): string
    {
        $timezone = $this->interview->timezone ?: config('app.timezone');

        return $this->interview->scheduled_at->copy()->setTimezone($timezone)->format('Y-m-d H:i').' ('.$timezone.')';
    }

    private function url(): string
    {
        $url = route('recruitment.interviews.show', ['interviewId' => $this->interview->getKey()]);

        // Straight to the reviewer's own form.
        return $this->kind === self::EVALUATION_OPEN ? $url.'#my-evaluation' : $url;
    }
}
