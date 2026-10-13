<?php

namespace App\Livewire\Employee;

use App\Livewire\Forms\Employee\DraftContractForm;
use App\Livewire\Forms\Employee\PersonalInfoForm;
use App\Models\City;
use App\Models\Position;
use App\Traits\WithAlerts;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithFileUploads;

class EmployeeCreate extends Component
{
    use WithAlerts, WithFileUploads;

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

    public function mount()
    {
        $this->title = __('general.add-new');
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

    public function saveInformation()
    {
        try {
            $this->infoForm->saveInformation();
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

        $this->successToast();
        $this->showDraftPrompt = true;
        $this->draftCityId = $this->infoForm->employee->city_id;

        // saveInformation() is reachable from more than one tab's own Save
        // button (Profile and Contact share the same Form object and both
        // call this method) but the draft-position prompt only lives in the
        // Profile tab's view — force the user back there so it is always
        // reachable regardless of which tab they saved from.
        $this->activeTab = 'information';
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
        $this->successToast();

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
        ]);
    }

    /**
     * Which tab to show so a validation failure is visible: stays on the
     * current tab if it already accounts for at least one of the failing
     * fields, otherwise jumps to the other one.
     */
    private function tabForErrors(array $errors): string
    {
        $contactFields = ['username', 'email', 'work_phone', 'personal_phone'];

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
