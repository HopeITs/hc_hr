<?php

namespace App\Support\Recruitment;

use App\Enums\ContractPlace;
use App\Enums\ContractType as EmploymentType;
use App\Enums\Currency;
use App\Enums\SalaryPer;
use App\Models\RecruitmentJobOfferVersion;

/**
 * Display labels for a job offer's terms (Phase 2 Task 9), in the current
 * locale. The contract enums disagree on the shape they return — Currency
 * gives ['en' => …, 'ar' => …], the others ['value' => …] — so the views
 * read labels here instead of knowing each shape.
 *
 * The salary is formatted only when the caller passes $canSeeSalary; for
 * everyone else it is a fixed placeholder, never the figure.
 */
final class OfferTerms
{
    public static function currency(?int $value): string
    {
        return $value ? (Currency::getLocalizedName($value)[app()->getLocale()] ?? '') : '—';
    }

    public static function salaryPer(?int $value): string
    {
        return $value ? SalaryPer::getLocalizedName($value)['value'] : '—';
    }

    public static function employmentType(?int $value): string
    {
        return $value ? EmploymentType::getLocalizedName($value)['value'] : '—';
    }

    public static function place(?int $value): string
    {
        return $value ? ContractPlace::getLocalizedName($value)['value'] : '—';
    }

    public static function salary(RecruitmentJobOfferVersion $version, bool $canSeeSalary): string
    {
        if (! $canSeeSalary) {
            return __('recruitment-applicant.offers.salary_hidden');
        }

        if ($version->salary_amount === null) {
            return '—';
        }

        return number_format((float) $version->salary_amount, 2).' '.self::currency($version->currency)
            .' / '.self::salaryPer($version->salary_per);
    }
}
