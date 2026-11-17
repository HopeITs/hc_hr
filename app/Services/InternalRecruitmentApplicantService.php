<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\HiringCampaign;
use App\Models\RecruitmentApplication;
use App\Models\User;

/**
 * Staff applying to an internal campaign.
 *
 * An internal campaign is applied to while signed in to HC-HR, so the person
 * is already known: the form opens with what their employee record holds and
 * they only add what it does not — a CV, answers to the campaign's questions.
 * Everything filled in stays editable; what they submit is their application,
 * the employee record itself is never changed from here.
 */
class InternalRecruitmentApplicantService
{
    /** The signed-in person as staff, or null when they cannot apply internally. */
    public function employeeFor(?User $user): ?Employee
    {
        $employee = $user?->profile;

        return $employee?->is_active ? $employee : null;
    }

    public function hasApplied(HiringCampaign $campaign, User $user): bool
    {
        return RecruitmentApplication::query()
            ->where('campaign_id', $campaign->getKey())
            ->where('email_normalized', $this->email($user))
            ->exists();
    }

    /** The address an internal application is filed under: the work account's. */
    public function email(User $user): string
    {
        return mb_strtolower(trim((string) $user->email));
    }

    /**
     * Answers the form can start from, keyed like the form fields. Only the
     * catalogue fields the record can answer; a missing value is left out so
     * the field simply starts empty.
     *
     * @return array<string, string>
     */
    public function prefill(User $user, Employee $employee, string $locale): array
    {
        $dob = $employee->dob ?? $employee->actual_dob;

        return array_filter([
            'full_name' => $employee->nameForLocale($locale),
            'email' => $this->email($user),
            'phone' => (string) ($employee->personal_phone ?: $employee->work_phone),
            'city' => (string) $employee->city?->getAttribute("name_{$locale}"),
            'date_of_birth' => $dob?->format('Y-m-d') ?? '',
        ], fn (string $value): bool => trim($value) !== '');
    }

    /**
     * Who is applying, for the card above the form.
     *
     * @return array{name: string, email: string, position: ?string, center: ?string}
     */
    public function profile(User $user, Employee $employee, string $locale): array
    {
        $position = $employee->latestActiveContract()?->position;

        return [
            'name' => $employee->nameForLocale($locale),
            'email' => $this->email($user),
            'position' => $position?->getAttribute("title_{$locale}") ?: $position?->title_en,
            'center' => $position?->center?->getAttribute("name_{$locale}") ?: $position?->center?->name_en,
        ];
    }
}
