<?php

namespace App\Notifications;

use App\Models\Center;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class NewCenterCreated extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Center $center) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        return [
            'title' => [
                'en' => "We have a new center, established in {$this->center->city->name_en}.",
                'ar' => "تم إنشاء مركز جديد في {$this->center->city->name_ar}.",
            ],

            'body' => [
                'en' => "Center \"{$this->center->name_en}\" has been created.",
                'ar' => "تم إنشاء المركز \"{$this->center->name_ar}\".",
            ],

        ];
    }
}
