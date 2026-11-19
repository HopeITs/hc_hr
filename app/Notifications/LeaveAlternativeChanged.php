<?php

namespace App\Notifications;

use App\Models\Employee;
use App\Models\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * The decider named another alternative employee when accepting the request
 * (meeting 19/11): the requester is told who was named and who now covers.
 * Sent only when the alternative actually changed.
 */
class LeaveAlternativeChanged extends Notification
{
    use Queueable;

    public function __construct(
        public LeaveRequest $leaveRequest,
        public ?Employee $previous,
        public ?Employee $current,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $leaveType = $this->leaveRequest->leaveBalance->leaveType;

        return [
            'title' => [
                'en' => __('leaves.alternative-changed.title', [], 'en'),
                'ar' => __('leaves.alternative-changed.title', [], 'ar'),
            ],
            'body' => [
                'en' => $this->body('en', (string) $leaveType?->name_en),
                'ar' => $this->body('ar', (string) $leaveType?->name_ar),
            ],
            'url' => route('leaves.index', ['leave' => $this->leaveRequest->id]),
            'subject' => ['type' => 'leave_request', 'id' => $this->leaveRequest->id, 'action' => null],
        ];
    }

    private function body(string $locale, string $type): string
    {
        $none = __('leaves.alternative-changed.none', [], $locale);

        return __('leaves.alternative-changed.body', [
            'type' => $type,
            'previous' => $this->previous?->nameForLocale($locale) ?? $none,
            'current' => $this->current?->nameForLocale($locale) ?? $none,
        ], $locale);
    }
}
