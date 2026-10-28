<?php

namespace App\Notifications;

use App\Models\ProfileChangeRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * HR approved or rejected a change the employee proposed to their own profile
 * (D1, profile-contract.md §5.1). Sent to the employee only; the reason of a
 * rejection is theirs to read.
 */
class ProfileChangeDecided extends Notification
{
    use Queueable;

    public function __construct(public ProfileChangeRequest $change) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $section = [
            'en' => __('employees.profile.change.sections.'.$this->change->section, [], 'en'),
            'ar' => __('employees.profile.change.sections.'.$this->change->section, [], 'ar'),
        ];
        $approved = $this->change->status === ProfileChangeRequest::APPROVED;
        $reason = Str::limit((string) $this->change->reason, 160);

        return [
            'title' => $approved
                ? ['en' => 'HR approved your profile change', 'ar' => 'وافقت الموارد البشرية على تعديل ملفك']
                : ['en' => 'HR did not approve your profile change', 'ar' => 'لم توافق الموارد البشرية على تعديل ملفك'],
            'body' => $approved
                ? ['en' => "Your {$section['en']} change is now on your profile.", 'ar' => "أصبح تعديل {$section['ar']} ظاهرًا في ملفك."]
                : ['en' => "Your {$section['en']} change was not approved. Reason: {$reason}", 'ar' => "لم يُعتمد تعديل {$section['ar']}. السبب: {$reason}"],
            'url' => route('my-profile'),
            'subject' => ['type' => 'profile_change', 'id' => $this->change->id, 'action' => null],
        ];
    }
}
