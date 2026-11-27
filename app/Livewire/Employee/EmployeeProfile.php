<?php

namespace App\Livewire\Employee;

use App\Enums\ContractType;
use App\Enums\MaritalStatus;
use App\Livewire\Concerns\GuardsModalClose;
use App\Livewire\Concerns\WithFeedback;
use App\Models\Badge;
use App\Models\Employee;
use App\Models\ProfileChangeRequest;
use App\Models\User;
use App\Services\EmployeeTimeline;
use App\Services\ProfileChangeRequests;
use App\Services\ProfileEditNotifier;
use App\Services\TaskTelemetry;
use App\Services\WorkPresence;
use App\Support\DirectoryAccess;
use App\Support\NavigationMemory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

/**
 * The full profile page. Who may open it, and which sections they get, is
 * docs/ux/access-policy.md §4.3 (signed off 2026-10-20):
 *
 * - D (header, position, work contact, manager): EmployeePolicy::viewDirectory.
 *   Anyone else gets the same 404 as an unknown username (§4.4).
 * - R (career history without pay, education, experience, skills, courses):
 *   EmployeePolicy::viewRecords — self, a manager's direct reports, the
 *   general manager, HR in city scope, HR-SYR, super-admin.
 * - P (identity, personal contact, family): self or HR in city scope.
 * - F (pay): HR in city scope and User::canViewSalary().
 * - Badges: BadgePolicy::viewForEmployee (Badge::scopeVisibleTo).
 *
 * Sections a viewer may not see are not loaded at all, rather than loaded and
 * hidden. The flags are recomputed on every request, not only in mount(), so
 * an active-role switch takes effect on the next request.
 *
 * D1 (profile-contract.md): a timeline of career events and badges
 * (EmployeeTimeline, Q1 = B); the owner sees their own rewards and warnings
 * (Q2 = C); and the small sections are edited in place, one at a time
 * (section 5) by HR in scope or by the employee themself. A save writes only
 * that section's columns, and is refused as stale when the section changed
 * since the dialog opened.
 *
 * Meeting 19/11 (track 2): the employee edits everything but contracts and
 * identity directly, with no approval, and HR of their city is told what
 * changed (ProfileEditNotifier). That covers personal contact, skills, work
 * contact, marital status and children (the last three widened on the
 * product owner's word, 2026-11-19), and new education, course, experience,
 * family entries and the photo (ProfileChangeRequests::add). Identity (name,
 * national ID, birth, nationality, civil registry), contracts and pay stay
 * with HR on the edit page.
 */
class EmployeeProfile extends Component
{
    use GuardsModalClose, WithFeedback, WithFileUploads;

    /** The sections edited in place, and their columns. Never an identity, contract or pay column. */
    public const SECTIONS = [
        'personal-contact' => ['personal_phone', 'personal_email', 'address_ar', 'address_en'],
        'skills' => ['skills', 'interests'],
        'work-contact' => ['work_phone', 'whatsapp_work', 'telegram_work'],
        'family-status' => ['marital_status', 'children'],
    ];

    public Employee $employee;

    /** The section whose dialog is open, or '' (deep link: ?edit=skills). */
    #[Url(as: 'edit', except: '')]
    public string $editing = '';

    public bool $sectionModal = false;

    /** @var array<string, mixed> the open section's entries */
    public array $sectionFields = [];

    /** What the section held when its dialog opened (stale-edit check). */
    #[Locked]
    public string $sectionVersion = '';

    /** The owner adding a new entry (or the photo): which section, and its entries. */
    public bool $entryModal = false;

    public string $adding = '';

    /** @var array<string, mixed> */
    public array $entry = [];

    /** A certificate, or the new photo. */
    public $entryFile = null;

    #[Locked]
    public bool $isSelf = false;

    #[Locked]
    public bool $canViewPrivate = false;

    #[Locked]
    public bool $canViewFinancial = false;

    #[Locked]
    public bool $canEdit = false;

    #[Locked]
    public bool $canViewBadges = false;

    // Contracts (without pay), education, experience, skills and courses.
    #[Locked]
    public bool $canViewRecords = false;

    public function mount(?Employee $employee = null): void
    {
        $this->employee = $employee?->exists
            ? $employee
            : Auth::user()?->profile ?? abort(404);

        $this->resolveAccess();

        // X2-I task B: opening someone's profile completes an open lookup.
        if ($employee?->exists) {
            app(TaskTelemetry::class)->completeLookup();

            // Prompt 9: recent people, recorded only once the page has
            // authorized the visit (and checked again when listed).
            if (! $this->isSelf) {
                try {
                    NavigationMemory::recordPerson(Auth::user(), (int) $this->employee->id);
                } catch (Throwable $e) {
                    report($e);
                }
            }
        }

        // A deep link to a section the viewer may not edit just opens the page.
        if ($this->editing !== '') {
            $this->canEditSection($this->editing) ? $this->openSection($this->editing) : $this->editing = '';
        }
    }

    // -- Section editing (D1, profile-contract.md section 5) ----------------

    public function openSection(string $section): void
    {
        abort_unless($this->canEditSection($section), 404);

        $this->resetValidation();
        $this->editing = $section;
        $this->sectionFields = $this->sectionValues($this->employee->fresh(), $section);
        $this->sectionVersion = $this->versionOf($this->sectionFields);
        $this->markClean('sectionModal');
        $this->sectionModal = true;
    }

    public function saveSection(): void
    {
        $section = $this->editing;
        abort_unless($this->canEditSection($section), 404);

        $columns = self::SECTIONS[$section];
        $entries = [];

        foreach ($columns as $column) {
            $value = $this->sectionFields[$column] ?? null;
            $value = is_string($value) ? trim($value) : $value;
            $entries[$column] = $value === '' ? null : $value;
        }

        foreach (['personal_phone', 'work_phone', 'children'] as $phone) {
            if (array_key_exists($phone, $entries) && $entries[$phone] !== null) {
                $entries[$phone] = en_digits((string) $entries[$phone]);
            }
        }

        $validator = Validator::make($entries, array_intersect_key($this->sectionRules(), $entries), [
            'work_phone.regex' => __('employees.validation.work-phone-format'),
            'personal_phone.regex' => __('employees.validation.personal-phone-format'),
        ], $this->sectionAttributes());

        if ($validator->fails()) {
            // Errors on the dialog's own fields; the entries stay as typed.
            foreach ($validator->errors()->messages() as $field => $messages) {
                $this->addError('sectionFields.'.$field, $messages[0]);
            }

            return;
        }

        $stale = false;
        $wrote = false;

        DB::transaction(function () use ($section, $entries, &$stale, &$wrote): void {
            $current = Employee::query()->whereKey($this->employee->getKey())->lockForUpdate()->firstOrFail();

            // Someone saved this section since the dialog opened: write nothing.
            if ($this->versionOf($this->sectionValues($current, $section)) !== $this->sectionVersion) {
                $stale = true;

                return;
            }

            // Only this section's columns; LogsActivity records the change and its author.
            $current->forceFill($entries);
            $changes = [];

            foreach (array_keys($current->getDirty()) as $column) {
                $changes[$column] = [$current->getOriginal($column), $current->getAttribute($column)];
            }

            $wrote = $changes !== [];
            $current->save();

            // The employee's own edit is reported to HR of their city, once per save, after the commit.
            app(ProfileEditNotifier::class)->fieldsChanged(Auth::user(), $current, $changes);
        });

        if ($stale) {
            $this->addError('section', __('employees.profile.section-stale'));

            return;
        }

        $this->employee->refresh();
        $this->closeGuardedDialog('sectionModal');
        unset($this->closeBaselines['sectionModal']);
        $wrote ? $this->toast(__('employees.profile.section-saved')) : $this->toast(__('general.no-changes-message'), type: 'info');
    }

    // -- New entries added by the owner (meeting 19/11, track 2) ------------

    public function openEntry(string $section): void
    {
        abort_unless($this->canAdd() && in_array($section, ProfileChangeRequest::SECTIONS, true), 404);

        $this->resetValidation();
        $this->adding = $section;
        $this->entry = array_fill_keys(array_keys(app(ProfileChangeRequests::class)->rules($section)), null);
        $this->entryFile = null;
        $this->markClean('entryModal');
        $this->entryModal = true;
    }

    public function saveEntry(): void
    {
        abort_unless($this->canAdd() && in_array($this->adding, ProfileChangeRequest::SECTIONS, true), 404);

        try {
            app(ProfileChangeRequests::class)->add(Auth::user(), $this->adding, $this->entry, $this->entryFile);
        } catch (AuthorizationException) {
            abort(404);
        } catch (ValidationException $e) {
            // On the dialog's own fields; the entries stay as typed.
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field === 'file' ? 'entryFile' : 'entry.'.$field, $messages[0]);
            }

            return;
        }

        $this->employee->refresh();
        $this->closeGuardedDialog('entryModal');
        unset($this->closeBaselines['entryModal']);
        $this->toast(__('employees.profile.change.added'));
    }

    /** After a stale refusal: load what is saved now, discarding the entries. */
    public function reloadSection(): void
    {
        $this->openSection($this->editing);
    }

    /** The active role may have changed since the page was opened. */
    public function hydrate(): void
    {
        $this->resolveAccess();
    }

    public function render()
    {
        $contractColumns = [
            'id', 'employee_id', 'position_id', 'start_date', 'end_date',
            'terminated_at', 'is_active',
        ];

        // Contract type, termination reason and weekly hours are career
        // history; the header only needs the current position.
        if ($this->canViewRecords) {
            array_push($contractColumns, 'type', 'contract_type_id', 'termination_reason_id', 'weekly_hours');
        }

        if ($this->canViewFinancial) {
            array_push($contractColumns, 'salary', 'currency', 'salary_per');
        }

        $contractRelations = [
            'position.center',
            'position.program',
            'position.city',
            'position.jobLevel',
            'position.department',
            // The manager is a Directory entry: a former one stays unnamed
            // for anyone who may not see former employees.
            'position.directManager.activeContracts' => DirectoryAccess::holders(Auth::user()),
            'position.directManager.activeContracts.employee' => DirectoryAccess::holderEmployee(),
        ];

        if ($this->canViewRecords) {
            array_push($contractRelations, 'contractType', 'terminationReason', 'appliedAmendments.payTiers');
        }

        $relations = [
            DirectoryAccess::ACCOUNT_COLUMNS,
            // Without the history the header and summary still need the
            // current contract, so only that one is loaded.
            'allContracts' => fn ($query) => $query
                ->select($contractColumns)
                ->unless($this->canViewRecords, fn ($query) => $query
                    ->where('is_active', true)
                    ->whereNull('terminated_at')
                    ->whereDate('start_date', '<=', today())
                    ->whereDate('end_date', '>=', today()))
                ->with($contractRelations)
                ->latest('start_date'),
        ];

        if ($this->canViewRecords) {
            array_push($relations, 'educations', 'experiences', 'courses.category');
        }

        if ($this->canViewBadges) {
            $relations['badges'] = fn ($query) => $query
                ->select($this->canViewFinancial
                    ? ['id', 'employee_id', 'evaluation_id', 'currency', 'type', 'date', 'reason', 'current_salary', 'new_salary', 'notes']
                    : ['id', 'employee_id', 'evaluation_id', 'type', 'date', 'reason', 'notes'])
                ->latest('date');
        }

        $employee = $this->employee->load($relations);

        // Q2 = C: the owner sees their own rewards and warnings, without pay or notes.
        $badges = $this->canViewBadges
            ? $employee->badges
            : ($this->isSelf ? app(EmployeeTimeline::class)->ownBadges($employee) : collect());

        if ($this->canViewPrivate) {
            $employee->load('relatives');
        }

        $contracts = $employee->allContracts;
        $currentContract = $contracts->first(fn ($contract) => $contract->is_active
            && $contract->start_date?->lte(today())
            && $contract->end_date?->gte(today())
            && ! $contract->terminated_at);
        $upcomingContract = $currentContract === null && $this->canViewRecords
            ? $contracts->filter(fn ($contract) => $contract->is_active
                && (int) $contract->type !== ContractType::Draft
                && ! $contract->isTerminated()
                && $contract->start_date?->gt(today())
                && $contract->end_date?->gte($contract->start_date))
                ->sortBy('start_date')->first()
            : null;
        $currentPosition = ($currentContract ?? $upcomingContract)?->position;

        return view('livewire.employee.employee-profile', [
            'employee' => $employee,
            'badges' => $badges,
            // Career history is R; a Directory-only viewer gets no timeline at all.
            'timeline' => $this->canViewRecords
                ? app(EmployeeTimeline::class)->events($contracts, $badges, $this->canViewFinancial)
                : [],
            'editable' => array_values(array_filter(array_keys(self::SECTIONS), fn (string $s) => $this->canEditSection($s))),
            // The owner adds entries; proposals sent before 19/11 still show their state to the owner alone.
            'canAdd' => $this->canAdd(),
            'proposals' => $this->isSelf ? $this->ownProposals() : [],
            'contracts' => $contracts,
            'currentContract' => $currentContract,
            'upcomingContract' => $upcomingContract,
            'currentPosition' => $currentPosition,
            'isOnLeave' => $employee->is_active && $employee->isOnLeaveToday(),
            'presence' => app(WorkPresence::class)->forOne(Auth::user(), $employee),
            'directManager' => $currentPosition?->directManager?->activeContracts?->first()?->employee,
            // Length of service is read off the career history (R).
            'yearsOfService' => $this->canViewRecords ? $employee->years_of_service_formatted : null,
            'skills' => $this->canViewRecords ? $this->parseTags($employee->skills) : [],
            'interests' => $this->canViewRecords ? $this->parseTags($employee->interests) : [],
            'canViewPrivate' => $this->canViewPrivate,
            'canViewFinancial' => $this->canViewFinancial,
            'canEdit' => $this->canEdit,
            'canViewBadges' => $this->canViewBadges,
            'canViewRecords' => $this->canViewRecords,
        ]);
    }

    protected function guardedDialogs(): array
    {
        return ['sectionModal', 'entryModal'];
    }

    protected function guardedEntries(string $dialog): array
    {
        return $dialog === 'entryModal'
            ? [...$this->entry, 'file' => $this->entryFile ? 'chosen' : null]
            : $this->sectionFields;
    }

    protected function closeGuardedDialog(string $dialog): void
    {
        if ($dialog === 'entryModal') {
            $this->entryModal = false;
            $this->adding = '';
            $this->entry = [];
            $this->entryFile = null;
            $this->resetValidation();

            return;
        }

        $this->sectionModal = false;
        $this->editing = '';
        $this->sectionFields = [];
        $this->sectionVersion = '';
        $this->resetValidation();
    }

    /** Only the active owner adds entries, and only to their own profile. */
    private function canAdd(): bool
    {
        return $this->isSelf && app(ProfileChangeRequests::class)->canAdd(Auth::user());
    }

    /**
     * Who may edit $section of this profile: HR in scope, or the active owner
     * (meeting 19/11: every section here, none of them identity, contract or pay).
     */
    private function canEditSection(string $section): bool
    {
        if (! array_key_exists($section, self::SECTIONS)) {
            return false;
        }

        $user = Auth::user();

        return ($this->isSelf && $this->employee->is_active)
            || ($user !== null && Gate::forUser($user)->allows('update', $this->employee));
    }

    /** @return array<string, mixed> */
    private function sectionValues(Employee $employee, string $section): array
    {
        $values = [];

        // Through the casts: several of these columns are encrypted at rest.
        foreach (self::SECTIONS[$section] as $column) {
            $values[$column] = $employee->getAttribute($column);
        }

        return $values;
    }

    private function versionOf(array $values): string
    {
        return hash('sha256', json_encode(array_map(fn ($v) => $v === null ? null : (string) $v, $values)));
    }

    /** The edit page's rules (PersonalInfoForm) for these columns, plus lengths. */
    private function sectionRules(): array
    {
        return [
            'personal_phone' => ['nullable', 'regex:/^09\d{8}$/'],
            'work_phone' => ['nullable', 'regex:/^09\d{8}$/'],
            'personal_email' => ['nullable', 'email', 'max:255'],
            'address_ar' => ['nullable', 'string', 'max:255'],
            'address_en' => ['nullable', 'string', 'max:255'],
            'skills' => ['nullable', 'string', 'max:2000'],
            'interests' => ['nullable', 'string', 'max:2000'],
            'whatsapp_work' => ['nullable', 'string', 'max:255'],
            'telegram_work' => ['nullable', 'string', 'max:255'],
            // PersonalInfoForm: marital status is required, children a count.
            'marital_status' => ['required', Rule::in(MaritalStatus::getValues())],
            'children' => ['nullable', 'integer', 'min:0', 'max:30'],
        ];
    }

    private function sectionAttributes(): array
    {
        return [
            'personal_phone' => __('general.personal-phone'),
            'work_phone' => __('general.work-phone'),
            'personal_email' => __('general.email'),
            'address_ar' => __('employees.profile.address-ar'),
            'address_en' => __('employees.profile.address-en'),
            'skills' => __('general.skills'),
            'interests' => __('general.interests'),
            'whatsapp_work' => 'WhatsApp',
            'telegram_work' => 'Telegram',
            'marital_status' => __('general.marital-status'),
            'children' => trim(__('general.children')),
        ];
    }

    /**
     * Authorize the page and work out which sections the active role gets.
     * Runs on mount and again on every later request.
     */
    private function resolveAccess(): void
    {
        /** @var User|null $user */
        $user = Auth::user();
        abort_unless($user, 403);

        $gate = Gate::forUser($user);

        // Unknown usernames already 404 in route binding; a profile outside
        // the viewer's directory cell must look exactly the same.
        abort_if($gate->denies('viewDirectory', $this->employee), 404);

        $this->isSelf = (int) $user->employee_id === (int) $this->employee->id;
        $isHr = $user->activeRoleIs('HR', 'super-admin');
        $isManagement = $user->activeRoleIs('general-manager');
        $isHrInScope = $isHr && $user->canAccessCity($this->employee->city_id);

        // EmployeePolicy::viewPrivate: self, or HR (super-admin counted) in scope; the
        // dashboard's private section cards ask the same ability.
        $this->canViewPrivate = $gate->allows('viewPrivate', $this->employee);

        // Narrower than $isHrInScope on purpose: $isHr above counts
        // super-admin as HR so a developer can open any profile, and pay is
        // the one thing that must not come with that. See User::canViewSalary.
        $this->canViewFinancial = $isHrInScope && $user->canViewSalary();
        $this->canEdit = $isHrInScope || $isManagement;
        $this->canViewRecords = $gate->allows('viewRecords', $this->employee);
        // Same rule as the Badges page and the dashboard card, through
        // Badge::scopeVisibleTo(): regional HR its own city, HR-SYR and
        // general-manager every city, a manager granted badges.view their
        // reports. Being the profile's owner grants nothing by itself.
        $this->canViewBadges = $gate->allows('viewForEmployee', [Badge::class, $this->employee]);
    }

    /**
     * The owner's open proposals and, per section, the reason of a refusal
     * that has not been followed by a newer proposal.
     *
     * @return array<string, array{pending: bool, rejected: ?string}>
     */
    private function ownProposals(): array
    {
        return ProfileChangeRequest::query()
            ->where('employee_id', $this->employee->id)
            ->whereIn('status', [ProfileChangeRequest::PENDING, ProfileChangeRequest::REJECTED])
            ->latest('id')
            ->get(['id', 'section', 'status', 'reason'])
            ->groupBy('section')
            ->map(function ($changes) {
                $latest = $changes->first();

                return [
                    'pending' => $latest->status === ProfileChangeRequest::PENDING,
                    'rejected' => $latest->status === ProfileChangeRequest::REJECTED ? $latest->reason : null,
                ];
            })
            ->all();
    }

    private function parseTags(?string $value): array
    {
        if (! $value) {
            return [];
        }

        $decoded = json_decode($value, true);
        $tags = is_array($decoded) ? $decoded : explode(',', $value);

        return array_values(array_filter(array_map(
            fn ($tag) => trim((string) $tag),
            $tags,
        )));
    }
}
