<?php

namespace App\Enums;

use App\Enums\RecruitmentFileScanStatus as Scan;
use App\Models\RecruitmentApplicantFile;
use Illuminate\Support\Collection;

/**
 * What a reviewer can do with an application's CV right now.
 *
 * This is a display answer only. Whether a particular user may download the
 * file is the policy's question, asked again on every download.
 */
final class RecruitmentCvAvailability
{
    public const Available = 'available';

    public const Archived = 'archived';

    public const Pending = 'pending';

    /** Quarantined or failed its scan: kept, never served. */
    public const Blocked = 'blocked';

    public const Missing = 'missing';

    /** @param  Collection<int, RecruitmentApplicantFile>  $cvFiles */
    public static function of(Collection $cvFiles): string
    {
        $latest = self::latest($cvFiles);

        return match (true) {
            $latest === null => self::Missing,
            $latest->scan_status === Scan::Pending => self::Pending,
            $latest->scan_status !== Scan::Clean => self::Blocked,
            $latest->isArchived() => self::Archived,
            default => self::Available,
        };
    }

    /** @param  Collection<int, RecruitmentApplicantFile>  $cvFiles */
    public static function latest(Collection $cvFiles): ?RecruitmentApplicantFile
    {
        return $cvFiles->sortByDesc(fn (RecruitmentApplicantFile $file) => [$file->uploaded_at, $file->getKey()])->first();
    }

    /** Whole class names, so Tailwind's scanner finds them. */
    public static function classes(string $availability): string
    {
        return match ($availability) {
            self::Available => 'border border-primary bg-primary text-white',
            self::Archived, self::Pending => 'border border-neutral-300 bg-neutral-100 text-secondary-dark',
            default => 'border border-primary bg-white text-primary',
        };
    }
}
