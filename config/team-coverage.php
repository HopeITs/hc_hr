<?php

use App\Enums\LeaveTypeCases;

/*
 * Team calendar and coverage (C5; docs/ux/team-coverage-contract.md).
 * Read by App\Services\TeamCoverage only.
 */
return [
    /*
     * Job level grade of a project manager (T-5, confirmed by HR 2026-10-21).
     * Project managers of the same program see each other's absences.
     */
    'project_manager_grade' => 3,

    /*
     * Service departments, by departments.code (T-6, mapped by HR 2026-10-21):
     * their volunteers' absences are also visible to the project managers of
     * every program in the same governorate. Codes, not ids, so the list
     * survives a reseed; re-check against production before release.
     */
    'service_department_codes' => ['Comm', 'IT', 'HR', 'Fund', 'Fin', 'MEAL', 'Logi', 'LegA'],

    /*
     * Leave types colleagues see as a neutral "On leave" (§3.1 Q1): sick,
     * maternity, paternity, bereavement. Managers and HR see the real type.
     */
    'neutral_type_ids' => [
        LeaveTypeCases::FullSick,
        LeaveTypeCases::HalfSick,
        LeaveTypeCases::MaternityFirst,
        LeaveTypeCases::MaternitySecond,
        LeaveTypeCases::Paternity,
        LeaveTypeCases::DeathFirst,
        LeaveTypeCases::DeathSecond,
    ],

    /* Longest range one call may read (§4.2 bounds). */
    'max_days' => 62,
];
