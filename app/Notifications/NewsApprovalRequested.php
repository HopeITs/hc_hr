<?php

namespace App\Notifications;

use App\Models\News;
use App\Support\News\NewsNotificationText;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** HR-040: a manager's news item is for more than their team and waits for HR's approval. */
class NewsApprovalRequested extends Notification
{
    use Queueable;

    public function __construct(public News $news) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        [$titleEn, $titleAr] = NewsNotificationText::titles($this->news);
        [$authorEn, $authorAr] = NewsNotificationText::author($this->news);

        return [
            'title' => [
                'en' => 'News waiting for your approval',
                'ar' => 'خبر بانتظار موافقتك',
            ],
            'body' => [
                'en' => "{$authorEn} wants to publish «{$titleEn}» beyond their team. Approve or reject it.",
                'ar' => "يريد {$authorAr} نشر «{$titleAr}» لجمهور أوسع من فريقه. وافق عليه أو ارفضه.",
            ],
            'url' => route('news.preview', $this->news->id),
        ];
    }
}
