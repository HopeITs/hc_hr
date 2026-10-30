<?php

namespace App\Livewire\Employee;

use App\Enums\ContractType as ContractTypeEnum;
use App\Livewire\Concerns\AutosavesEmployeeDraft;
use App\Livewire\Concerns\WithFeedback;
use App\Livewire\Forms\Employee\AttachmentForm;
use App\Livewire\Forms\Employee\BadgeForm;
use App\Livewire\Forms\Employee\ChecklistForm;
use App\Livewire\Forms\Employee\CourseForm;
use App\Livewire\Forms\Employee\EducationForm;
use App\Livewire\Forms\Employee\ExperienceForm;
use App\Livewire\Forms\Employee\PersonalInfoForm;
use App\Livewire\Forms\Employee\RelativeForm;
use App\Livewire\Forms\LeaveBalanceForm;
use App\Models\Badge;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\City;
use App\Models\DefaultDeliverable;
use App\Models\Employee;
use App\Services\ChecklistWorkflow;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Component;
use Livewire\WithFileUploads;

class EmployeeEdit extends Component
{
    use AutosavesEmployeeDraft;
    use WithFeedback, WithFileUploads;

    public ?Employee $employee = null;

    #[Locked]
    public bool $canManageBadges = false;

    public PersonalInfoForm $infoForm;

    public EducationForm $educationForm;

    public ChecklistForm $checklistForm;

    public CourseForm $courseForm;

    public ExperienceForm $experienceForm;

    public RelativeForm $relativeForm;

    public BadgeForm $badgeForm;

    public AttachmentForm $attachmentForm;

    public LeaveBalanceForm $balanceForm;

    public $activeTab = 'information';

    public $title = '';

    public $showInitiateReturnModal = false;

    public $selectedChecklistForReturn;

    public $return_receiver_id;

    public function mount(Employee $employee)
    {
        Gate::authorize('update', $employee);

        $this->employee = $employee;
        $this->title = $employee->name;
        $this->canManageBadges = Gate::allows('create', [Badge::class, $employee]);

        $this->infoForm->setEmployee($employee);
        $this->educationForm->setEmployee($employee);
        $this->courseForm->setEmployee($employee);
        $this->experienceForm->setEmployee($employee);
        $this->relativeForm->setEmployee($employee);
        if ($this->canManageBadges) {
            $this->badgeForm->setEmployee($employee);
        }
        $this->attachmentForm->setEmployee($employee);

        $hasExistingChecklist = Checklist::where('employee_id', $employee->id)->exists();
        $defaultDeliverables = $hasExistingChecklist ? collect() : DefaultDeliverable::all();

        $this->checklistForm->setEmployeeAndAllItems($employee, $this->getChecklistItems(), $defaultDeliverables);
    }

    public function saveChecklist()
    {
        $this->persist('checklistForm', function () {
            $this->checklistForm->save();

            $this->checklistForm->setEmployeeAndAllItems(
                $this->employee->fresh(),
                $this->getChecklistItems(),
                DefaultDeliverable::all(),
            );
        });
    }

    public function setActiveTab($tab)
    {
        $this->activeTab = $tab;
    }

    public function updated(string $property, mixed $value = null): void
    {
        if (preg_match('/^badgeForm\.badges\.(\d+)\.type$/', $property, $matches)) {
            $this->badgeForm->resetSubtypeDetails((int) $matches[1]);
        }

        if (preg_match('/^badgeForm\.badges\.(\d+)\.subtype$/', $property, $matches)) {
            $this->badgeForm->resetConditionalDetails((int) $matches[1]);
        }
    }

    /**
     * Save a draft and keep it a draft (B5). Only while the record is still
     * incomplete: a complete record never goes back to draft.
     */
    public function saveDraftRecord()
    {
        abort_unless($this->employee->isDraft(), 403);

        $this->persist('infoForm', fn () => $this->infoForm->saveDraft());
        $this->employee->refresh();
    }

    /**
     * Autosave of a draft (phase 2, DEC-04, employee-lifecycle.md §5.6):
     * re-authorized like saveDraftRecord, always the same row, silent while
     * the draft minimum does not validate. A record that is no longer a draft
     * (completed here or in another tab) is left untouched: autosave never
     * turns it back into one, and never completes or activates anything.
     *
     * @return array{status: string, time?: string, message?: string}
     */
    #[Renderless]
    public function autosaveDraft(): array
    {
        Gate::authorize('update', $this->employee);

        if (! $this->employee->isDraft()) {
            return ['status' => 'complete'];
        }

        return $this->autosaveSilently(fn () => $this->infoForm->saveDraft(withPhoto: false));
    }

    /** Ask first: discarding a draft deletes it (B5D-Q7). */
    public function confirmDiscardDraftRecord(): void
    {
        $this->assertDiscardable();

        $this->confirm(
            'discardDraftRecord',
            title: __('employees.onboarding.discard-title'),
            message: __('employees.onboarding.discard-message'),
            confirmLabel: __('employees.onboarding.discard'),
        );
    }

    /**
     * Delete an incomplete record and its position reservation, if any
     * (B5D-Q6, Q7). Only a draft with no real contract and no account; the
     * activity log keeps the event, never the national ID.
     */
    public function discardDraftRecord()
    {
        $employee = $this->assertDiscardable();

        DB::transaction(function () use ($employee): void {
            $employee->allContracts()->where('type', ContractTypeEnum::Draft)->get()->each->delete();
            $employee->delete();
        });

        $this->toast(__('employees.onboarding.discarded'));

        return $this->redirectRoute('employees.index', ['view' => 'incomplete'], navigate: true);
    }

    public function saveInformation()
    {
        // Saving a draft here completes it (B5): say so instead of the generic message.
        $completing = $this->employee->isDraft() ? __('employees.onboarding.completed') : null;

        $this->persist('infoForm', function () {
            $this->infoForm->saveInformation();
            $employee = $this->infoForm->employee;
            // joined_at drives seniority, and with it the annual-leave rule.
            if ($employee->wasChanged(['gender', 'marital_status', 'joined_at'])) {
                if ($employee->contracts()->exists()) {
                    $contract = $employee->contracts()->whereHas('leaveBalances')->first();
                    if (isset($contract)) {
                        if ($contract->leaveBalances()->exists()) {
                            $this->balanceForm->update($contract->id);
                        } else {
                            $this->balanceForm->store($contract->id);
                        }
                    }
                }
            }

            // Stay on the current tab: editing is a multi-tab session, and
            // bouncing back to the employees table after every save threw away
            // the user's place in it. `back_to_employees` is the only way out.
            $this->title = $employee->name;
        }, $completing);

        $this->employee->refresh();
    }

    public function saveEducation()
    {
        $this->persist('educationForm', fn () => $this->educationForm->save());
    }

    public function saveCourses()
    {
        $this->persist('courseForm', fn () => $this->courseForm->save());
    }

    public function saveExperience()
    {
        $this->persist('experienceForm', fn () => $this->experienceForm->save());
    }

    public function saveRelatives()
    {
        $this->persist('relativeForm', fn () => $this->relativeForm->save());
    }

    public function saveBadges()
    {
        Gate::authorize('create', [Badge::class, $this->employee]);

        $this->persist('badgeForm', fn () => $this->badgeForm->save());
    }

    public function saveAttachments()
    {
        $this->persist('attachmentForm', fn () => $this->attachmentForm->save());
    }

    public function addAttachment()
    {
        $this->attachmentForm->addAttachment();
    }

    public function removeAttachment($index, $id = null)
    {
        $this->attachmentForm->removeAttachment($index, $id);
        $this->resetErrorBag();
        $this->toastIfSaved($id);
    }

    public function addEducation()
    {
        $this->educationForm->addEducation();
    }

    public function removeEducation(int $index, ?int $id = null)
    {
        $this->educationForm->removeEducation($index, $id);
        $this->resetErrorBag();
        $this->toastIfSaved($id);
    }

    public function addCourse()
    {
        $this->courseForm->addCourse();
    }

    public function removeCourse(int $index, ?int $id = null)
    {
        $this->courseForm->remove($index, $id);
        $this->resetErrorBag();
        $this->toastIfSaved($id);
    }

    public function addExperience()
    {
        $this->experienceForm->addExperience();
    }

    public function removeExperience(int $index, ?int $id = null)
    {
        $this->experienceForm->removeExperience($index, $id);
        $this->resetErrorBag();
        $this->toastIfSaved($id);
    }

    public function addRelative()
    {
        $this->relativeForm->addRelative();
    }

    public function removeRelative(int $index, ?int $id = null)
    {
        $this->relativeForm->removeRelative($index, $id);
        $this->resetErrorBag();
        $this->toastIfSaved($id);
    }

    public function addBadge()
    {
        Gate::authorize('create', [Badge::class, $this->employee]);
        $this->badgeForm->addBadge();
    }

    public function removeBadge(int $index)
    {
        Gate::authorize('create', [Badge::class, $this->employee]);
        $id = $this->badgeForm->badges[$index]['id'] ?? null;
        $this->badgeForm->removeBadge($index);
        $this->resetErrorBag();
        $this->toastIfSaved($id);
    }

    public function openInitiateReturnModal(int $checklistId)
    {
        Gate::authorize('update', $this->employee);

        // Scoped to the employee being edited. Previously any checklist id at
        // all could be loaded into the modal — including one belonging to
        // another city's employee — and then written to by initiateReturn().
        $this->selectedChecklistForReturn = Checklist::query()
            ->whereKey($checklistId)
            ->where('employee_id', $this->employee->id)
            ->firstOrFail();

        $this->return_receiver_id = null;
        $this->showInitiateReturnModal = true;
    }

    public function initiateReturn()
    {
        Gate::authorize('update', $this->employee);

        $this->validate(['return_receiver_id' => 'required|exists:employees,id']);

        if ($this->selectedChecklistForReturn) {
            app(ChecklistWorkflow::class)->initiateReturn(
                checklistId: (int) $this->selectedChecklistForReturn->id,
                employee: $this->employee,
                receiverId: (int) $this->return_receiver_id,
            );

            $this->toast(__('general.return-process-initiated'));
            $this->showInitiateReturnModal = false;
            $this->selectedChecklistForReturn = null;
        }

        $this->checklistForm->setEmployeeAndAllItems(
            $this->employee->fresh(),
            $this->getChecklistItems(),
            DefaultDeliverable::all(),
        );
    }

    public function finalizeReturnCycle(int $checklistId)
    {
        Gate::authorize('update', $this->employee);

        app(ChecklistWorkflow::class)->finalizeReturn($checklistId, $this->employee);

        $this->toast(__('general.checklist-fully-reset'));

        $this->checklistForm->setEmployeeAndAllItems(
            $this->employee->fresh(),
            $this->getChecklistItems(),
            DefaultDeliverable::all(),
        );
    }

    public function toggleBoardMember($checked): void
    {
        $this->infoForm->is_board_member = (bool) $checked;
    }

    public function render()
    {
        $delivereds = Employee::whereIn('id', DefaultDeliverable::pluck('employee_id'))->get();
        if ($delivereds->isEmpty()) {
            $delivereds = Employee::whereHas(
                'contracts', function ($q) {
                    $q->whereHas('position.department', function ($q) {
                        $q->whereIn('name', ['IT', 'Communication', 'HR']);
                    });
                },
            )->get();
        }
        $cities = City::where('is_active', true)->pluck(transColumn('name'), 'id')->toArray();

        // Contracts History tab. The permission rules mirror
        // EmployeeProfile ($canViewRecords / $canViewFinancial): the history
        // is the colleague-records tier, EmployeePolicy::viewRecords
        // (access-policy.md §4.3). Loaded only while the tab is open, and
        // handed to the view rather than kept on a public property, so pay
        // never enters the Livewire snapshot.
        $user = auth()->user();
        $canViewContractHistory = Gate::forUser($user)->allows('viewRecords', $this->employee);
        $canViewContractPay = $user->activeRoleIs('HR', 'super-admin')
            && $user->canAccessCity($this->employee->city_id)
            && $user->canViewSalary();
        $contractHistory = $canViewContractHistory && $this->activeTab === 'contracts_history'
            ? $this->contractHistory($canViewContractPay)
            : collect();

        $employeeChecklistItems = Checklist::with(['item'])
            ->where('employee_id', $this->employee->id)
            ->where(function ($q) {
                $q->whereNull('delivered_status')
                    ->orWhereIn('delivered_status', ['delivered', 'pending'])
                    ->orWhere('returned_status', 'received');
            })
            ->orderByDesc('updated_at')
            ->get();

        return view('livewire.employee.form', [
            'cities' => $cities,
            'delivereds' => $delivereds,
            'employeeChecklistItems' => $employeeChecklistItems,
            'isCreateMode' => false,
            'canViewContractHistory' => $canViewContractHistory,
            'canViewContractPay' => $canViewContractPay,
            'contractHistory' => $contractHistory,
        ]);
    }

    /**
     * A saved row is deleted from the database on the spot, so it gets the
     * same toast as any other delete; a row only added on screen does not.
     */
    private function toastIfSaved(mixed $id): void
    {
        if (filled($id)) {
            $this->toast();
        }
    }

    /** The draft being discarded, re-read and re-authorized; 403 for anything else. */
    private function assertDiscardable(): Employee
    {
        $employee = $this->employee->fresh();

        abort_unless($employee && $employee->isDraft(), 403);
        Gate::authorize('update', $employee);
        abort_if($employee->realContracts()->exists() || $employee->account()->exists(), 403);

        return $employee;
    }

    /**
     * Run a tab's save, then report what actually happened.
     *
     * Eloquent fires created/updated/deleted only when a row is really
     * written — saving an untouched model fires none of them — so watching
     * those events answers "did this change anything?" accurately, whatever
     * shape the form behind the tab happens to have. Without it every save
     * claimed success, including saves of a form nobody had touched.
     */
    private function persist(string $form, callable $save, ?string $successMessage = null): void
    {
        $wrote = false;

        // Not paired with an Event::forget(): that drops every wildcard
        // listener for these events, not just this one. Livewire runs a
        // single action per request, so the listener dies with the request.
        Event::listen(
            ['eloquent.created: *', 'eloquent.updated: *', 'eloquent.deleted: *'],
            function () use (&$wrote) {
                $wrote = true;
            },
        );

        $save();

        // Re-arms the save button's dirty gate against the freshly saved state.
        $this->dispatch('form-saved', form: $form);

        $wrote ? $this->toast($successMessage) : $this->toast(__('general.no-changes-message'), type: 'info');
    }

    /**
     * Same columns and order as the career history in
     * EmployeeProfile::render(), with only the relations the card reads;
     * keep the two in sync. Pay columns are only selected when the viewer
     * may see them.
     */
    private function contractHistory(bool $withPay): Collection
    {
        $columns = [
            'id', 'employee_id', 'position_id', 'contract_type_id',
            'termination_reason_id', 'start_date', 'end_date', 'terminated_at',
            'weekly_hours', 'salary_per', 'is_active',
        ];

        if ($withPay) {
            array_push($columns, 'salary', 'currency');
        }

        return $this->employee->allContracts()
            ->select($columns)
            ->with(['contractType', 'position.department'])
            ->latest('start_date')
            ->get();
    }

    private function getChecklistItems(): Collection
    {
        $query = ChecklistItem::query();

        if ($this->employee->created_at->greaterThan(Carbon::now()->subMonth())) {
            return $query->where('order', 1)->get();
        }

        return $query->whereNot('order', 1)->orderBy('order')->get();
    }
}
