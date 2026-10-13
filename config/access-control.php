<?php

return [
    'administrator_roles' => ['super-admin', 'HR-SYR'],

    /*
     * Regional HR roles that cover more than their own city, by city slug.
     * A regional role not listed here covers exactly the city whose slug
     * follows "HR-" in its name.
     */
    'hr_role_cities' => [
        'HR-HMS' => ['HMS', 'RHO'],
    ],

    'system_roles' => [
        'super-admin',
        'general-manager',
        'HR-SYR',
        'HR-ALP',
        'HR-HMS',
        'HR-DMS',
        'HR-LAT',
        'HR-TAR',
        'manager',
        'employee',
    ],

    'role_baselines' => [
        'general-manager' => [
            'statistics.view', 'employees.view', 'employees.create', 'employees.update', 'employees.export',
            'contracts.view', 'contracts.create', 'contracts.update', 'contracts.export',
            'programs.view', 'programs.create', 'programs.update', 'centers.view',
            'positions.view', 'leaves.view-own', 'leaves.view-all', 'leaves.create',
            'leaves.update', 'leaves.decide', 'news.view', 'news.create', 'news.update',
            'checklists.view-own',
            'checklists.manage', 'project-basics.view', 'archives.view',
            'archives.create', 'archives.update', 'archives.delete', 'archives.share',
            'archives.download',
        ],
        'manager' => [
            'programs.view', 'centers.view', 'positions.view', 'leaves.view-own',
            'leaves.view-all', 'leaves.create', 'leaves.update', 'leaves.decide',
            'news.view', 'news.create', 'news.update',
            'checklists.view-own', 'project-basics.view', 'archives.view',
            'archives.create', 'archives.update', 'archives.delete', 'archives.share',
            'archives.download',
        ],
        'employee' => [
            'programs.view', 'centers.view', 'positions.view', 'leaves.view-own',
            'leaves.create', 'leaves.update', 'archives.view',
            'archives.create', 'archives.update', 'archives.delete', 'archives.share',
            'archives.download', 'checklists.view-own', 'project-basics.view',
        ],
    ],

    'groups' => [
        'access-management' => ['view', 'manage-employees', 'manage-roles', 'manage-permissions'],
        'statistics' => ['view'],
        'employees' => ['view', 'create', 'update', 'delete', 'export'],
        'contracts' => ['view', 'create', 'update', 'delete', 'export'],
        'programs' => ['view', 'create', 'update', 'delete'],
        'centers' => ['view', 'create', 'update', 'delete', 'export'],
        'positions' => ['view', 'create', 'update', 'delete', 'export'],
        'leaves' => ['view-own', 'view-all', 'create', 'update', 'delete', 'decide', 'review', 'manage-balances', 'export'],
        'project-basics' => ['view', 'create', 'update', 'delete', 'export'],
        'news' => ['view', 'create', 'update', 'delete'],
        'badges' => ['view', 'create', 'update', 'delete'],
        'archives' => ['view', 'create', 'update', 'delete', 'share', 'download'],
        'checklists' => ['view-own', 'view-all', 'manage', 'deliver', 'receive'],
        'activity-log' => ['view'],
    ],

    /*
     * Server-side guards for mutation methods with an unambiguous action.
     * Components that use one save method for both create and update authorize
     * inside the component because the required permission depends on state.
     */
    'livewire' => [
        'access-management.index' => [
            'saveEmployeeAccess' => 'access-management.manage-employees',
            'saveRolePermissions' => 'access-management.manage-permissions',
            'selectAllRolePermissions' => 'access-management.manage-permissions',
            'clearRolePermissions' => 'access-management.manage-permissions',
            'createRole' => 'access-management.manage-roles',
            'renameRole' => 'access-management.manage-roles',
            'deleteRole' => 'access-management.manage-roles',
        ],
        'basics.city-table' => [
            'toggleItemStatusConfirmed' => 'project-basics.update',
            'exportExcel' => 'project-basics.export',
        ],
        'basics.contract-type-table' => ['delete' => 'project-basics.delete', 'exportExcel' => 'project-basics.export'],
        'basics.checklist-item-table' => ['delete' => 'project-basics.delete', 'exportExcel' => 'project-basics.export'],
        'basics.leave-type-table' => ['delete' => 'project-basics.delete', 'exportExcel' => 'project-basics.export'],
        'basics.job-level-table' => ['delete' => 'project-basics.delete', 'exportExcel' => 'project-basics.export'],
        'basics.department-table' => ['delete' => 'project-basics.delete', 'exportExcel' => 'project-basics.export'],
        'basics.course-category-table' => ['delete' => 'project-basics.delete', 'exportExcel' => 'project-basics.export'],
        'basics.annual-leave-rule-table' => ['delete' => 'project-basics.delete', 'exportExcel' => 'project-basics.export'],
        'basics.termination-reason-table' => ['delete' => 'project-basics.delete'],
        'program-management.program.create' => ['save' => 'programs.create'],
        'program-management.program.update' => ['save' => 'programs.update'],
        'program-management.program.index' => [
            'delete' => 'programs.delete',
            'toggleItemStatusConfirmed' => 'programs.update',
            'show' => 'programs.view',
        ],
        'program-management.program.detail' => [
            'delete' => 'programs.delete',
            'toggleItemStatusConfirmed' => 'programs.update',
        ],
        'program-management.center.center-create' => ['save' => 'centers.create'],
        'program-management.center.centers-table' => [
            'delete' => 'centers.delete',
            'toggleItemStatusConfirmed' => 'centers.update',
            'exportExcel' => 'centers.export',
        ],
        'position.position-create' => ['save' => 'positions.create'],
        'position.position-edit' => ['save' => 'positions.update'],
        'position.position-table' => [
            'delete' => 'positions.delete',
            'toggleItemStatusConfirmed' => 'positions.update',
            'exportExcel' => 'positions.export',
        ],
        'employee.employee-create' => [
            'saveInformation' => 'employees.create',
            'createDraftAndContinue' => 'employees.create',
            'positionOptionsForDraft' => 'employees.create',
            'programOptionsForDraft' => 'employees.create',
            'citiesForDraft' => 'employees.create',
            'canPickAnyDraftCity' => 'employees.create',
        ],
        'employee.employee-edit' => [
            'saveChecklist' => 'employees.update',
            'saveInformation' => 'employees.update',
            'saveEducation' => 'employees.update',
            'saveCourses' => 'employees.update',
            'saveExperience' => 'employees.update',
            'saveRelatives' => 'employees.update',
            'saveBadges' => 'badges.create',
            'saveAttachments' => 'employees.update',
            'removeAttachment' => 'employees.update',
            'removeEducation' => 'employees.update',
            'removeCourse' => 'employees.update',
            'removeExperience' => 'employees.update',
            'removeRelative' => 'employees.update',
            'addBadge' => 'badges.create',
            'removeBadge' => 'badges.create',
            'initiateReturn' => 'employees.update',
            'finalizeReturnCycle' => 'employees.update',
        ],
        'employee.employee-table' => [
            'delete' => 'employees.delete',
            'toggleItemStatusConfirmed' => 'employees.update',
            'exportExcel' => 'employees.export',
        ],
        // The composer exposes one option load, one entitlement preview, and
        // the save itself on both screens; create additionally exposes the
        // draft-contract lookup that only makes sense when creating a new
        // contract (see ContractCreate::draftContractFor()).
        'contract.contract-create' => [
            'submit' => 'contracts.create',
            'locationOptions' => 'contracts.create',
            'employeeSearchOptions' => 'contracts.create',
            'leavePreview' => 'contracts.create',
            'draftContractFor' => 'contracts.create',
        ],
        'contract.contract-edit' => [
            'submit' => 'contracts.update',
            'locationOptions' => 'contracts.update',
            'employeeSearchOptions' => 'contracts.update',
            'leavePreview' => 'contracts.update',
        ],
        // A renewal creates a contract. The permission here is the coarse
        // gate; ContractRenewModal re-reads the contract and runs the `renew`
        // policy on every one of these, which is what enforces city scope and
        // rejects a terminated source.
        'contract.contract-renew-modal' => [
            'open' => 'contracts.create',
            'renewAsIs' => 'contracts.create',
            'renewWithEdit' => 'contracts.create',
        ],
        'contract.contracts-table' => ['delete' => 'contracts.delete', 'exportExcel' => 'contracts.export'],
        'news.news-table' => ['delete' => 'news.delete'],
        'employee.leave.all-leave-request-module' => [
            'create' => 'leaves.create', 'delete' => 'leaves.delete',
            'openDecision' => 'leaves.decide', 'submitDecision' => 'leaves.decide',
        ],
        'employee.leave.all-leave-request-table' => [
            'delete' => 'leaves.delete', 'markAsReviewed' => 'leaves.review',
        ],
        'employee.leave.my-leave-request-table' => ['delete' => 'leaves.delete'],
        // 'edit' loads a balance record into the modal, before processSave().
        'employee.leave.emp-leave-balance-module' => [
            'processSave' => 'leaves.manage-balances',
            'edit' => 'leaves.manage-balances',
        ],
        'employee.leave.emp-leave-balance-table' => [
            'exportExcel' => 'leaves.export',
            'edit' => 'leaves.manage-balances',
        ],
        'badges.badges-table' => ['delete' => 'badges.delete'],
        'program-management.default-deliverable.default-deliverables-table' => [
            'delete' => 'checklists.manage', 'exportExcel' => 'checklists.manage',
        ],
        'archives.archived-files-module' => [
            'createFolder' => 'archives.create',
            'editPermissions' => 'archives.share',
            'savePermissions' => 'archives.share',
            'updatedFilesToUpload' => 'archives.create',
            'downloadFile' => 'archives.download',
            'previewFile' => 'archives.view',
            'confirmFileDeletion' => 'archives.delete',
            'confirmFolderDeletion' => 'archives.delete',
            'deleteItem' => 'archives.delete',
            'startEditingFolder' => 'archives.update',
            'saveEditedFolder' => 'archives.update',
            'startEditingFile' => 'archives.update',
            'saveEditedFile' => 'archives.update',
            'toggleUser' => 'archives.share',
            'toggleSelectAllVisible' => 'archives.share',
        ],
        'checklist-items.pending-items' => ['markDelivered' => 'checklists.deliver'],
        'checklist-items.pending-returns' => ['markReceived' => 'checklists.receive'],

        // Project-basics lookup tables. create/edit open the modal and disclose
        // the record; save() is create-or-update and decides its own permission
        // in-method, so it appears under livewire_exempt instead.
        'basics.annual-leave-rule-module' => ['create' => 'project-basics.create', 'edit' => 'project-basics.update'],
        'basics.checklist-item-module' => ['create' => 'project-basics.create', 'edit' => 'project-basics.update'],
        'basics.contract-type-module' => [
            'create' => 'project-basics.create',
            'edit' => 'project-basics.update',
            'delete' => 'project-basics.delete',
        ],
        'basics.course-category-module' => ['create' => 'project-basics.create', 'edit' => 'project-basics.update'],
        'basics.department-module' => ['create' => 'project-basics.create', 'edit' => 'project-basics.update'],
        'basics.job-level-module' => ['create' => 'project-basics.create', 'edit' => 'project-basics.update'],
        'basics.leave-type-module' => ['create' => 'project-basics.create', 'edit' => 'project-basics.update'],
        'basics.termination-reason-module' => ['create' => 'project-basics.create', 'edit' => 'project-basics.update'],

        'program-management.center.centers-module' => ['create' => 'centers.create', 'edit' => 'centers.update'],
        'program-management.default-deliverable.default-deliverables-module' => [
            'create' => 'checklists.manage',
            'edit' => 'checklists.manage',
        ],
        // Streams the whole org directory to CSV; had no check of its own.
        'position.directory' => ['exportCsv' => 'positions.export'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Reviewed Livewire actions that need no middleware permission
    |--------------------------------------------------------------------------
    |
    | Every public method on a Livewire component is callable over HTTP with
    | arbitrary arguments, whether or not the page renders a button for it.
    | ActiveLivewirePermissionMiddleware only checks methods listed in
    | 'livewire' above and lets everything else through, so an unlisted action
    | is unprotected *and* silent about it.
    |
    | LivewireMutationCoverageTest turns that silence into a build failure:
    | every public action written in app/Livewire must appear either in
    | 'livewire' above or here. This list is therefore a record of decisions,
    | not a bypass — each group below says why the middleware layer adds
    | nothing, and in most cases the real control (a Gate call, a scoped query,
    | or a route middleware) is stronger than a permission name would be.
    |
    | Adding a method here without a reason defeats the entire mechanism.
    |
    */

    'livewire_exempt' => [

        // --- Authentication -------------------------------------------------
        // Reached before a session exists, or acting only on the caller's own
        // account. A permission check would be meaningless: there is no active
        // role yet. Login is rate limited per account and per IP.
        'auth.login' => ['login', 'updatedEmail'],
        'auth.register' => ['register'],
        'auth.forgot-password' => ['sendPasswordResetLink'],
        'auth.reset-password' => ['resetPassword'],
        'auth.confirm-password' => ['confirmPassword'],
        'auth.verify-email' => ['sendVerification', 'logout'],
        // switchTo() calls User::setActiveRole(), which aborts unless the role
        // is already assigned to this user; it grants nothing new.
        'auth.role-switcher' => ['switchTo', 'refreshRoles'],
        'settings.password' => ['updatePassword'],

        // --- Confirmation dialogs -------------------------------------------
        // These only render a "are you sure?" prompt. The action it confirms
        // is the mapped one (delete / toggleItemStatusConfirmed), and that is
        // where the permission and the policy check live.
        'badges.badges-table' => ['confirmDelete'],
        'basics.annual-leave-rule-table' => ['confirmDelete'],
        'basics.checklist-item-table' => ['confirmDelete'],
        'basics.city-table' => ['confirmToggleItemStatus'],
        'basics.contract-type-table' => ['confirmDelete'],
        'basics.course-category-table' => ['confirmDelete'],
        'basics.department-table' => ['confirmDelete'],
        'basics.job-level-table' => ['confirmDelete'],
        'basics.leave-type-table' => ['confirmDelete'],
        'basics.termination-reason-table' => ['confirmDelete'],
        'contract.contracts-table' => ['confirmDelete'],
        'news.news-table' => ['confirmDelete'],
        'position.position-table' => ['confirmDelete', 'confirmToggleItemStatus'],
        'program-management.center.centers-table' => ['confirmDelete', 'confirmToggleItemStatus'],
        'program-management.default-deliverable.default-deliverables-table' => ['confirmDelete'],
        'program-management.program.detail' => ['confirmDelete', 'confirmToggleItemStatus'],
        'program-management.program.index' => ['confirmDelete', 'confirmToggleItemStatus', 'loadMore'],
        'employee.employee-table' => ['confirmDelete', 'confirmToggleItemStatus'],

        // --- Authorized inside the method -----------------------------------
        // save() is create-or-update, so a single permission name in the
        // middleware map would be wrong in one direction or the other. Each of
        // these calls authorizeActivePermission() with the correct permission
        // for the branch it is taking, which the middleware cannot do.
        'basics.annual-leave-rule-module' => ['save'],
        'basics.checklist-item-module' => ['save'],
        'basics.contract-type-module' => ['save'],
        'basics.course-category-module' => ['save'],
        'basics.department-module' => ['save'],
        'basics.job-level-module' => ['save'],
        'basics.leave-type-module' => ['save'],
        'basics.termination-reason-module' => ['save'],
        // updatedFormNewsableType() only clears dependent form selections;
        // it does not read a record or persist data. save() authorizes before writing.
        'news.news-create' => ['save', 'updatedFormNewsableType'],
        // Also runs assertCityIsAuthorized() and a Gate check on the city.
        'program-management.center.centers-module' => ['save'],
        'program-management.default-deliverable.default-deliverables-module' => ['save'],
        // Each calls authorizeActivePermission() plus a LeaveRequestPolicy
        // check on the specific record, which is the stronger control.
        'employee.leave.my-leave-request-module' => [
            'create', 'edit', 'processSave', 'confirmSave', 'openDetails',
            'closeDetails', 'selectedLeave', 'refreshCounts', 'setActiveTab',
        ],
        'employee.leave.all-leave-request-module' => [
            'edit', 'show', 'processSave', 'confirmSave', 'confirmDelete',
            'closeDecision', 'closeDetails', 'refreshCounts', 'setActiveTab',
            'updatedFormEmployeeId',
        ],
        'employee.leave.emp-leave-balance-module' => ['confirmSave'],
        'employee.leave.all-leave-request-table' => [
            'edit', 'confirmDelete', 'confirmHrReview', 'refreshTable', 'setStatusFilter',
        ],
        'employee.leave.my-leave-request-table' => ['edit', 'confirmDelete', 'setStatusFilter'],

        // --- Scoped to the caller -------------------------------------------
        // These resolve their subject from auth() and never accept a
        // caller-supplied owner id, so there is no cross-user surface for a
        // permission to protect.
        'dashboard.employee-notes-card' => [
            'addText', 'createNote', 'toggleDone', 'updateContent', 'delete',
        ],
        'notifications.dropdown' => [
            'markAsRead', 'markAllAsRead', 'openNotification', 'refreshList', 'safeNotificationUrl',
        ],
        'notifications.index' => [
            'markAsRead', 'markAllAsRead', 'openNotification', 'refreshList', 'safeNotificationUrl',
        ],
        'notifications.counter' => ['updateCount'],
        'layout.sidebar' => ['toggle', 'close', 'refreshCounts'],

        // --- Component-state only -------------------------------------------
        // Tab switches, wizard steps, modal open/close, filter changes, zoom
        // and pagination controls. They mutate Livewire properties, never the
        // database, and the page they belong to is already route-gated.
        'access-management.index' => [
            'showTab', 'selectRole', 'selectUser', 'showAssignedUsers', 'clearSearch',
            'employeeAccessIsDirty', 'updatedSearch', 'updatedRoleFilter',
            'updatedStatusFilter', 'updatedEmployeeRoleIds',
        ],
        'archives.archived-files-module' => [
            'loadContent', 'addFilter', 'removeFilter', 'clearFilters',
            'closePreviewModal', 'closePermissionsModal', 'updatedUserSearch',
            'updatedPermissionFilterType', 'updatedPermissionFilterValue',
        ],
        'badges.badges-module' => ['openBadgeModal'],
        // Neither contract.contract-create nor contract.contract-edit has any
        // exempt action: the wizard's step navigation and updated* hooks were
        // removed along with the wizard itself, and every action that remains
        // is mapped to a permission above.
        'contract.contract-details' => ['nextPage', 'prevPage', 'setPage'],
        // Dismisses the modal and clears its state. Writes nothing; the two
        // actions that do write are mapped to contracts.create above.
        'contract.contract-renew-modal' => ['close'],
        'dashboard.calendar-card' => ['next', 'prev'],
        // skipDraft is pure navigation (a redirect, no data read or write) and
        // updatedDraftCityId/updatedDraftProgramId only reset the draft
        // cascade's own in-memory selections — the same class as
        // setActiveTab, not the mapped createDraftAndContinue/
        // positionOptionsForDraft/programOptionsForDraft/citiesForDraft/
        // canPickAnyDraftCity above.
        'employee.employee-create' => ['setActiveTab', 'toggleBoardMember', 'skipDraft', 'updatedDraftCityId', 'updatedDraftProgramId'],
        // The add*/openInitiateReturnModal methods stage rows in the in-memory
        // form object; nothing is written until the mapped save* action runs,
        // and mount() has already authorized 'update' on this employee.
        'employee.employee-edit' => [
            'setActiveTab', 'toggleBoardMember', 'addEducation', 'addCourse',
            'addExperience', 'addRelative', 'addAttachment', 'openInitiateReturnModal',
        ],
        'position.org-chart' => [
            'zoomIn', 'zoomOut', 'resetZoom', 'fitToScreen', 'expandAll',
            'collapseAll', 'toggleCollapse', 'toggleDensity', 'resetFilters',
        ],
        'position.directory' => ['resetFilters'],
        'position.profile-drawer' => ['openProfile', 'close'],
        'program-management.center.center-details' => ['switchTab'],
        'news.today-events-card' => ['loadEvents'],

        // --- Route-gated reads ----------------------------------------------
        // Reached only through routes carrying active_permission:statistics.view,
        // and both now apply the caller's city scope to every aggregate.
        'statistics.contract-statistics' => ['loadData', 'resetFilters'],
        'employee.employee-statistics' => ['getStatistics'],
    ],
];
