<?php

namespace App\Notifications;

use App\Models\RecruitmentRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class MissingRecruitmentApprover extends Notification
{
    use Queueable;

    /**
     * @param  array<int, string>  $steps
     */
    public function __construct(
        public RecruitmentRequest $request,
        public array $steps,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $steps = implode(', ', $this->steps);

        return [
            'title' => [
                'en' => 'Recruitment approval needs attention',
                'ar' => 'طلب توظيف يحتاج إلى معالجة',
            ],
            'body' => [
                'en' => "No unique approver was found for {$this->request->request_number} ({$steps}). Please correct the HR setup.",
                'ar' => "لم يتم العثور على موافق واحد لطلب التوظيف {$this->request->request_number} ({$steps}). يرجى تصحيح إعدادات الموارد البشرية.",
            ],
            'url' => route('recruitment.requests.show', $this->request->getKey()),
        ];
    }
}
