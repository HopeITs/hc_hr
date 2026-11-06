<?php

namespace App\Enums;

use App\Notifications\ActionLeaveBalance;
use App\Notifications\ActionLeaveRequest;
use App\Notifications\CenterClosed;
use App\Notifications\ChecklistAssigned;
use App\Notifications\ContractsAutoRenewedSummary;
use App\Notifications\ContractsExpiringAutoRenewWarning;
use App\Notifications\DigitalIdReportedLost;
use App\Notifications\FolderShared;
use App\Notifications\HRSawLeaveRequest;
use App\Notifications\LeaveDecisionReminder;
use App\Notifications\LeaveRequestObjected;
use App\Notifications\NewCenterCreated;
use App\Notifications\NewEmployeeWelcome;
use App\Notifications\NewLeaveRequest;
use App\Notifications\ProfileChangeDecided;
use App\Notifications\TestNotification;
use Illuminate\Notifications\Notification;

/**
 * The kinds of notification a person can tune, one per thing the app really
 * sends (app/Notifications). There is no attendance or payroll module, so
 * there is no category for them: add one here when such a notification exists.
 */
enum NotificationCategory: string
{
    case LeaveActions = 'leave_actions';
    case LeaveUpdates = 'leave_updates';
    case Contracts = 'contracts';
    case Employees = 'employees';
    case Checklists = 'checklists';
    case Files = 'files';
    case Organization = 'organization';
    case Security = 'security';
    case System = 'system';

    /** @var array<class-string<Notification>, self> */
    private const MAP = [
        NewLeaveRequest::class => self::LeaveActions,
        LeaveDecisionReminder::class => self::LeaveActions,
        ActionLeaveRequest::class => self::LeaveUpdates,
        HRSawLeaveRequest::class => self::LeaveUpdates,
        LeaveRequestObjected::class => self::LeaveUpdates,
        ActionLeaveBalance::class => self::LeaveUpdates,
        ContractsAutoRenewedSummary::class => self::Contracts,
        ContractsExpiringAutoRenewWarning::class => self::Contracts,
        NewEmployeeWelcome::class => self::Employees,
        ProfileChangeDecided::class => self::Employees,
        ChecklistAssigned::class => self::Checklists,
        FolderShared::class => self::Files,
        NewCenterCreated::class => self::Organization,
        CenterClosed::class => self::Organization,
        DigitalIdReportedLost::class => self::Security,
        TestNotification::class => self::System,
    ];

    public static function forNotification(Notification $notification): self
    {
        return self::MAP[$notification::class] ?? self::System;
    }

    public function label(): string
    {
        return __('notification-settings.categories.'.$this->value.'.label');
    }

    public function description(): string
    {
        return __('notification-settings.categories.'.$this->value.'.description');
    }

    public function icon(): string
    {
        return match ($this) {
            self::LeaveActions, self::LeaveUpdates => 'calendar',
            self::Contracts => 'file',
            self::Employees => 'users',
            self::Checklists => 'check',
            self::Files => 'folder',
            self::Organization => 'building',
            self::Security => 'shield',
            self::System => 'bell',
        };
    }

    /**
     * Urgent notifications may pass quiet hours (when the person allows it:
     * notification_preferences.urgent_bypass) and reach "urgent only" devices.
     */
    public function urgent(): bool
    {
        return $this === self::Security;
    }

    /**
     * The starting matrix for someone who never changed it. Email stays off
     * except where the app already mailed before preferences existed
     * (the two contract summaries), so nobody's inbox changes overnight.
     *
     * @return array{in_app: bool, desktop: bool, mobile: bool, email: bool}
     */
    public function defaults(): array
    {
        return [
            'in_app' => true,
            'desktop' => true,
            'mobile' => $this !== self::Organization,
            'email' => $this === self::Contracts || $this === self::Security,
        ];
    }
}
