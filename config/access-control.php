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
    |--------------------------------------------------------------------------
    | Permissions no role may ever hold
    |--------------------------------------------------------------------------
    |
    | These identify a specific person rather than a job, so a role carrying
    | one would hand that person's authority to everybody who holds the role.
    | They are created and displayed like any other permission and granted per
    | user from Access Management.
    |
    | Every bulk grant reads AccessPermissionCatalog::roleGrantableNames(),
    | which subtracts this list — the automatic grant for new administrator and
    | HR roles in App\Models\Role included. See config/recruitment.php for what
    | each one means.
    |
    */

    'never_granted_to_roles' => [
        'recruitment-requests.approve-finance',
        'recruitment-requests.submit-without-manager',
        // Not person-identifying, but held back from every bulk grant for the
        // same reason: candidate export and erasure are answerable acts, so a
        // new HR role must not arrive able to do either. They are read with
        // User::hasExplicitPermission(), which also refuses the administrator
        // bypass — an administrator is granted them like anyone else.
        'recruitment-applicants.export',
        'recruitment-applicants.request-erasure',
        // Rewrites what "met" means on every applicant's screen, so a new HR
        // role must not arrive able to. Administrator roles pass without it;
        // anyone else is granted it per user from Access Management.
        'recruitment-indicators.manage',
    ],

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
            // Recruitment: the CEO and his deputy hold this role, so they both
            // raise requests and approve their direct reports' requests.
            'recruitment-requests.view', 'recruitment-requests.manage',
            'recruitment-requests.submit', 'recruitment-requests.cancel',
            'recruitment-requests.approve', 'recruitment-requests.reject',
            'recruitment-requests.request-changes', 'recruitment-requests.export',
            'recruitment-vacancies.view', 'recruitment-campaigns.view',
            'recruitment-job-posts.view', 'recruitment-forms.view',
            'recruitment-links.view',
            // General managers endorse applicants for their campaigns.
            'recruitment-applicants.view', 'recruitment-applicants.endorse',
            // Read-only, across every city like HR-SYR: see Badge::scopeVisibleTo().
            'badges.view',
        ],
        'manager' => [
            // Read-only, and only their direct/technical reports' contracts:
            // see Contract::scopeVisibleTo().
            'contracts.view',
            'programs.view', 'centers.view', 'positions.view', 'leaves.view-own',
            'leaves.view-all', 'leaves.create', 'leaves.update', 'leaves.decide',
            'news.view', 'news.create', 'news.update',
            'checklists.view-own', 'project-basics.view', 'archives.view',
            'archives.create', 'archives.update', 'archives.delete', 'archives.share',
            'archives.download',
            // Recruitment: the same role covers both sides of the first step —
            // a manager raises his own requests and approves his direct
            // reports'. Holding 'approve' is never sufficient on its own; the
            // workflow still checks that this user is the assigned approver for
            // that step.
            'recruitment-requests.view', 'recruitment-requests.manage',
            'recruitment-requests.submit', 'recruitment-requests.cancel',
            'recruitment-requests.approve', 'recruitment-requests.reject',
            'recruitment-requests.request-changes', 'recruitment-requests.export',
            // Managers see and endorse applicants for their campaigns.
            'recruitment-vacancies.view', 'recruitment-campaigns.view',
            'recruitment-applicants.view', 'recruitment-applicants.endorse',
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

        /*
         * Recruitment module. Two of these are never attached to a role:
         * 'approve-finance' identifies the single organization-wide financial
         * approver, and 'submit-without-manager' marks the handful of people at
         * the top of the org chart who have no manager to approve their own
         * requests. Both are granted per user from Access Management — granting
         * either to a role would silently turn every holder of that role into a
         * financial approver, or let anyone skip manager approval. See
         * config/recruitment.php:user_level_permissions.
         */
        'recruitment-requests' => [
            'view', 'manage', 'submit', 'cancel',
            'approve', 'reject', 'request-changes',
            'direct-open', 'export',
            'approve-finance', 'submit-without-manager',
        ],
        'recruitment-vacancies' => ['view', 'manage'],
        'recruitment-campaigns' => ['view', 'manage'],
        'recruitment-job-posts' => ['view', 'manage'],
        'recruitment-forms' => ['view', 'manage'],
        'recruitment-links' => ['view', 'manage'],
        // The rules behind the matching indicators shown on every application.
        'recruitment-indicators' => ['manage'],

        /*
         * Applicant records. Each sensitive act is its own permission rather
         * than riding on 'view': seeing a list must never imply reading a CV,
         * and writing a note must never imply moving an application. Tagging
         * one record, tagging many at once, and editing the tag list itself
         * are separate for the same reason. Accepting is a hiring decision
         * layered on top of change-status, and rejecting many at once (which
         * emails every one of them) is a grant of its own. 'manage' covers
         * campaign invites.
         */
        'recruitment-applicants' => [
            'view', 'manage', 'download-cv', 'add-notes', 'delete-notes', 'archive-cv', 'change-status', 'endorse',
            'tag', 'bulk-tag', 'manage-tags', 'accept', 'bulk-reject',
            // Phase 2 Task 7: create / edit / reschedule / cancel interviews
            // and record their outcome. Participants need no permission.
            'schedule-interviews',
            // Phase 2 Task 8: the aggregated view of every reviewer's
            // evaluation, and correcting / withdrawing someone else's.
            // Writing one's own evaluation needs none: it comes from being on
            // the interview.
            'view-evaluations', 'correct-evaluations',
            // Phase 2 Task 9: follow job offers / write them. Writing also
            // needs User::canViewSalary(), checked by the policy.
            'view-offers', 'manage-offers',
            // The pay figure of a sent offer, for the manager who requested
            // the position (RecruitmentJobOfferPolicy::viewSalary). Granted on
            // purpose — to the manager role or to one user — never by default.
            'view-offer-salary',
            // Phase 2 Task 10: turn an accepted offer into an employee and a
            // contract. The policy also needs contracts.create and
            // canViewSalary(), and the employee side is checked against
            // EmployeePolicy.
            'convert-hires',
            // Phase 2 Task 11: take candidate data out of the system as a
            // file, and anonymize an applicant who asked to be forgotten.
            // Neither is attached to any role — see never_granted_to_roles.
            'export', 'request-erasure',
        ],
    ],

    /*
     * Server-side guards for mutation methods with an unambiguous action.
     * Components that use one save method for both create and update authorize
     * inside the component because the required permission depends on state.
     */
    'livewire' => [
        'recruitment.requests.create' => ['save' => 'recruitment-requests.manage'],
        'recruitment.campaigns.index' => [
            'create' => 'recruitment-campaigns.manage',
            'beginScheduleEdit' => 'recruitment-campaigns.manage',
            'updateSchedule' => 'recruitment-campaigns.manage',
            'openCampaign' => 'recruitment-campaigns.manage',
            'closeCampaign' => 'recruitment-campaigns.manage',
            'beginDisable' => 'recruitment-campaigns.manage',
            'disableCampaign' => 'recruitment-campaigns.manage',
        ],
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
        // The permission map is the coarse HTTP boundary. Each action also
        // runs JobPostPolicy so governorate HR remains inside its city scope.
        'recruitment.job-posts.editor' => [
            'saveDraft' => 'recruitment-job-posts.manage',
            'publish' => 'recruitment-job-posts.manage',
            'unpublish' => 'recruitment-job-posts.manage',
            'reloadLatest' => 'recruitment-job-posts.manage',
            'resetFromApprovedRequest' => 'recruitment-job-posts.manage',
        ],
        'recruitment.application-forms.editor' => [
            'addField' => 'recruitment-forms.manage',
            'addCustomField' => 'recruitment-forms.manage',
            'applyReferenceTemplate' => 'recruitment-forms.manage',
            'applyPublishedTemplate' => 'recruitment-forms.manage',
            'removeField' => 'recruitment-forms.manage',
            'moveField' => 'recruitment-forms.manage',
            'addOption' => 'recruitment-forms.manage',
            'removeOption' => 'recruitment-forms.manage',
            'setPreviewSection' => 'recruitment-forms.manage',
            'toggleSection' => 'recruitment-forms.manage',
            'setPreviewLocale' => 'recruitment-forms.manage',
            'saveDraft' => 'recruitment-forms.manage',
            'publish' => 'recruitment-forms.manage',
            'reloadLatest' => 'recruitment-forms.manage',
        ],
        'recruitment.public-links.manager' => [
            'generate' => 'recruitment-links.manage',
            'disable' => 'recruitment-links.manage',
            'addInvite' => 'recruitment-links.manage',
            'cancelInvite' => 'recruitment-links.manage',
        ],
        // Team 2's invite screen (also embedded in the publishing page). Each
        // action also runs RecruitmentCampaignInvitePolicy (campaign scope).
        'recruitment.campaigns.invites' => ['add' => 'recruitment-applicants.manage'],
        'recruitment.campaigns.invite-table' => ['cancelInvite' => 'recruitment-applicants.manage'],
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

        // Each method re-resolves its record through the applicant scope and
        // asks the policy again; the permission here is only the outer wall.
        'recruitment.applicants.show' => [
            'endorse' => 'recruitment-applicants.endorse',
            'retractEndorsement' => 'recruitment-applicants.endorse',
            'beginStatusChange' => 'recruitment-applicants.change-status',
            'changeStatus' => 'recruitment-applicants.change-status',
            'beginArchive' => 'recruitment-applicants.archive-cv',
            'archiveCv' => 'recruitment-applicants.archive-cv',
        ],
        // The selection is re-scoped in RecruitmentApplicationStatusService,
        // which also asks the bulkReject policy itself.
        'recruitment.applicants.bulk-reject' => [
            'open' => 'recruitment-applicants.bulk-reject',
            'apply' => 'recruitment-applicants.bulk-reject',
        ],
        'recruitment.indicators.rules' => [
            'save' => 'recruitment-indicators.manage',
            'resetToDefaults' => 'recruitment-indicators.manage',
        ],
        'recruitment.applicants.notes' => [
            'addNote' => 'recruitment-applicants.add-notes',
            'confirmDelete' => 'recruitment-applicants.delete-notes',
            'deleteNote' => 'recruitment-applicants.delete-notes',
        ],
        // Phase 2 Task 7. Each method re-resolves the interview through
        // RecruitmentInterview::visibleTo() and asks the policy (manage /
        // schedule, scope included) again; the service authorizes a third time.
        'recruitment.interviews.form' => ['save' => 'recruitment-applicants.schedule-interviews'],
        'recruitment.interviews.show' => [
            'beginCancel' => 'recruitment-applicants.schedule-interviews',
            'cancelInterview' => 'recruitment-applicants.schedule-interviews',
            'beginOutcome' => 'recruitment-applicants.schedule-interviews',
            'recordOutcome' => 'recruitment-applicants.schedule-interviews',
        ],
        // Phase 2 Task 8. Each method re-resolves the interview and the
        // evaluation through the scoped query and asks the policy again; the
        // service authorizes a third time.
        'recruitment.interviews.evaluation-summary' => [
            'beginCorrect' => 'recruitment-applicants.correct-evaluations',
            'saveCorrection' => 'recruitment-applicants.correct-evaluations',
            'beginWithdraw' => 'recruitment-applicants.correct-evaluations',
            'withdraw' => 'recruitment-applicants.correct-evaluations',
            'closeEvaluations' => 'recruitment-applicants.schedule-interviews',
        ],
        // Phase 2 Task 9. Each method re-resolves the offer through the scoped
        // query and asks the policy (manage: permission + scope +
        // canViewSalary) again; the service authorizes a third time.
        'recruitment.offers.form' => ['save' => 'recruitment-applicants.manage-offers'],
        'recruitment.offers.revise' => ['save' => 'recruitment-applicants.manage-offers'],
        'recruitment.offers.show' => [
            'beginSend' => 'recruitment-applicants.manage-offers',
            'send' => 'recruitment-applicants.manage-offers',
            'beginResponse' => 'recruitment-applicants.manage-offers',
            'recordResponse' => 'recruitment-applicants.manage-offers',
            'beginCancel' => 'recruitment-applicants.manage-offers',
            'cancelOffer' => 'recruitment-applicants.manage-offers',
        ],
        // Phase 2 Task 10. save() re-resolves the offer through the scoped
        // query and asks the policy (convert) again; the service authorizes a
        // third time, and again for the employee it creates or links.
        // confirmHire() only asks first (a confirm dialog that runs save()).
        'recruitment.offers.convert' => [
            'confirmHire' => 'recruitment-applicants.convert-hires',
            'save' => 'recruitment-applicants.convert-hires',
        ],
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
        // Form-only resets. Persistence rechecks role, grade and subtree in the draft service.
        'recruitment.requests.create' => ['updatedDataRequestType', 'updatedDataPositionId', 'updatedDataCityId'],
        // Close an open panel; they clear component state and touch no record.
        'recruitment.applicants.show' => ['cancelStatusChange', 'cancelArchive'],
        // refreshNotes only re-renders, through the same scoped query as the first render.
        'recruitment.applicants.notes' => ['cancelDelete', 'refreshNotes'],
        // Re-render through the same scoped query; they write nothing.
        // selectTable() reads ids through the same scoped, filtered query.
        // executeAction/executeItemAction authorize their action code by policy.
        'recruitment.applicants.application-table' => [
            'refreshDecisions', 'selectTable', 'executeAction', 'executeItemAction',
        ],
        'recruitment.applicants.bulk-reject' => ['cancel'],
        // Phase 2 Task 7. Form-only state: the participant list is validated
        // user by user (exists, active) by the scheduling service on save,
        // which is the mapped action. closePanel clears component state only.
        'recruitment.interviews.form' => ['addParticipant', 'removeParticipant'],
        // dismissPanel closes the action popup: component state only.
        'recruitment.interviews.show' => ['dismissPanel'],
        // Month navigation; re-renders through the same visibleTo() query.
        'recruitment.interviews.calendar' => ['previousMonth', 'nextMonth', 'thisMonth'],
        // Phase 2 Task 8. save() writes the caller's OWN evaluation, which
        // needs no permission — the grant is being a current participant in a
        // reviewer role, checked by the evaluate policy in the component and
        // again, under a row lock, in RecruitmentInterviewEvaluationService.
        // reload() only re-reads the caller's own row.
        'recruitment.interviews.evaluation-form' => ['save', 'reload'],
        // dismissPanel closes the action popup (component state only);
        // refreshEvaluations re-renders through the same scoped query and
        // writes nothing.
        'recruitment.interviews.evaluation-summary' => ['dismissPanel', 'refreshEvaluations'],
        // Phase 2 Task 9. Clears panel state only.
        'recruitment.offers.show' => ['closePanel'],
        // Phase 2 Task 10. The post-hire message's buttons: redirects only,
        // after re-resolving the offer through the scoped query; the employee
        // and contract edit pages check their own permissions.
        'recruitment.offers.convert' => ['openEmployee', 'openContract'],

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
        'recruitment.campaigns.invite-table' => ['confirmCancel'],
        'basics.annual-leave-rule-table' => ['confirmDelete'],
        'basics.checklist-item-table' => ['confirmDelete'],
        'basics.city-table' => ['confirmToggleItemStatus'],
        'basics.contract-type-table' => ['confirmDelete'],
        'basics.course-category-table' => ['confirmDelete'],
        'basics.department-table' => ['confirmDelete'],
        'basics.job-level-table' => ['confirmDelete'],
        'basics.leave-type-table' => ['confirmDelete'],
        'basics.termination-reason-table' => ['confirmDelete'],
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
        'contract.contract-create' => ['dehydrateTracksTaskAttempt'],

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
        'news.news-create' => ['save', 'updatedFormNewsableType'],
        // Also runs assertCityIsAuthorized() and a Gate check on the city.
        'program-management.center.centers-module' => ['save', ...$closeGuard],
        'program-management.default-deliverable.default-deliverables-module' => ['save', ...$closeGuard],
        // Each calls authorizeActivePermission() plus a LeaveRequestPolicy
        // check on the specific record, which is the stronger control.
        'employee.leave.my-leave-request-module' => [
            'create', 'edit', 'processSave', 'confirmSave', 'openDetails',
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
        ],
        'employee.leave.all-leave-request-module' => [
            'edit', 'show', 'processSave', 'confirmSave', 'confirmDelete',
            'closeDecision', 'closeDetails', 'refreshCounts', 'setActiveTab',
            'resetStatusFilter', // B3: sets the tab to "all"; a view filter only
            'updatedFormEmployeeId',
            ...$closeGuard,
            'dehydrateTracksTaskAttempt', // X2-I lifecycle hook, see contract.contract-create
        ],
        'employee.leave.emp-leave-balance-module' => ['confirmSave', ...$closeGuard],
        // B3 HasScreenListStates: clearAllFilters resets search/filters/tab (view state only),
        // renderEmptyState and stateAnnouncement render text, retryLoad re-renders. No writes.
        'employee.leave.all-leave-request-table' => [
            'edit', 'confirmDelete', 'confirmHrReview', 'refreshTable', 'setStatusFilter',
            'clearAllFilters', 'renderEmptyState', 'retryLoad', 'stateAnnouncement',
        ],
        'employee.leave.my-leave-request-table' => ['edit', 'confirmDelete', 'setStatusFilter', 'clearAllFilters', 'renderEmptyState', 'retryLoad', 'stateAnnouncement'],
        'employee.leave.emp-leave-balance-table' => ['clearAllFilters', 'renderEmptyState', 'retryLoad', 'stateAnnouncement'],
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
        'contract.contract-renew-modal' => ['close', ...$closeGuard],
        'dashboard.calendar-card' => ['next', 'prev'],
        // B3: retryLoad re-renders read-only data; placeholder renders the lazy skeleton.
        'dashboard.contract-type-employees-statistics' => ['retryLoad'],
        'dashboard.department-employee-statistics' => ['retryLoad'],
        'dashboard.index' => ['retryLoad'],
        'dashboard.job-level-employee-statistics' => ['retryLoad'],
        'dashboard.salary-per-employee-statistics' => ['retryLoad'],
        'dashboard.upcoming-leaves-card' => ['retryLoad'],
        'dashboard.no-leaves-employees-statistics' => ['retryLoad', 'placeholder'],
        'dashboard.soon-ending-contracts-card' => ['retryLoad', 'placeholder'],
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
        'news.today-events-card' => ['loadEvents'],

        // --- Route-gated reads ----------------------------------------------
        // Reached only through routes carrying active_permission:statistics.view,
        // and both now apply the caller's city scope to every aggregate.
        'statistics.contract-statistics' => ['loadData', 'resetFilters'],
        'employee.employee-statistics' => ['getStatistics'],
    ],
];
