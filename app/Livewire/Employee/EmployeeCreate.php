<?php

namespace App\Livewire\Employee;

use App\Livewire\Concerns\AutosavesEmployeeDraft;
use App\Livewire\Concerns\RemembersReturnTo;
use App\Livewire\Concerns\WithFeedback;
use App\Livewire\Forms\Employee\DraftContractForm;
use App\Livewire\Forms\Employee\PersonalInfoForm;
use App\Models\City;
use App\Models\Employee;
use App\Models\Position;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Component;
use Livewire\WithFileUploads;

class EmployeeCreate extends Component
{
    use AutosavesEmployeeDraft;
    use RemembersReturnTo;
    use WithFeedback, WithFileUploads;

    public PersonalInfoForm $infoForm;

    public DraftContractForm $draftForm;

    public $activeTab = 'information';

    public $title = '';

    /**
     * Once personal info is saved, the tab shows the optional draft-position
     * step (see resources/views/livewire/employee/tabs/personal-info-tab.blade.php)
     * instead of redirecting away immediately, so this employee's city is
     * available to scope the position dropdown before the page navigates on.
     */
    public bool $showDraftPrompt = false;

    /**
     * City the draft-position cascade is scoped to. Defaults to the employee's
     * own city; a nationwide HR role (HR-SYR, super-admin, general-manager —
     * see User::activeCityIds()) may change it, since their reach was never
     * limited to the employee's own city elsewhere in the app either.
     */
    public ?int $draftCityId = null;

    /** Narrows the draft-position dropdown within draftCityId. */
    public ?int $draftProgramId = null;

    /**
     * One per opened form (B5, B2 hand-off). A save retried after its response
     * was lost carries the same token and continues on the record the first
     * one created, instead of creating a second person. Locked: the browser
     * can neither change nor clear it.
     */
    #[Locked]
    public string $submissionToken = '';

    public function mount()
    {
        $this->title = __('general.add-new');
        $this->submissionToken = (string) Str::uuid();
    }

    public function setActiveTab($tab)
    {
        $this->activeTab = $tab;
    }

    public function toggleBoardMember($checked): void
    {
        $this->infoForm->is_board_member = (bool) $checked;
        if (! $this->infoForm->is_board_member) {
            $this->infoForm->board_member_started_at = null;
            $this->infoForm->board_member_end_at = null;
        }
    }

    /** Save the complete record: "Ready, awaiting contract" (B5-D state C). */
    public function saveInformation()
    {
        $this->saveRecord(complete: true);
    }

    /** Save what is there as a draft (B5-D state D): listed as Incomplete, nothing activated. */
    public function saveDraftRecord()
    {
        $this->saveRecord(complete: false);
    }

    /**
     * Autosave (phase 2, DEC-04, employee-lifecycle.md §5.6). The first one
     * that passes the draft minimum creates the draft through the same path as
     * "Save draft" — same lock, same submission token — so every later one
     * updates that row and never creates a second. Unlike "Save draft" it
     * stays on the page: no draft-position step, no redirect, no toast, and
     * nothing re-rendered under the person's cursor. The page offers a link to
     * the saved draft instead (resources/js/employee-autosave.js).
     *
     * @return array{status: string, time?: string, message?: string, url?: string}
     */
    #[Renderless]
    public function autosaveDraft(): array
    {
        abort_unless(auth()->user()?->hasActivePermission('employees.create'), 403);

        // Saved explicitly already: the form has made way for the draft-position step.
        if ($this->showDraftPrompt) {
            return ['status' => 'complete'];
        }

        // Never waits: an explicit save holding the lock is saving the same form.
        $result = Cache::lock($this->submissionKey().':lock', 30)
            ->get(fn () => $this->autosaveOnce($this->submissionKey()));

        return $result === false ? ['status' => 'busy'] : $result;
    }

    /** Whether the acting user may point the draft-position cascade at a city other than the employee's own. */
    public function canPickAnyDraftCity(): bool
    {
        return auth()->user()?->activeCityIds() === null;
    }

    /**
     * Cities the draft-position city dropdown may offer for a nationwide HR
     * role. Unlike $cities in render() (the employee's own "current
     * residence", which excludes the headquarters row), this includes Syria
     * itself, since city-level positions can belong to it directly.
     */
    public function citiesForDraft(): array
    {
        return City::where('is_active', true)->pluck(transColumn('name'), 'id')->toArray();
    }

    /**
     * Programs the draft-position program dropdown may offer: only those
     * with at least one visible position in draftCityId — every position
     * requires a program, so this never hides a reachable position behind
     * an absent program option.
     */
    public function programOptionsForDraft(): array
    {
        if (! $this->draftCityId) {
            return [];
        }

        return Position::query()
            ->inCity($this->draftCityId)
            ->visible()
            ->with('program:id,name')
            ->get(['id', 'program_id'])
            ->pluck('program')
            ->unique('id')
            ->pluck('name', 'id')
            ->sort()
            ->all();
    }

    /**
     * Positions the draft-position dropdown may offer, scoped to draftCityId
     * AND the chosen program — empty until a program is picked, matching the
     * cascade in the view.
     *
     * Position::inCity() treats a center's city as authoritative over the
     * position's own city_id (see ResolvesContractLocationOptions), so a
     * headquarters-level position (city_id = Syria) whose center happens to
     * sit in this city correctly shows up here — but nothing on screen would
     * otherwise say so. A "(Syria)" suffix flags it for awareness without
     * changing which positions are offered.
     */
    public function positionOptionsForDraft(): array
    {
        if (! $this->draftCityId || ! $this->draftProgramId) {
            return [];
        }

        $positions = Position::query()
            ->inCity($this->draftCityId)
            ->where('program_id', $this->draftProgramId)
            ->visible()
            ->orderBy(transColumn('title'))
            ->get(['id', 'city_id', 'title_en', 'title_ar']);

        $nationalLabel = City::query()->where('slug', City::NATIONAL_SLUG)->value(transColumn('name'));

        return $positions
            ->mapWithKeys(fn (Position $position): array => [
                $position->id => City::isNational($position->city_id)
                    ? "{$position->title} ({$nationalLabel})"
                    : $position->title,
            ])
            ->all();
    }

    public function updatedDraftCityId(): void
    {
        // A program/position picked under the previous city cannot survive
        // the switch — both dropdowns that offered them are about to change.
        $this->draftProgramId = null;
        $this->draftForm->position_id = null;
    }

    public function updatedDraftProgramId(): void
    {
        // A stale position choice from a previous program must not survive
        // the switch — the dropdown that offered it is about to disappear.
        $this->draftForm->position_id = null;
    }

    public function createDraftAndContinue()
    {
        $this->draftForm->createDraft($this->infoForm->employee, $this->draftCityId, $this->draftProgramId);
        $this->toast();

        return $this->redirect(route('employees.edit', $this->infoForm->employee->id), navigate: true);
    }

    public function skipDraft()
    {
        return $this->redirect(route('employees.edit', $this->infoForm->employee->id), navigate: true);
    }

    public function render()
    {
        $cities = City::where('is_active', true)->where('code', '!=', '000')->pluck(transColumn('name'), 'id')->toArray();

        return view('livewire.employee.form', [
            'cities' => $cities,
            'isCreateMode' => true,
            'employeeChecklistItems' => collect(),
            'canManageBadges' => false,
            // No employee yet, so the tab is disabled. Same rule as
            // EmployeeProfile's $canViewRecords.
            'canViewContractHistory' => ! auth()->user()->activeRoleIs('employee'),
            'canViewContractPay' => false,
            'contractHistory' => collect(),
        ]);
    }

    /** Where the record this form created is remembered, per user and opened form. */
    private function submissionKey(): string
    {
        return 'employee-create-submission:'.auth()->id().':'.sha1($this->submissionToken);
    }

    private function autosaveOnce(string $key): array
    {
        // An earlier save of this form, explicit or automatic, created the record: continue on it.
        if (! $this->infoForm->employee && ($id = Cache::get($key))) {
            $this->infoForm->employee = Employee::query()->find($id);
        }

        $employee = $this->infoForm->employee;

        if ($employee) {
            Gate::authorize('update', $employee);

            if (! $employee->isDraft()) {
                return ['status' => 'complete'];
            }
        } elseif (! auth()->user()->canAccessCity((int) $this->infoForm->city_id)) {
            // The governorate is part of the draft minimum: out of scope (or
            // not picked yet) is "not saved yet", not a 403 while typing.
            // PersonalInfoForm::authorizeScope() still re-checks below.
            return ['status' => 'incomplete'];
        }

        $result = $this->autosaveSilently(fn () => $this->infoForm->saveDraft(withPhoto: false));

        if ($result['status'] === 'saved') {
            Cache::put($key, $this->infoForm->employee->id, now()->addDay());
            $result['url'] = route('employees.edit', $this->infoForm->employee->id);
        }

        return $result;
    }

    private function saveRecord(bool $complete): void
    {
        $key = $this->submissionKey();

        try {
            Cache::lock($key.':lock', 30)->block(10, fn () => $this->saveRecordOnce($complete, $key));
        } catch (LockTimeoutException) {
            $this->toast(__('employees.onboarding.save-in-progress'), type: 'info');
        }
    }

    private function saveRecordOnce(bool $complete, string $key): void
    {
        // An earlier send of this form already created the record: continue on it.
        if (! $this->infoForm->employee && ($id = Cache::get($key))) {
            $this->infoForm->employee = Employee::query()->find($id);
        }

        try {
            $complete ? $this->infoForm->saveInformation() : $this->infoForm->saveDraft();
        } catch (ValidationException $e) {
            // PersonalInfoForm validates fields split across two tabs
            // (Profile: names, gender, national_number, city... / Contact:
            // username, email, work_phone, personal_phone), but only one tab
            // is ever rendered at a time. An error on a field that lives on
            // the tab NOT currently showing was invisible — the request
            // still "failed" from the user's point of view, with no visible
            // reason why. Jump to whichever tab actually holds the error.
            $this->activeTab = $this->tabForErrors($e->errors());

            throw $e;
        }

        Cache::put($key, $this->infoForm->employee->id, now()->addDay());

        // What is on screen is now saved: leaving no longer asks (B2).
        $this->dispatch('form-saved', form: 'infoForm');

        $this->toast($complete ? __('employees.onboarding.saved-complete') : __('employees.onboarding.saved-draft'));
        $this->showDraftPrompt = true;
        $this->draftCityId = $this->infoForm->employee->city_id;

        // saveInformation() is reachable from more than one tab's own Save
        // button (Profile and Contact share the same Form object and both
        // call this method) but the draft-position prompt only lives in the
        // Profile tab's view — force the user back there so it is always
        // reachable regardless of which tab they saved from.
        $this->activeTab = 'information';
    }

    /**
     * Which tab to show so a validation failure is visible: stays on the
     * current tab if it already accounts for at least one of the failing
     * fields, otherwise jumps to the other one.
     */
    private function tabForErrors(array $errors): string
    {
        $contactFields = PersonalInfoForm::CONTACT_FIELDS;

        $failingFields = collect(array_keys($errors))
            ->map(fn (string $key): string => str($key)->after('infoForm.')->toString());

        $hasContactError = $failingFields->intersect($contactFields)->isNotEmpty();
        $hasProfileError = $failingFields->diff($contactFields)->isNotEmpty();

        if ($this->activeTab === 'contact' && $hasContactError) {
            return 'contact';
        }

        if ($this->activeTab !== 'contact' && $hasProfileError) {
            return $this->activeTab;
        }

        return $hasContactError ? 'contact' : 'information';
    }
}
