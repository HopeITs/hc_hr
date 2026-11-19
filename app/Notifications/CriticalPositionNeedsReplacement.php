<?php

namespace App\Notifications;

use App\Models\Position;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * A critical position has no holder (meeting 19/11, track 6): HR of the city and
 * the direct manager are told once per episode. HR gets a link to the suggested
 * replacements; the manager, who may not see that panel, gets the position page.
 */
class CriticalPositionNeedsReplacement extends Notification
{
    use Queueable;

    public function __construct(public Position $position, public string $reason) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $title = ['en' => $this->position->title_en, 'ar' => $this->position->title_ar];
        $ended = $this->reason === 'contract_ended';

        $url = method_exists($notifiable, 'hasActivePermission') && $notifiable->hasActivePermission('positions.view-replacements')
            ? route('positions.replacements', $this->position)
            : route('org.chart');

        return [
            'title' => [
                'en' => "Critical position needs a replacement: {$title['en']}",
                'ar' => "منصب حرج يحتاج بديلاً: {$title['ar']}",
            ],
            'body' => [
                'en' => $ended
                    ? "The holder's contract of \"{$title['en']}\" has ended and nobody holds it now."
                    : "\"{$title['en']}\" is vacant.",
                'ar' => $ended
                    ? "انتهى عقد شاغل منصب «{$title['ar']}» ولا أحد يشغله الآن."
                    : "منصب «{$title['ar']}» شاغر.",
            ],
            'url' => $url,
            'subject' => ['type' => 'critical_position', 'id' => $this->position->id, 'action' => null],
        ];
    }
}
