<?php

namespace App\Notifications;

use App\Models\PolicyDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** A policy the employee had agreed to has a new version to read and agree to. */
class PolicyUpdated extends Notification
{
    use Queueable;

    public function __construct(public PolicyDocument $document) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        $title = $this->document->title;

        return [
            'title' => [
                'en' => "Policy updated: {$title}",
                'ar' => "تحديث سياسة: {$title}",
            ],
            'body' => [
                'en' => "A new version of «{$title}» is in place. Please read it and agree to it again.",
                'ar' => "صدرت نسخة جديدة من «{$title}». يرجى قراءتها والموافقة عليها من جديد.",
            ],
            'url' => route('policies.show', $this->document->id),
        ];
    }
}
