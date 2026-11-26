<?php

namespace App\Notifications;

use App\Models\News;
use App\Support\News\NewsNotificationText;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** HR-040: for information, a manager published a news item to their own team. */
class NewsPublishedByManager extends Notification
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
                'en' => 'A manager published news to their team',
                'ar' => 'نشر مدير خبرًا لفريقه',
            ],
            'body' => [
                'en' => "{$authorEn} published «{$titleEn}» to their team. No action is needed.",
                'ar' => "نشر {$authorAr} «{$titleAr}» لفريقه. لا يلزم أي إجراء.",
            ],
            'url' => route('news.preview', $this->news->id),
        ];
    }
}
