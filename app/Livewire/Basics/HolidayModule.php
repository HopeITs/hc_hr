<?php

namespace App\Livewire\Basics;

use App\Livewire\Concerns\GuardsModalClose;
use App\Livewire\Concerns\WithFeedback;
use App\Models\City;
use App\Models\Holiday;
use App\Services\HolidayProjects;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Official holidays, one-off grants and the birthday holiday
 * (team-coverage-contract §6.1).
 *
 * National HR (HR-SYR, super-admin) sets anything. A program manager sets
 * project holidays and grants for their own projects only, never national
 * and never the birthday holiday (H-6). Every check goes through
 * HolidayProjects, which also builds the project picker.
 */
class HolidayModule extends Component
{
    use GuardsModalClose;
    use WithFeedback;

    public bool $showModal = false;

    public string $modalTitle = '';

    public array $item = [];

    public ?int $governorate = null;

    /** The team calendar embeds the dialog alone (no title, table or breadcrumbs). */
    public bool $embedded = false;

    protected $listeners = ['openHolidayEdit' => 'edit', 'openHolidayCreate' => 'createOn'];

    public function mount(bool $embedded = false): void
    {
        $this->authorizeManager();
        $this->embedded = $embedded;
        $this->item = $this->blank();
    }

    public function render(HolidayProjects $projects)
    {
        $mine = $projects->managedBy(auth()->user());

        return view($this->embedded ? 'livewire.basics.holiday-dialog' : 'livewire.basics.holiday-module', [
            'isNationalHr' => $projects->isNationalHr(auth()->user()),
            'projectOptions' => $mine->map(fn ($p) => $p['label'])->all(),
            'governorates' => City::query()
                ->whereIn('id', $mine->pluck('city_id')->unique()->all() ?: [0])
                ->get()
                ->mapWithKeys(fn (City $c) => [$c->id => $c->name])
                ->all(),
        ]);
    }

    public function create(): void
    {
        $this->authorizeManager();
        $this->item = $this->blank();
        $this->governorate = null;
        $this->resetValidation();
        $this->modalTitle = __('general.add-new');
        $this->markClean();
        $this->showModal = true;
    }

    /** From a day of the team calendar: a new holiday starting and ending that day. */
    public function createOn(string $date): void
    {
        $this->create();

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
            $this->item['start_date'] = $date;
            $this->item['end_date'] = $date;
            $this->markClean();
        }
    }

    public function edit(int $id): void
    {
        $this->authorizeManager();
        $model = Holiday::with('projects')->findOrFail($id);
        abort_unless(app(HolidayProjects::class)->canChange(auth()->user(), $model), 403);

        $this->item = [
            'id' => $model->id,
            'name_en' => $model->name_en,
            'name_ar' => $model->name_ar,
            'scope' => $model->scope,
            'kind' => $model->kind,
            'projects' => $model->projects->map(fn ($l) => $l->program_id.':'.$l->city_id)->values()->all(),
            'start_date' => $model->start_date?->toDateString(),
            'end_date' => $model->end_date?->toDateString(),
            'is_recurring' => (bool) $model->is_recurring,
        ];

        $this->governorate = null;
        $this->modalTitle = __('general.edit');
        $this->resetValidation();
        $this->markClean();
        $this->showModal = true;
    }

    /**
     * H-2 shortcut: tick every project of one governorate. It only selects
     * per-project links; there is no governorate-wide holiday.
     */
    public function selectGovernorate(): void
    {
        $this->authorizeManager();

        $keys = app(HolidayProjects::class)->managedBy(auth()->user())
            ->filter(fn ($p) => $p['city_id'] === (int) $this->governorate)
            ->keys()
            ->all();

        $this->item['projects'] = array_values(array_unique([...($this->item['projects'] ?? []), ...$keys]));
    }

    public function save(): void
    {
        $this->authorizeManager();
        $projects = app(HolidayProjects::class);
        $user = auth()->user();

        $model = ! empty($this->item['id']) ? Holiday::with('projects')->findOrFail($this->item['id']) : new Holiday;
        abort_if($model->exists && ! $projects->canChange($user, $model), 403);

        $this->validate();

        // A grant goes to projects: it is what a program manager gives one project (§6.1).
        if ($this->item['kind'] === Holiday::KIND_GRANT && $this->item['scope'] !== Holiday::SCOPE_PROJECTS) {
            $this->addError('item.scope', __('holidays.grant-needs-projects'));

            return;
        }

        $isProjects = $this->item['scope'] === Holiday::SCOPE_PROJECTS;
        $kind = $this->item['kind'];

        DB::transaction(function () use ($model, $isProjects, $kind, $user) {
            $model->fill([
                'name_en' => $this->item['name_en'],
                'name_ar' => $this->item['name_ar'],
                'scope' => $this->item['scope'],
                'kind' => $kind,
                'start_date' => $this->item['start_date'],
                'end_date' => $this->item['end_date'],
                // A one-off grant never repeats.
                'is_recurring' => $kind !== Holiday::KIND_GRANT && (bool) ($this->item['is_recurring'] ?? false),
            ]);
            $model->created_by ??= $user->id;
            $model->save();

            $model->projects()->delete();

            if ($isProjects) {
                foreach (array_unique($this->item['projects']) as $key) {
                    [$programId, $cityId] = array_map('intval', explode(':', $key));
                    $model->projects()->create(['program_id' => $programId, 'city_id' => $cityId]);
                }
            }
        });

        $this->showModal = false;

        $this->dispatch('refreshHolidayTable');
        $this->dispatch('holidaysChanged');

        $this->toast();
    }

    protected function rules(): array
    {
        $projects = app(HolidayProjects::class);
        $user = auth()->user();
        $national = $projects->isNationalHr($user);

        return [
            'item.name_en' => 'required|string|max:255',
            'item.name_ar' => 'required|string|max:255',
            'item.scope' => ['required', Rule::in($national ? [Holiday::SCOPE_NATIONAL, Holiday::SCOPE_PROJECTS] : [Holiday::SCOPE_PROJECTS])],
            'item.kind' => ['required', Rule::in($national
                ? [Holiday::KIND_OFFICIAL, Holiday::KIND_GRANT, Holiday::KIND_BIRTHDAY]
                : [Holiday::KIND_OFFICIAL, Holiday::KIND_GRANT])],
            'item.projects' => ['array', Rule::requiredIf(fn () => ($this->item['scope'] ?? null) === Holiday::SCOPE_PROJECTS)],
            'item.projects.*' => ['string', Rule::in($projects->managedBy($user)->keys()->all())],
            'item.start_date' => 'required|date',
            'item.end_date' => 'required|date|after_or_equal:item.start_date',
            'item.is_recurring' => 'boolean',
        ];
    }

    protected function messages(): array
    {
        return [
            'item.projects.required' => __('holidays.projects-required'),
            'item.projects.*.in' => __('holidays.invalid-project'),
            'item.scope.in' => __('holidays.invalid-scope'),
            'item.kind.in' => __('holidays.invalid-kind'),
        ];
    }

    private function blank(): array
    {
        $national = app(HolidayProjects::class)->isNationalHr(auth()->user());

        return [
            'id' => null,
            'name_en' => null,
            'name_ar' => null,
            'scope' => $national ? Holiday::SCOPE_NATIONAL : Holiday::SCOPE_PROJECTS,
            'kind' => Holiday::KIND_OFFICIAL,
            'projects' => [],
            'start_date' => null,
            'end_date' => null,
            'is_recurring' => false,
        ];
    }

    private function authorizeManager(): void
    {
        abort_unless(app(HolidayProjects::class)->canManage(auth()->user()), 403);
    }
}
