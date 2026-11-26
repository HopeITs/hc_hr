<?php

namespace App\Notifications;

use App\Models\News;
use App\Support\News\NewsNotificationText;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** HR-040: HR approved (published) or rejected the manager's news item. */
class NewsApprovalDecided extends Notification
{
    use Queueable;

    public function __construct(public News $news, public bool $approved) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        [$titleEn, $titleAr] = NewsNotificationText::titles($this->news);

        if ($this->approved) {
            return [
                'title' => [
                    'en' => 'Your news was approved',
                    'ar' => 'تمت الموافقة على خبرك',
                ],
                'body' => [
                    'en' => "HR approved «{$titleEn}». It is published to the audience you chose.",
                    'ar' => "وافقت الموارد البشرية على «{$titleAr}»، ونُشر للجمهور الذي اخترته.",
                ],
                'url' => route('news.preview', $this->news->id),
            ];
        }

        $reason = (string) $this->news->review_note;

        return [
            'title' => [
                'en' => 'Your news was not approved',
                'ar' => 'لم تتم الموافقة على خبرك',
            ],
            'body' => [
                'en' => "HR did not approve «{$titleEn}». Reason: {$reason}. You can edit it and send it again.",
                'ar' => "لم توافق الموارد البشرية على «{$titleAr}». السبب: {$reason}. يمكنك تعديله وإرساله من جديد.",
            ],
            'url' => route('news.preview', $this->news->id),
            'comment' => $reason,
        ];
    }
}
