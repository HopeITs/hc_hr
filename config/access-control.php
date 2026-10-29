<?php

/*
 * The close guard of an editable dialog (GuardsModalClose, A5-M). requestClose()
 * only compares the entries with what the dialog opened with and either hides
 * it or raises the "Discard your changes?" question; discardChanges() hides it
 * and clears that dialog's own form. Neither reads a record or writes one, and
 * the dialog name they take is checked against the component's own list.
 */
$closeGuard = ['requestClose', 'discardChanges'];

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

    /*
     * Reference pages a role may not open, though its baseline still holds
     * the read permission (C0-Q4 answer 2, G-B-13 answer 2). The same list
     * takes them out of the menu and refuses their URL with 403, so the two
     * cannot drift. The permission stays: positions.view also opens the org
     * chart and the colleague drawer, which every role keeps.
     * Read through User::canOpenReferencePage().
     */
    'reference_pages_hidden_from' => [
        'centers' => ['employee'],
        'programs' => ['employee'],
        'positions' => ['employee', 'manager'],
        'project-basics' => ['employee', 'manager'],
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
            // Read-only, across every city like HR-SYR: see Badge::scopeVisibleTo().
            'badges.view',
        ],
        'manager' => [
            // Read-only, and only their direct reports' contracts (G-C-8):
            // see Contract::scopeVisibleTo().
            'contracts.view',
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
        // edit() discloses a city into the modal, like the other basics modules.
        'basics.city-module' => ['edit' => 'project-basics.update'],
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
            'saveDraftRecord' => 'employees.create',
            'createDraftAndContinue' => 'employees.create',
            'positionOptionsForDraft' => 'employees.create',
            'programOptionsForDraft' => 'employees.create',
            'citiesForDraft' => 'employees.create',
            'canPickAnyDraftCity' => 'employees.create',
        ],
        'employee.employee-edit' => [
            'saveChecklist' => 'employees.update',
            'saveInformation' => 'employees.update',
            'saveDraftRecord' => 'employees.update',
            'confirmDiscardDraftRecord' => 'employees.update',
            'discardDraftRecord' => 'employees.update',
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
        // B4: opens the read-only drawer; the method re-runs EmployeePolicy::view and
        // ::viewDirectory on the record inside the active city scope.
        'employee.employee-drawer' => ['open' => 'employees.view'],
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
            'reconcileOperation' => 'contracts.create', // X1-I: lookup by token, never writes
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
            'reconcileOperation' => 'contracts.create', // X1-I: lookup by token, never writes
            'renewWithEdit' => 'contracts.create',
        ],
        // Both auto-renew actions also run ContractPolicy::manageAutoRenew on
        // the specific contract (HR role, contracts.update, visible to the
        // caller), which is the city/record control; the permission here is
        // the coarse gate. The confirmed write normally arrives as a dispatched
        // event, so the in-method Gate is what holds on that path.
        // Opens the read-only drawer; the method re-runs ContractPolicy::view on the record.
        'contract.contract-drawer' => ['open' => 'contracts.view'],
        'contract.contracts-table' => [
            'delete' => 'contracts.delete',
            'exportExcel' => 'contracts.export',
            'confirmToggleAutoRenew' => 'contracts.update',
            'toggleAutoRenewConfirmed' => 'contracts.update',
        ],
        'news.news-table' => ['delete' => 'news.delete'],
        'employee.leave.all-leave-request-module' => [
            'create' => 'leaves.create', 'delete' => 'leaves.delete',
            'openDecision' => 'leaves.decide', 'submitDecision' => 'leaves.decide',
            'reconcileOperation' => 'leaves.decide', // X1-I: reads the request's state, decides nothing
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
        // Holidays are set by national HR and by program managers, who hold no
        // project-basics permission: every action checks HolidayProjects
        // (who may change which holiday) in the component itself.
        'basics.holiday-table' => ['confirmDelete', 'delete'],
        // updatedPaginators (not a dialog) stores the caller's own page
        // position in their own session (the key includes auth()->id()); the
        // rows still come from query(), which applies Contract::scopeVisibleTo.
        // B3 list states: view state / text only, no writes.
        // B4: setPreset / clearAll / removeChip / dismissStateNotice change only the caller's
        // own view state (setPreset also 403s a preset the active role may not use); every
        // row still comes from query() -> Contract::scopeVisibleTo. The rest is display text.
        // B4-D saved views: saveView / applyView / renameView / deleteView / savedViews touch only
        // the caller's OWN saved_table_views rows (every query is scoped by auth id, this table and
        // the active role context; another user's id is a 404). They store table state only (search,
        // filters, sort, per-page, columns), never rows or pay, and applyView re-runs the table's
        // sanitizing, so rows still come from query().
        'contract.contracts-table' => [
            'saveView', 'applyView', 'renameView', 'deleteView', 'savedViews',
            'confirmDelete', 'updatedPaginators', 'updatedPerPage', 'updatedRemembersTableState', 'clearAllFilters',
            'renderEmptyState', 'retryLoad', 'stateAnnouncement', 'setPreset', 'clearAll', 'removeChip',
            'dismissStateNotice', 'canClearAll', 'activeFilterChips', 'cardView',
        ],
        // Closing the read-only drawer only clears its own locked id.
        'contract.contract-drawer' => ['close'],
        'news.news-table' => ['confirmDelete'],
        'position.position-table' => ['confirmDelete', 'confirmToggleItemStatus'],
        'program-management.center.centers-table' => ['confirmDelete', 'confirmToggleItemStatus'],
        'program-management.default-deliverable.default-deliverables-table' => ['confirmDelete'],
        'program-management.program.detail' => ['confirmDelete', 'confirmToggleItemStatus'],
        'program-management.program.index' => ['confirmDelete', 'confirmToggleItemStatus', 'loadMore'],
        'employee.employee-table' => [
            'confirmDelete', 'confirmToggleItemStatus',
            'saveView', 'applyView', 'renameView', 'deleteView', 'savedViews', // B4-D saved views: own rows only, see contract.contracts-table
            // X2-I lifecycle hooks: Livewire refuses direct calls to updated*.
            // They only start an anonymous lookup attempt (TaskTelemetry).
            'updatedGlobalSearch', 'updatedSearch',
            'clearAllFilters', 'renderEmptyState', 'retryLoad', 'stateAnnouncement', // B3: view state / text only
            // B4: setView / clearAll / removeChip / dismissStateNotice change only the caller's own
            // view state (setView falls back to Active for a view the role may not use); rows still
            // come from query(). The rest is display text or an Alpine expression.
            'setView', 'clearAll', 'removeChip', 'dismissStateNotice', 'canClearAll', 'activeFilterChips',
            'cardView', 'rowClickAction', 'canUseInactiveView', 'canUseIncompleteView', 'effectiveView',
            'updatedPaginators', 'updatedPerPage', 'updatedRemembersTableState',
        ],
        // Closing the read-only drawer only clears its own id.
        'employee.employee-drawer' => ['close'],

        // --- X2-I task telemetry --------------------------------------------
        // dehydrateTracksTaskAttempt is a Livewire lifecycle hook: dehydrate*
        // cannot be called from the browser. It only flags an anonymous
        // attempt as having met a validation error (TracksTaskAttempt).
        'contract.contract-create' => ['dehydrateTracksTaskAttempt', 'mountRemembersReturnTo', // C2 lifecycle hook (Livewire refuses direct calls to mount*): reads ?from through SafeReturn, writes nothing
        ],

        // --- Authorized inside the method -----------------------------------
        // save() is create-or-update, so a single permission name in the
        // middleware map would be wrong in one direction or the other. Each of
        // these calls authorizeActivePermission() with the correct permission
        // for the branch it is taking, which the middleware cannot do.
        'basics.annual-leave-rule-module' => ['save', ...$closeGuard],
        'basics.checklist-item-module' => ['save', ...$closeGuard],
        'basics.contract-type-module' => ['save', ...$closeGuard],
        'basics.course-category-module' => ['save', ...$closeGuard],
        'basics.department-module' => ['save', ...$closeGuard],
        'basics.job-level-module' => ['save', ...$closeGuard],
        'basics.leave-type-module' => ['save', ...$closeGuard],
        'basics.termination-reason-module' => ['save', ...$closeGuard],
        'basics.holiday-module' => ['create', 'createOn', 'edit', 'save', 'selectGovernorate', ...$closeGuard],
        // choose() records the caller's own request for their own birthday;
        // decide() is refused unless the caller holds the volunteer's
        // direct-manager position (BirthdayHoliday::canDecide).
        'employee.leave.birthday-holiday-card' => ['choose', 'decide'],
        // save() writes only cities.hr_hotline (the ID card's emergency number,
        // A2D-Q7) after its own project-basics.update check.
        'basics.city-module' => ['save', ...$closeGuard],
        // Each re-reads the employee by its locked id and runs the
        // digital-id.manage or digital-id.report-lost gate (DigitalIdPolicy)
        // against the request's active role. A permission name would be wrong:
        // reportLost belongs to the card's owner, who holds no HR permission,
        // and manage is city-scoped HR, which a permission cannot express.
        'employee-cards.manage-digital-id' => ['reissue', 'disable', 'enable', 'reportLost'],
        // updatedFormNewsableType() only clears dependent form selections;
        // it does not read a record or persist data. save() authorizes before writing.
        'news.news-create' => ['save', 'updatedFormNewsableType',
            'mountRemembersReturnTo', // C2 lifecycle hook (Livewire refuses direct calls to mount*): reads ?from through SafeReturn, writes nothing
        ],
        // Also runs assertCityIsAuthorized() and a Gate check on the city.
        'program-management.center.centers-module' => ['save', ...$closeGuard],
        'program-management.default-deliverable.default-deliverables-module' => ['save', ...$closeGuard],
        // Each calls authorizeActivePermission() plus a LeaveRequestPolicy
        // check on the specific record, which is the stronger control.
        'employee.leave.my-leave-request-module' => [
            'create', 'edit', 'processSave', 'confirmSave', 'openDetails',
            'reconcileOperation', // X1-I: lookup by the form's token; authorizes leaves.create in the method
            'closeDetails', 'selectedLeave', 'submittedLeave', 'refreshCounts', 'setActiveTab',
            // B3: list states. resetStatusFilter only sets the tab to "all" (a filter on the
            // caller's own requests); retryLoad re-renders and writes nothing.
            'resetStatusFilter', 'retryLoad',
            // Only resets form.type to "day" when an own non-annual balance is picked.
            'updatedFormLeaveBalanceId',
            // Read-only, self-scoped: prices the typed window against a balance
            // of the signed-in employee's own active contract; writes nothing.
            'leaveImpact',
            ...$closeGuard,
            'dehydrateTracksTaskAttempt', // X2-I lifecycle hook, see contract.contract-create
            'mountRemembersReturnTo', // C2 lifecycle hook (Livewire refuses direct calls to mount*): reads ?from through SafeReturn, writes nothing
        ],
        'employee.leave.all-leave-request-module' => [
            'edit', 'show', 'processSave', 'confirmSave', 'confirmDelete',
            'closeDecision', 'closeDetails', 'refreshCounts', 'setActiveTab',
            'resetStatusFilter', // B3: sets the tab to "all"; a view filter only
            'updatedFormEmployeeId',
            ...$closeGuard,
            'dehydrateTracksTaskAttempt', // X2-I lifecycle hook, see contract.contract-create
            'mountRemembersReturnTo', // C2 lifecycle hook (Livewire refuses direct calls to mount*): reads ?from through SafeReturn, writes nothing
        ],
        'employee.leave.emp-leave-balance-module' => ['confirmSave', ...$closeGuard],
        // B3 HasScreenListStates: clearAllFilters resets search/filters/tab (view state only),
        // renderEmptyState and stateAnnouncement render text, retryLoad re-renders. No writes.
        // G-B-4: cardView only names the phone card's Blade view; rows still come from query().
        'employee.leave.all-leave-request-table' => [
            'edit', 'confirmDelete', 'confirmHrReview', 'refreshTable', 'setStatusFilter',
            'clearAllFilters', 'renderEmptyState', 'retryLoad', 'stateAnnouncement', 'cardView',
        ],
        'employee.leave.my-leave-request-table' => ['edit', 'confirmDelete', 'setStatusFilter', 'clearAllFilters', 'renderEmptyState', 'retryLoad', 'stateAnnouncement', 'cardView'],
        'employee.leave.emp-leave-balance-table' => ['clearAllFilters', 'renderEmptyState', 'retryLoad', 'stateAnnouncement', 'cardView'],
        // B3: read-only lists of the caller's own checklist rows; retryLoad re-renders only.
        'checklist-items.all-items' => ['retryLoad'],
        'checklist-items.pending-items' => ['retryLoad'],
        'checklist-items.pending-returns' => ['retryLoad'],
        'checklist-items.done-requests-table' => ['clearAllFilters', 'renderEmptyState', 'retryLoad', 'stateAnnouncement'],
        // A record read, not a state toggle: it takes a caller-supplied
        // employee id. It authorizes EmployeePolicy::viewDirectory on that id,
        // and the drawer re-checks on every render, so a role switch or a
        // former employee drops back to a uniform not-found (access-policy.md
        // §4.4). The middleware could only add positions.view, which every
        // role already holds and which says nothing about the record.
        'position.profile-drawer' => ['openProfile'],

        // --- Scoped to the caller -------------------------------------------
        // These resolve their subject from auth() and never accept a
        // caller-supplied owner id, so there is no cross-user surface for a
        // permission to protect.
        'dashboard.employee-notes-card' => [
            'addText', 'createNote', 'toggleDone', 'updateContent', 'delete', 'restoreNote', 'retryLoad',
            'placeholder', // prompt 4: renders the dashboard tile's loading skeleton, reads nothing
        ],
        'notifications.dropdown' => [
            'markAsRead', 'markAllAsRead', 'openNotification', 'refreshList', 'safeNotificationUrl',
            'retryLoad', // B3: re-renders the read-only list; marks nothing read
        ],
        'notifications.index' => [
            'markAsRead', 'markAllAsRead', 'openNotification', 'refreshList', 'safeNotificationUrl',
            'retryLoad', // B3: re-renders the read-only list; marks nothing read
        ],
        'notifications.counter' => ['updateCount', 'syncCount'],
        // D3 / DEC-08 feedback form: every signed-in role may send feedback, so
        // there is no permission to check. open() only shows the dialog;
        // submit() aborts for a guest, validates, is rate limited per user, and
        // writes one row owned by the caller whose role, language and page are
        // derived on the server (InAppFeedbackRecorder). Reading is separate.
        'feedback.feedback-form' => ['open', 'submit', ...$closeGuard],
        // D3 reading list: boot() runs Gate feedback.read (User::canReadFeedback)
        // on every request; a permission name here would be wrong, since
        // hasActivePermission() says yes to HR-SYR. updatedCategory is a
        // lifecycle hook that only changes the filter.
        'feedback.feedback-index' => ['updatedCategory'],
        // toggleFavourite/moveFavourite (prompt 9) write only the caller's own
        // navigation_preferences row, and only ids of the active role's menu
        // (NavigationMemory, Navigation::shortcuts); nothing else is accepted.
        'layout.sidebar' => ['toggle', 'close', 'refreshCounts', 'toggleFavourite', 'moveFavourite'],
        // D2 phone bar: re-renders its own links and the inbox count (WorkInbox, scoped to the caller).
        // refreshBar (prompt 10) only re-renders after the picker changed the caller's own shortcuts.
        'layout.bottom-nav' => ['refreshInbox', 'refreshBar'],
        // Prompt 10 phone bar picker: setSlot/resetBar write only the caller's own
        // navigation_preferences row, and only shortcuts the active role may use
        // (BottomBar::options: Home, My profile, the role's menu pages and New actions).
        'layout.bottom-bar-picker' => ['setSlot', 'resetBar'],

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
        'contract.contract-renew-modal' => ['close', ...$closeGuard],
        // B3: retryLoad re-renders read-only data; placeholder renders the lazy skeleton.
        // Every dashboard tile (prompt 4) is lazy, so each has placeholder
        // (IsDashboardTile: the card skeleton, reads nothing). The cards that were
        // sections of dashboard.index (team, news, upcoming events, pending
        // requests, checklist, badges) read only the caller's own records, or
        // what the dashboard has always shown everyone, exactly as before.
        'dashboard.calendar-card' => ['next', 'prev', 'goToday', 'jumpTo', 'retryLoad', 'placeholder'],
        'dashboard.contract-type-employees-statistics' => ['retryLoad', 'placeholder'],
        'dashboard.department-employee-statistics' => ['retryLoad', 'placeholder'],
        // saveLayout/resetLayout (HasDashboardLayout) are self-scoped: only the caller's
        // own row for the active role, cleaned so a tile the role may not have is dropped.
        // addTile is saveLayout followed by a render (the tile being added was never drawn).
        'dashboard.index' => ['retryLoad', 'saveLayout', 'resetLayout', 'addTile'],
        'dashboard.job-level-employee-statistics' => ['retryLoad', 'placeholder'],
        'dashboard.salary-per-employee-statistics' => ['retryLoad', 'placeholder'],
        'dashboard.upcoming-leaves-card' => ['retryLoad', 'placeholder'],
        'dashboard.no-leaves-employees-statistics' => ['retryLoad', 'placeholder'],
        'dashboard.soon-ending-contracts-card' => ['retryLoad', 'placeholder'],
        'dashboard.for-you-now' => ['placeholder'],
        'dashboard.my-leaves-card' => ['placeholder'],
        'dashboard.team-card' => ['retryLoad', 'placeholder'],
        'dashboard.news-card' => ['retryLoad', 'placeholder'],
        'dashboard.upcoming-events-card' => ['retryLoad', 'placeholder'],
        'dashboard.pending-requests-card' => ['retryLoad', 'placeholder'],
        'dashboard.checklist-card' => ['retryLoad', 'placeholder'],
        'dashboard.badges-card' => ['retryLoad', 'placeholder'],
        // Prompt 8 tiles, read-only. Who is out and birthdays read TeamCoverage on the
        // team calendar's default scope for the active role; next holiday and leave
        // balance read the caller's own contract only.
        'dashboard.who-is-out-card' => ['retryLoad', 'placeholder'],
        'dashboard.next-holiday-card' => ['retryLoad', 'placeholder'],
        'dashboard.leave-balance-card' => ['retryLoad', 'placeholder'],
        'dashboard.birthdays-card' => ['retryLoad', 'placeholder'],
        // The clock reads only the caller's own active contract (its schedule) and the holidays that reach it.
        'dashboard.clock-card' => ['retryLoad', 'placeholder'],
        // The late band and decision cards read the caller's own WorkInbox::shared
        // snapshot and Contract::visibleTo(); a big shortcut's card re-checks its key
        // against DashboardLayout::allowed() on every render and keeps its page's scope.
        'dashboard.late-card' => ['retryLoad', 'placeholder'],
        'dashboard.decisions-card' => ['retryLoad', 'placeholder'],
        'dashboard.app-card' => ['retryLoad', 'placeholder'],
        // skipDraft is pure navigation (a redirect, no data read or write) and
        // updatedDraftCityId/updatedDraftProgramId only reset the draft
        // cascade's own in-memory selections — the same class as
        // setActiveTab, not the mapped createDraftAndContinue/
        // positionOptionsForDraft/programOptionsForDraft/citiesForDraft/
        // canPickAnyDraftCity above.
        'employee.employee-create' => ['setActiveTab', 'toggleBoardMember', 'skipDraft', 'updatedDraftCityId', 'updatedDraftProgramId',
            'mountRemembersReturnTo', // C2 lifecycle hook (Livewire refuses direct calls to mount*): reads ?from through SafeReturn, writes nothing
        ],
        // C3 work inbox: no permission of its own, by design (approval-source-contract.md §5).
        // Each action re-reads the record and runs its domain owner, which authorizes inside
        // its lock: decide -> LeaveRequestForm::submitDecision (Gate decide, LeaveDecisionAuthority),
        // markSeen/object -> LeaveRequestReview (Gate review/object), confirmItem/applyBatch ->
        // ChecklistWorkflow (row scoped to the actor, plus checklists.deliver/receive),
        // finalizeItem -> Gate update on the employee + ChecklistWorkflow::finalizeReturn.
        // previewBatch writes nothing; setFilter changes the view only.
        // C5 team calendar: move/goToday/openDay/jumpTo only change the period shown.
        // What is shown comes from TeamCoverage on every render, scoped to the
        // active role; the URL values are re-checked there.
        'team.calendar' => ['move', 'goToday', 'openDay', 'jumpTo'],
        // approveProfile/rejectProfile (D1): ProfileChangeRequests::canDecide, i.e.
        // HR of the employee's city through EmployeePolicy::update, under a row lock.
        'inbox.index' => ['setFilter', 'decide', 'markSeen', 'object', 'confirmItem', 'finalizeItem', 'previewBatch', 'applyBatch', 'approveProfile', 'rejectProfile'],
        // C2 header search: a lifecycle hook (Livewire refuses direct calls to updated*) that only
        // trims the term and counts it against the per-user search limit. Results come from
        // DirectoryAccess::constrain() and the active role's menu on every render.
        // showRecents (prompt 9) only flags the empty box open; the caller's own recents are
        // read and filtered the same way (NavigationMemory) on render.
        'layout.global-search' => ['updatedQ', 'showRecents'],
        // The add*/openInitiateReturnModal methods stage rows in the in-memory
        // form object; nothing is written until the mapped save* action runs,
        // and mount() has already authorized 'update' on this employee.
        'employee.employee-edit' => [
            'setActiveTab', 'toggleBoardMember', 'addEducation', 'addCourse',
            'addExperience', 'addRelative', 'addAttachment', 'openInitiateReturnModal',
        ],
        // toggleVacancies only flips whether empty positions are drawn and
        // rebuilds the same tree; vacant positions hold no person's data.
        'position.org-chart' => [
            'zoomIn', 'zoomOut', 'resetZoom', 'fitToScreen', 'expandAll',
            'collapseAll', 'toggleCollapse', 'toggleDensity', 'resetFilters',
            'toggleVacancies',
        ],
        'position.directory' => ['resetFilters'],
        // Reset the dependent center selection and pass the city through
        // validPositionFormCityId(), which refuses a city outside the
        // caller's scope. Nothing is written until the mapped save() runs.
        'position.position-create' => ['updatedFormCityId'],
        'position.position-edit' => ['updatedFormCityId'],
        'program-management.center.center-details' => ['switchTab'],
        'news.today-events-card' => ['loadEvents', 'placeholder'],

        // --- Route-gated reads ----------------------------------------------
        // Reached only through routes carrying active_permission:statistics.view,
        // and both now apply the caller's city scope to every aggregate.
        'statistics.contract-statistics' => ['loadData', 'resetFilters'],
        'employee.employee-statistics' => ['getStatistics'],
        // D1 profile section editing. Not a single permission: openSection and
        // saveSection check EmployeeProfile::canEditSection() on every call
        // (EmployeePolicy::update for HR in scope, or the active owner for
        // their own contact and skills, profile-contract.md Q5), write only
        // that section's columns, and refuse a stale save. reloadSection
        // re-opens through the same check; the close guard writes nothing.
        // openProposal/submitProposal: the active owner only, for their own record
        // (ProfileChangeRequests::canPropose); nothing reaches the profile until
        // HR of the employee's city approves it in the inbox (section 5.1).
        'employee.employee-profile' => ['openSection', 'saveSection', 'reloadSection', 'openProposal', 'submitProposal', ...$closeGuard],
    ],
];
