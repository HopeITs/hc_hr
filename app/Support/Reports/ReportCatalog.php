<?php

namespace App\Support\Reports;

use App\Models\RecruitmentApplicant;
use App\Models\User;
use Closure;

/**
 * The reports the hub page lists, and who may open each. Every report is for
 * HR and the head-office roles (the same people as the Activity report) AND the
 * domain permission its data already needs, so the hub never widens access: a
 * report is only a summary of a page the viewer could already open.
 * Each page re-checks through allows(); hiding a card is not the gate.
 */
final class ReportCatalog
{
    /** HR (any city) and the administrator roles. Cities are scoped by each report. */
    public static function isReportViewer(?User $user): bool
    {
        return $user !== null
            && $user->activeRoleIs('HR', ...config('access-control.administrator_roles', []));
    }

    /** @return array<string, array{route: string, title: string, lead: string, allows: Closure}> */
    public static function all(): array
    {
        return [
            'leaving-reasons' => [
                'route' => 'reports.leaving-reasons',
                'title' => __('Why people leave'),
                'lead' => __('The reason recorded on the contract of each person who left. One row per person.'),
                'allows' => fn (User $u) => $u->hasActivePermission('contracts.view'),
            ],
            'recruitment-funnel' => [
                'route' => 'reports.recruitment-funnel',
                'title' => __('Recruitment funnel'),
                'lead' => __('From request to hire: how many applicants reach each stage, and how long it takes.'),
                'allows' => fn (User $u) => $u->can('viewAny', RecruitmentApplicant::class),
            ],
            'leave' => [
                'route' => 'reports.leave',
                'title' => __('Leave balances and usage'),
                'lead' => __('Annual leave still owed, who is not taking leave, what was taken, and how long requests wait.'),
                'allows' => fn (User $u) => $u->hasActivePermission('leaves.view-all'),
            ],
            'vacancies' => [
                'route' => 'reports.vacancies',
                'title' => __('Position vacancies'),
                'lead' => __('Capacity against the people in post, by city and department, with the critical positions.'),
                'allows' => fn (User $u) => $u->hasActivePermission('positions.view'),
            ],
            'training' => [
                'route' => 'reports.training',
                'title' => __('Training'),
                'lead' => __('Courses by category, month and status, cost, and who has had none.'),
                'allows' => fn (User $u) => $u->hasActivePermission('employees.view'),
            ],
            'profile-completeness' => [
                'route' => 'reports.profile-completeness',
                'title' => __('Profile completeness'),
                'lead' => __('Whose file is missing a photo, contact, education or emergency contact.'),
                'allows' => fn (User $u) => $u->hasActivePermission('employees.view'),
            ],
        ];
    }

    public static function allows(string $key, ?User $user): bool
    {
        $report = self::all()[$key] ?? null;

        return $report !== null && self::isReportViewer($user) && (bool) ($report['allows'])($user);
    }

    /** @return array<string, array{route: string, title: string, lead: string, allows: Closure}> */
    public static function visibleTo(?User $user): array
    {
        return collect(self::all())->filter(fn ($r, $key) => self::allows($key, $user))->all();
    }
}
