<?php

namespace App\Support\Status;

use App\Enums\HiringCampaignStatus;
use App\Enums\RecruitmentApplicationStatus;
use App\Enums\RecruitmentInterviewStatus;
use App\Enums\RecruitmentJobOfferStatus;
use App\Enums\RecruitmentRequestStatus;
use Illuminate\Support\Facades\Blade;

/**
 * The recruitment statuses (request, campaign, application, offer) as the one
 * status chip every other page uses: icon + words + fill shape, never colour
 * (DESIGN.md). Their enums are constant classes, not PHP enums, so they cannot
 * implement HasStatusTone themselves; this is the one place that maps them.
 *
 *   work (solid ink)    moving: submitted, under review, interviewing, sent…
 *   done (surface)      reached its end well: approved, hired, accepted, closed
 *   attn (ink outline)  waits on someone to act: changes requested, failed
 *   stop (dashed)       stopped: rejected, cancelled, withdrawn, expired
 *   wait (card + line)  not started: drafts
 *
 * Tables render HTML strings, so html() gives the same <x-status> markup.
 */
final class RecruitmentStatus
{
    /** @return array{tone: string, icon: string, label: string} */
    public static function request(int $status): array
    {
        $tone = match ($status) {
            RecruitmentRequestStatus::Draft => StatusTone::Wait,
            RecruitmentRequestStatus::Submitted, RecruitmentRequestStatus::UnderReview, RecruitmentRequestStatus::Resubmitted => StatusTone::Work,
            RecruitmentRequestStatus::Approved, RecruitmentRequestStatus::Applied => StatusTone::Done,
            RecruitmentRequestStatus::ChangesRequested, RecruitmentRequestStatus::FailedApplication => StatusTone::Attn,
            default => StatusTone::Stop,
        };

        return self::chip($tone, RecruitmentRequestStatus::getLocalizedName($status)['value'], $status === RecruitmentRequestStatus::Draft ? 'pen' : null);
    }

    /** @return array{tone: string, icon: string, label: string} */
    public static function campaign(int $status): array
    {
        $tone = match ($status) {
            HiringCampaignStatus::Draft => StatusTone::Wait,
            HiringCampaignStatus::Published, HiringCampaignStatus::Open => StatusTone::Work,
            HiringCampaignStatus::Closed => StatusTone::Done,
            default => StatusTone::Stop,
        };

        return self::chip($tone, HiringCampaignStatus::getLocalizedName($status)['value'], $status === HiringCampaignStatus::Draft ? 'pen' : null);
    }

    /** @return array{tone: string, icon: string, label: string} */
    public static function application(?string $status): array
    {
        $tone = match ($status) {
            RecruitmentApplicationStatus::Submitted => StatusTone::Wait,
            RecruitmentApplicationStatus::UnderReview, RecruitmentApplicationStatus::Shortlisted,
            RecruitmentApplicationStatus::InterviewScheduled, RecruitmentApplicationStatus::Interviewed,
            RecruitmentApplicationStatus::Finalist, RecruitmentApplicationStatus::Selected,
            RecruitmentApplicationStatus::OfferSent => StatusTone::Work,
            RecruitmentApplicationStatus::OfferAccepted, RecruitmentApplicationStatus::Hired => StatusTone::Done,
            default => StatusTone::Stop,
        };

        return self::chip($tone, $status ? __('recruitment-applicant.statuses.'.$status) : '—');
    }

    /** @return array{tone: string, icon: string, label: string} */
    public static function offer(?string $status): array
    {
        $tone = match ($status) {
            RecruitmentJobOfferStatus::Draft => StatusTone::Wait,
            RecruitmentJobOfferStatus::Sent, RecruitmentJobOfferStatus::Revised => StatusTone::Work,
            RecruitmentJobOfferStatus::Accepted => StatusTone::Done,
            default => StatusTone::Stop,
        };

        return self::chip($tone, $status ? __('recruitment-applicant.offers.statuses.'.$status) : '—', $status === RecruitmentJobOfferStatus::Draft ? 'pen' : null);
    }

    /** @return array{tone: string, icon: string, label: string} */
    public static function interview(?string $status): array
    {
        $tone = match ($status) {
            RecruitmentInterviewStatus::Scheduled, RecruitmentInterviewStatus::Rescheduled => StatusTone::Work,
            RecruitmentInterviewStatus::Completed => StatusTone::Done,
            default => StatusTone::Stop,
        };

        return self::chip($tone, $status ? __('recruitment-applicant.interviews.statuses.'.$status) : '—');
    }

    /** The <x-status> markup of a chip, for the tables' HTML cells. */
    public static function html(array $chip): string
    {
        return Blade::render('<x-status :tone="$tone" :icon="$icon" :label="$label" />', $chip);
    }

    /** @return array{tone: string, icon: string, label: string} */
    private static function chip(StatusTone $tone, string $label, ?string $icon = null): array
    {
        return ['tone' => $tone->value, 'icon' => $icon ?? $tone->defaultIcon(), 'label' => $label];
    }
}
