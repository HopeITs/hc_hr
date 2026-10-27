<?php

/*
|--------------------------------------------------------------------------
| Product tours
|--------------------------------------------------------------------------
|
| One entry per page. `routes` is matched with request()->routeIs(), so
| wildcards such as 'leaves.*' work. Each step key doubles as its translation
| key: the copy for dashboard.welcome lives at tour.dashboard.welcome.title /
| .body in resources/lang/{en,ar}/tour.php.
|
| Step options:
|   target     value of a data-tour="..." attribute on the page; omit for a
|              centred step with no highlight (good for a welcome or wrap-up)
|   placement  preferred side: top | bottom | start | end (start/end follow
|              the reading direction, so they flip in Arabic). Falls back to
|              whichever side has room.
|
| A step whose target is missing or hidden when the tour starts is skipped,
| so steps may point at cards that only some roles or some data states render.
| The sidebar steps rely on this: each role only sees the entries its
| permissions render, so each role gets a walkthrough of exactly its pages.
|
| `autostart` opens the tour by itself the first time a user lands on the
| page (tracked per user, per browser). The button replays it at any time.
|
*/

return [
    'dashboard' => [
        'routes' => ['dashboard'],
        'autostart' => true,
        'steps' => [
            'welcome' => [],
            'my-leaves' => ['target' => 'dashboard.my-leaves', 'placement' => 'bottom'],
            'notes' => ['target' => 'dashboard.notes', 'placement' => 'bottom'],
            'team' => ['target' => 'dashboard.team', 'placement' => 'start'],
            'news' => ['target' => 'dashboard.news', 'placement' => 'end'],
            'upcoming-leaves' => ['target' => 'dashboard.upcoming-leaves', 'placement' => 'top'],
            'pending-requests' => ['target' => 'dashboard.pending-requests', 'placement' => 'top'],

            // Sidebar (C1): one step per area of the menu. An area the role
            // does not see is not rendered, so its step is skipped.
            'nav-intro' => ['target' => 'nav', 'placement' => 'end'],
            'nav-inbox' => ['target' => 'nav.inbox', 'placement' => 'end'],
            'nav-my-space' => ['target' => 'nav.area.my-space', 'placement' => 'end'],
            'nav-my-team' => ['target' => 'nav.area.my-team', 'placement' => 'end'],
            'nav-hr' => ['target' => 'nav.area.hr', 'placement' => 'end'],
            'nav-settings' => ['target' => 'nav.area.settings', 'placement' => 'end'],
            'nav-organisation' => ['target' => 'nav.area.organisation', 'placement' => 'end'],

            'replay' => ['target' => 'tour.button', 'placement' => 'bottom'],
        ],
    ],

    // The toolbar steps only exist in the chart view; in the directory view
    // they are skipped and the tour covers the header and view switcher.
    'org-chart' => [
        'routes' => ['org.chart'],
        'steps' => [
            'stats' => ['target' => 'org.stats', 'placement' => 'bottom'],
            'views' => ['target' => 'org.views', 'placement' => 'bottom'],
            'search' => ['target' => 'org.search', 'placement' => 'bottom'],
            'filters' => ['target' => 'org.filters', 'placement' => 'bottom'],
            'vacancies' => ['target' => 'org.vacancies', 'placement' => 'bottom'],
            'canvas' => ['target' => 'org.canvas', 'placement' => 'top'],
            'node' => ['target' => 'org.node', 'placement' => 'bottom'],
            'controls' => ['target' => 'org.controls', 'placement' => 'bottom'],
            'export' => ['target' => 'org.export', 'placement' => 'bottom'],
        ],
    ],

    'employee-statistics' => [
        'routes' => ['statistics.general'],
        'steps' => [
            'departments' => ['target' => 'stats.departments', 'placement' => 'bottom'],
            'payment-type' => ['target' => 'stats.payment-type', 'placement' => 'start'],
            'personnel' => ['target' => 'stats.personnel', 'placement' => 'end'],
            'job-levels' => ['target' => 'stats.job-levels', 'placement' => 'top'],
            'expiring' => ['target' => 'stats.expiring', 'placement' => 'end'],
            'no-leaves' => ['target' => 'stats.no-leaves', 'placement' => 'top'],
            'contract-types' => ['target' => 'stats.contract-types', 'placement' => 'start'],
        ],
    ],

    // Cards repeat per program (or per center); the card steps highlight the
    // first one, and are skipped when the filters leave no cards at all.
    'contract-statistics' => [
        'routes' => ['statistics.contract'],
        'steps' => [
            'filters' => ['target' => 'contract-stats.filters', 'placement' => 'bottom'],
            'reset' => ['target' => 'contract-stats.reset', 'placement' => 'bottom'],
            'card' => ['target' => 'contract-stats.card', 'placement' => 'end'],
            'breakdown' => ['target' => 'contract-stats.breakdown', 'placement' => 'bottom'],
            'show-more' => ['target' => 'contract-stats.show-more', 'placement' => 'bottom'],
        ],
    ],

    /*
    | Employee management. List pages share the table anchors from the
    | published livewire-table views (table.search, table.row, ...), plus
    | list.add / list.export on the page header; the copy stays per page.
    */

    'employees' => [
        'routes' => ['employees.index'],
        'steps' => [
            'add' => ['target' => 'list.add', 'placement' => 'bottom'],
            'export' => ['target' => 'list.export', 'placement' => 'bottom'],
            'search' => ['target' => 'table.search', 'placement' => 'bottom'],
            'column-search' => ['target' => 'table.column-search', 'placement' => 'bottom'],
            'row' => ['target' => 'table.row', 'placement' => 'bottom'],
            'columns' => ['target' => 'table.columns', 'placement' => 'bottom'],
            'per-page' => ['target' => 'table.per-page', 'placement' => 'bottom'],
        ],
    ],

    // Create and edit share one form. The draft step only shows right after a
    // new employee's information is saved; the back button only when editing.
    'employee-form' => [
        'routes' => ['employees.create', 'employees.edit'],
        'steps' => [
            'tabs' => ['target' => 'employee.tabs', 'placement' => 'end'],
            'draft' => ['target' => 'employee.draft', 'placement' => 'bottom'],
            'photo' => ['target' => 'employee.photo', 'placement' => 'bottom'],
            'save' => ['target' => 'employee.save', 'placement' => 'top'],
            'back' => ['target' => 'employee.back', 'placement' => 'bottom'],
        ],
    ],

    'employee-profile' => [
        'routes' => ['employees.show', 'my-profile'],
        'steps' => [
            'header' => ['target' => 'profile.header', 'placement' => 'bottom'],
            'actions' => ['target' => 'profile.actions', 'placement' => 'bottom'],
            'summary' => ['target' => 'profile.summary', 'placement' => 'bottom'],
            'overview' => ['target' => 'profile.overview', 'placement' => 'end'],
            'career' => ['target' => 'profile.career', 'placement' => 'end'],
            'private' => ['target' => 'profile.private', 'placement' => 'start'],
        ],
    ],

    'default-deliverables' => [
        'routes' => ['default-deliverables.index'],
        'steps' => [
            'intro' => [],
            'add' => ['target' => 'list.add', 'placement' => 'bottom'],
            'export' => ['target' => 'list.export', 'placement' => 'bottom'],
            'search' => ['target' => 'table.search', 'placement' => 'bottom'],
            'row' => ['target' => 'table.row', 'placement' => 'bottom'],
        ],
    ],

    'contracts' => [
        'routes' => ['contracts.index'],
        'steps' => [
            'add' => ['target' => 'list.add', 'placement' => 'bottom'],
            'export' => ['target' => 'list.export', 'placement' => 'bottom'],
            'search' => ['target' => 'table.search', 'placement' => 'bottom'],
            'column-search' => ['target' => 'table.column-search', 'placement' => 'bottom'],
            'row' => ['target' => 'table.row', 'placement' => 'bottom'],
            'columns' => ['target' => 'table.columns', 'placement' => 'bottom'],
        ],
    ],

    // Create, renew and edit share the composer. Termination exists only when
    // editing; the live contract card only on wide screens.
    'contract-form' => [
        'routes' => ['contracts.create', 'contracts.renew', 'contracts.edit'],
        'steps' => [
            'assignment' => ['target' => 'contract.assignment', 'placement' => 'bottom'],
            'schedule' => ['target' => 'contract.schedule', 'placement' => 'bottom'],
            'pay' => ['target' => 'contract.pay', 'placement' => 'bottom'],
            'termination' => ['target' => 'contract.termination', 'placement' => 'bottom'],
            'card' => ['target' => 'contract.card', 'placement' => 'start'],
            'save' => ['target' => 'contract.save', 'placement' => 'top'],
        ],
    ],

    'contract-details' => [
        'routes' => ['contracts.show'],
        'steps' => [
            'document' => ['target' => 'contract-details.document', 'placement' => 'top'],
            'pager' => ['target' => 'contract-details.pager', 'placement' => 'top'],
            'edit' => ['target' => 'contract-details.edit', 'placement' => 'bottom'],
            'renew' => ['target' => 'contract-details.renew', 'placement' => 'bottom'],
            'export' => ['target' => 'contract-details.export', 'placement' => 'bottom'],
        ],
    ],

    'leaves' => [
        'routes' => ['leaves.index'],
        'steps' => [
            'balances' => ['target' => 'leaves.balances', 'placement' => 'bottom'],
            'request' => ['target' => 'leaves.request', 'placement' => 'bottom'],
            'filters' => ['target' => 'leaves.filters', 'placement' => 'bottom'],
            'table' => ['target' => 'leaves.table', 'placement' => 'top'],
        ],
    ],
];
