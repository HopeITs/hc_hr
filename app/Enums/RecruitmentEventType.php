<?php

namespace App\Enums;

use BenSampo\Enum\Enum;

/**
 * What happened to a recruitment request, for its timeline (D8).
 *
 * These are the structured audit trail that sits beside activity_log rather
 * than inside it, so the timeline can be filtered by step, scope and decision
 * without unpacking JSON.
 *
 * There is no WorkflowBlocked event: it went with the Blocked state (D17).
 * StepSkipped documents the top-level-requester exception (D27), and the last
 * two belong to the direct_hr path (D15).
 */
final class RecruitmentEventType extends Enum
{
    const RequestCreated = 1;

    const RequestEdited = 2;

    const VersionCreated = 3;

    const RequestSubmitted = 4;

    const WorkflowStarted = 5;

    const WorkflowAdvanced = 6;

    const StepApproved = 7;

    const StepRejected = 8;

    const ChangesRequested = 9;

    const RequestCancelled = 10;

    const RequestApplied = 11;

    const RequestApplicationFailed = 12;

    const PositionChanged = 13;

    const HeadcountChanged = 14;

    const VacancyCreated = 15;

    const VacancyClosed = 16;

    const CampaignStateChanged = 17;

    const JobPostPublished = 18;

    const FormVersionPublished = 19;

    const PublicLinkChanged = 20;

    const StepSkipped = 21;

    const DirectHrOpened = 22;

    const ExternalConfirmationRecorded = 23;

    public static function getLocalizedName(int $value): array
    {
        $val = match ($value) {
            self::RequestCreated => ['text' => ['en' => 'Request created', 'ar' => 'أُنشئ الطلب'], 'color' => 'secondary-light'],
            self::RequestEdited => ['text' => ['en' => 'Request edited', 'ar' => 'عُدّل الطلب'], 'color' => 'secondary-light'],
            self::VersionCreated => ['text' => ['en' => 'Version created', 'ar' => 'أُنشئت نسخة'], 'color' => 'secondary-light'],
            self::RequestSubmitted => ['text' => ['en' => 'Submitted', 'ar' => 'أُرسل الطلب'], 'color' => 'secondary-medium'],
            self::WorkflowStarted => ['text' => ['en' => 'Workflow started', 'ar' => 'بدأ المسار'], 'color' => 'secondary-medium'],
            self::WorkflowAdvanced => ['text' => ['en' => 'Workflow advanced', 'ar' => 'تقدّم المسار'], 'color' => 'secondary-medium'],
            self::StepApproved => ['text' => ['en' => 'Step approved', 'ar' => 'ووفق على الخطوة'], 'color' => 'primary'],
            self::StepRejected => ['text' => ['en' => 'Step rejected', 'ar' => 'رُفضت الخطوة'], 'color' => 'secondary-medium'],
            self::ChangesRequested => ['text' => ['en' => 'Changes requested', 'ar' => 'طُلب تعديل'], 'color' => 'secondary-medium'],
            self::RequestCancelled => ['text' => ['en' => 'Request cancelled', 'ar' => 'أُلغي الطلب'], 'color' => 'secondary-light'],
            self::RequestApplied => ['text' => ['en' => 'Request applied', 'ar' => 'طُبِّق الطلب'], 'color' => 'primary'],
            self::RequestApplicationFailed => ['text' => ['en' => 'Application failed', 'ar' => 'فشل التطبيق'], 'color' => 'secondary-medium'],
            self::PositionChanged => ['text' => ['en' => 'Position changed', 'ar' => 'تغيّر المنصب'], 'color' => 'secondary-light'],
            self::HeadcountChanged => ['text' => ['en' => 'Headcount changed', 'ar' => 'تغيّر الملاك'], 'color' => 'secondary-light'],
            self::VacancyCreated => ['text' => ['en' => 'Vacancy created', 'ar' => 'أُنشئ شاغر'], 'color' => 'primary'],
            self::VacancyClosed => ['text' => ['en' => 'Vacancy closed', 'ar' => 'أُغلق الشاغر'], 'color' => 'secondary-medium'],
            self::CampaignStateChanged => ['text' => ['en' => 'Campaign state changed', 'ar' => 'تغيّرت حالة الحملة'], 'color' => 'secondary-light'],
            self::JobPostPublished => ['text' => ['en' => 'Job post published', 'ar' => 'نُشر الإعلان'], 'color' => 'primary'],
            self::FormVersionPublished => ['text' => ['en' => 'Form version published', 'ar' => 'نُشرت نسخة النموذج'], 'color' => 'primary'],
            self::PublicLinkChanged => ['text' => ['en' => 'Public link changed', 'ar' => 'تغيّر الرابط العام'], 'color' => 'secondary-light'],
            self::StepSkipped => ['text' => ['en' => 'Step skipped', 'ar' => 'تُجووزت الخطوة'], 'color' => 'secondary-light'],
            self::DirectHrOpened => ['text' => ['en' => 'Opened directly by HR', 'ar' => 'فُتح مباشرة من HR'], 'color' => 'primary'],
            self::ExternalConfirmationRecorded => ['text' => ['en' => 'External confirmation recorded', 'ar' => 'سُجّل تأكيد خارجي'], 'color' => 'secondary-light'],
            default => ['text' => ['en' => 'Unknown', 'ar' => 'غير معروف'], 'color' => 'secondary-light'],
        };

        return [
            'value' => $val['text'][app()->getLocale()],
            'color' => $val['color'],
        ];
    }
}
