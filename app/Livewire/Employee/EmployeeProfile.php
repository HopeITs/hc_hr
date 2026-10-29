<?php

namespace App\Livewire\Employee;

use App\Livewire\Concerns\GuardsModalClose;
use App\Livewire\Concerns\WithFeedback;
use App\Models\Badge;
use App\Models\Employee;
use App\Models\ProfileChangeRequest;
use App\Models\User;
use App\Services\EmployeeTimeline;
use App\Services\ProfileChangeRequests;
use App\Services\TaskTelemetry;
use App\Support\DirectoryAccess;
use App\Support\NavigationMemory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
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
 * (section 5): personal contact and skills by HR in scope or by the employee
 * themself (Q5), work contact by HR in scope. A save writes only that
 * section's columns, and is refused as stale when the section changed since
 * the dialog opened. The list sections (education, courses, experience,
 * family) are still edited on the edit page; the employee's own changes to
 * them wait for the HR approval flow (section 5.1), not built yet.
 */
class EmployeeProfile extends Component
{
    use GuardsModalClose, WithFeedback, WithFileUploads;

    /** The sections edited in place, and their columns. */
    public const SECTIONS = [
        'personal-contact' => ['personal_phone', 'personal_email', 'address_ar', 'address_en'],
        'skills' => ['skills', 'interests'],
        'work-contact' => ['work_phone', 'whatsapp_work', 'telegram_work'],
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

    /** The owner's proposal waiting for HR (section 5.1): which section, and its entries. */
    public bool $proposalModal = false;

    public string $proposing = '';

    /** @var array<string, mixed> */
    public array $proposal = [];

    /** A certificate, or the new photo. */
    public $proposalFile = null;

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

        foreach (['personal_phone', 'work_phone'] as $phone) {
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
            $wrote = $current->isDirty();
            $current->save();
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

    // -- Proposals waiting for HR (D1, profile-contract.md section 5.1) -----

    public function openProposal(string $section): void
    {
        abort_unless($this->canPropose() && in_array($section, ProfileChangeRequest::SECTIONS, true), 404);

        $this->resetValidation();
        $this->proposing = $section;
        $this->proposal = array_fill_keys(array_keys(app(ProfileChangeRequests::class)->rules($section)), null);
        $this->proposalFile = null;
        $this->markClean('proposalModal');
        $this->proposalModal = true;
    }

    public function submitProposal(): void
    {
        abort_unless($this->canPropose() && in_array($this->proposing, ProfileChangeRequest::SECTIONS, true), 404);

        $service = app(ProfileChangeRequests::class);
        $hadOpen = ProfileChangeRequest::query()->pending()
            ->where('employee_id', $this->employee->id)->where('section', $this->proposing)->exists();

        try {
            $service->submit(Auth::user(), $this->proposing, $this->proposal, $this->proposalFile);
        } catch (AuthorizationException) {
            abort(404);
        } catch (ValidationException $e) {
            // On the dialog's own fields; the entries stay as typed.
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field === 'file' ? 'proposalFile' : 'proposal.'.$field, $messages[0]);
            }

            return;
        }

        $this->closeGuardedDialog('proposalModal');
        unset($this->closeBaselines['proposalModal']);
        $this->toast(__($hadOpen ? 'employees.profile.change.replaced' : 'employees.profile.change.sent'));
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
            array_push($contractColumns, 'contract_type_id', 'termination_reason_id', 'weekly_hours');
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
            array_push($contractRelations, 'contractType', 'terminationReason');
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
        $currentPosition = $currentContract?->position;

        return view('livewire.employee.employee-profile', [
            'employee' => $employee,
            'badges' => $badges,
            // Career history is R; a Directory-only viewer gets no timeline at all.
            'timeline' => $this->canViewRecords
                ? app(EmployeeTimeline::class)->events($contracts, $badges, $this->canViewFinancial)
                : [],
            'editable' => array_values(array_filter(array_keys(self::SECTIONS), fn (string $s) => $this->canEditSection($s))),
            // Section 5.1: the owner sees their own waiting proposals and the last refusal; nobody else does.
            'canPropose' => $this->canPropose(),
            'proposals' => $this->isSelf ? $this->ownProposals() : [],
            'contracts' => $contracts,
            'currentContract' => $currentContract,
            'currentPosition' => $currentPosition,
            'isOnLeave' => $employee->is_active && $employee->isOnLeaveToday(),
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
        return ['sectionModal', 'proposalModal'];
    }

    protected function guardedEntries(string $dialog): array
    {
        return $dialog === 'proposalModal'
            ? [...$this->proposal, 'file' => $this->proposalFile ? 'chosen' : null]
            : $this->sectionFields;
    }

    protected function closeGuardedDialog(string $dialog): void
    {
        if ($dialog === 'proposalModal') {
            $this->proposalModal = false;
            $this->proposing = '';
            $this->proposal = [];
            $this->proposalFile = null;
            $this->resetValidation();

            return;
        }

        $this->sectionModal = false;
        $this->editing = '';
        $this->sectionFields = [];
        $this->sectionVersion = '';
        $this->resetValidation();
    }

    /** Only the active owner proposes, and only for themself. */
    private function canPropose(): bool
    {
        return $this->isSelf && app(ProfileChangeRequests::class)->canPropose(Auth::user());
    }

    /** Who may edit $section of this profile (Q5: the owner edits their contact and skills directly). */
    private function canEditSection(string $section): bool
    {
        if (! array_key_exists($section, self::SECTIONS)) {
            return false;
        }

        $user = Auth::user();
        $hr = $user !== null && Gate::forUser($user)->allows('update', $this->employee);
        $self = $this->isSelf && $this->employee->is_active;

        return match ($section) {
            'personal-contact', 'skills' => $hr || $self,
            'work-contact' => $hr,
        };
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

        $this->canViewPrivate = $this->isSelf || $isHrInScope;

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
