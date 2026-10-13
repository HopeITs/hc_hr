<?php

namespace App\Enums;

final class RecruitmentApplicationStatus
{
    public const Submitted = 'submitted';

    public const UnderReview = 'under_review';

    public const Shortlisted = 'shortlisted';

    public const Rejected = 'rejected';

    public const InterviewScheduled = 'interview_scheduled';

    public const Interviewed = 'interviewed';

    public const Selected = 'selected';

    public const OfferSent = 'offer_sent';

    public const OfferAccepted = 'offer_accepted';

    public const OfferRejected = 'offer_rejected';

    public const Hired = 'hired';

    public const Withdrawn = 'withdrawn';

    public const Archived = 'archived';

    /** @return array<int, string> */
    public static function values(): array
    {
        return [
            self::Submitted,
            self::UnderReview,
            self::Shortlisted,
            self::Rejected,
            self::InterviewScheduled,
            self::Interviewed,
            self::Selected,
            self::OfferSent,
            self::OfferAccepted,
            self::OfferRejected,
            self::Hired,
            self::Withdrawn,
            self::Archived,
        ];
    }

    /** Hope Center brand red marks active outcomes; neutral tones mark waiting states. */
    public static function classes(?string $status): string
    {
        return match ($status) {
            self::Shortlisted, self::InterviewScheduled, self::Interviewed => 'border border-primary bg-white text-primary',
            self::Selected, self::OfferSent, self::OfferAccepted, self::Hired => 'border border-primary bg-primary text-white',
            self::Rejected, self::OfferRejected => 'border border-primary bg-white text-primary',
            default => 'border border-neutral-300 bg-neutral-100 text-secondary-dark',
        };
    }
}
