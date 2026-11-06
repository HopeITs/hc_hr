<?php

namespace App\Livewire\Contract;

use App\Enums\ContractAmendmentStatus;
use App\Enums\ContractType as ContractTypeEnum;
use App\Enums\Currency;
use App\Enums\PayMode;
use App\Enums\SalaryPer;
use App\Enums\ScheduleMode;
use App\Livewire\Concerns\WithFeedback;
use App\Livewire\Contract\Concerns\DrivesContractComposer;
use App\Livewire\Forms\ContractForm;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Services\ContractAmendmentDescriber;
use App\Services\ContractAmendmentService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Component;

/**
 * The contract amendment page (ملحق عقد): the composer's schedule and pay
 * sections, opened on a contract in force, plus the date the change takes
 * effect. Saving records a draft amendment — the sheet to print and sign —
 * and changes nothing on the contract yet; approving it does
 * (ContractAmendmentService).
 *
 * Everything else about the contract is fixed here. The form is refilled from
 * the stored contract on every submit and only the schedule, the pay and the
 * full/part-time choice are taken from the browser.
 */
class ContractAmend extends Component
{
    use DrivesContractComposer {
        DrivesContractComposer::sectionOfField as private baseSectionOfField;
    }
    use WithFeedback;

    public ContractForm $form;

    public Contract $contract;

    /** Set when the page edits a draft instead of drafting a new amendment. */
    #[Locked]
    public ?int $draftId = null;

    // Read by composerSeed(); an amendment has no location to choose.
    public ?int $city_id = null;

    public ?int $program_id = null;

    public ?int $center_id = null;

    public function mount(Contract $contract, ?ContractAmendment $amendment = null): void
    {
        $this->contract = $contract->loadMissing(['employee', 'position', 'contractType', 'payTiers']);
        $this->form->setDefaults();

        if ($amendment?->exists) {
            Gate::authorize('decideAmendment', $amendment);

            $this->draftId = $amendment->id;
            // Opened on the terms the draft makes, so only what HR changes now moves.
            $this->form->setContract($amendment->termsAsContract());

            return;
        }

        Gate::authorize('amend', $contract);

        $this->form->setContract($this->contract);
    }

    public function submit(array $payload, ContractAmendmentService $amendments): mixed
    {
        $draft = $this->draft();
        Gate::authorize(...$draft ? ['decideAmendment', $draft] : ['amend', $this->contract]);

        $contract = $this->contract->fresh(['payTiers', 'contractType']);

        $this->form->setDefaults();
        $this->form->setContract($contract);

        if (in_array((int) ($payload['type'] ?? 0), [1, 2], true) && $contract->contractType?->category !== 'service') {
            $this->form->type = (int) $payload['type'];
        }

        $this->applyComposerSchedule($payload);
        $this->applyComposerPay($payload);

        try {
            $this->form->validateTerms();
            $this->form->validatePay();

            $effectiveFrom = $this->dateOrNull($payload['effectiveFrom'] ?? null)
                ?? throw ValidationException::withMessages([
                    'effective_from' => __('validation.required', ['attribute' => __('contracts.amendment.effective-from')]),
                ]);

            $reason = $this->textOrNull($payload['reason'] ?? null);

            if ($reason !== null && mb_strlen($reason) > 2000) {
                throw ValidationException::withMessages(['reason' => __('validation.max.string', ['attribute' => __('contracts.amendment.reason'), 'max' => 2000])]);
            }

            $draft
                ? $amendments->redraft($draft, $this->form->amendmentTerms(), $effectiveFrom, $reason, auth()->user())
                : $amendments->draft($contract, $this->form->amendmentTerms(), $effectiveFrom, $reason, auth()->user());
        } catch (ValidationException $exception) {
            $this->dispatch('composer-invalid', sections: $this->sectionsFor(array_keys($exception->errors())));

            throw $exception;
        }

        $this->toast(__($draft ? 'contracts.amendment.redrafted' : 'contracts.amendment.drafted'));

        return $this->redirectRoute('contracts.show', $this->contract->id, navigate: true);
    }

    /** The amendment page previews no entitlement; the leave note says what happens. */
    #[Renderless]
    public function leavePreview(array $payload): ?float
    {
        return null;
    }

    public function render(): View
    {
        $category = $this->contract->contractType?->category ?? 'annual';

        return view('livewire.contract.contract-amend', [
            'cities' => collect(),
            'lockedCity' => null,
            'types' => collect([(object) [
                'id' => $this->contract->contract_type_id,
                'name' => $this->contract->contractType?->name,
                'category' => $category,
                'startDate' => $this->contract->start_date?->toDateString(),
                'endDate' => $this->contract->end_date?->toDateString(),
            ]]),
            'seed' => [...$this->composerSeed(), ...$this->draftSeed()],
            'editingNumber' => $this->draft()?->number,
            'nowCard' => $this->nowCard(),
            'previous' => $this->previousAmendment(),
            'errorSections' => $this->errorSections(),
            'isService' => $category === 'service',
        ]);
    }

    protected function locationOptionsPermission(): string
    {
        return 'contracts.amend';
    }

    protected function sectionOfField(): array
    {
        return array_merge($this->baseSectionOfField(), [
            'type' => 'amendment',
            'effective_from' => 'amendment',
            'reason' => 'amendment',
            'terms' => 'amendment',
            'contract' => 'amendment',
        ]);
    }

    /** The draft being edited, re-read on every call (draftId alone is never trusted). */
    private function draft(): ?ContractAmendment
    {
        return $this->draftId
            ? ContractAmendment::query()->where('contract_id', $this->contract->id)->findOrFail($this->draftId)
            : null;
    }

    /**
     * The "Now" column of the side card: the contract as it stands, worded on
     * the server. The composer itself may be seeded from a draft, so its own
     * getters cannot speak for the contract.
     *
     * @return array{type: string, workDays: string, hours: string, salary: string}
     */
    private function nowCard(): array
    {
        $contract = $this->contract;
        $locale = app()->getLocale();
        $digits = fn (string $s) => $locale === 'ar' ? ar_digits($s) : $s;
        $dayName = fn (int $d) => Carbon::now()->startOfWeek(Carbon::SUNDAY)->addDays($d)->translatedFormat('l');

        $workDays = (int) $contract->schedule_mode === ScheduleMode::Fixed && $contract->work_day_from !== null && $contract->work_day_to !== null
            ? $dayName((int) $contract->work_day_from).' – '.$dayName((int) $contract->work_day_to)
            : '—';

        $salary = match (true) {
            ! (auth()->user()?->canViewSalary() ?? false) => __('general.salary-hidden'),
            (int) $contract->pay_mode === PayMode::Flexible => trans_choice('contracts.tiers-count', $contract->payTiers->count(), ['count' => $contract->payTiers->count()]),
            blank($contract->salary) => '—',
            default => $digits(number_format((float) $contract->salary)).' '
                .(Currency::getLocalizedName((int) $contract->currency)[$locale] ?? '').' '
                .__(match ((int) $contract->salary_per) {
                    SalaryPer::Hour => 'general.composer.per-hour',
                    SalaryPer::Session => 'general.composer.per-session',
                    default => 'general.composer.per-month',
                }),
        };

        return [
            'type' => ContractTypeEnum::getLocalizedName((int) $contract->type)['value'],
            'workDays' => $workDays,
            'hours' => (int) $contract->weekly_hours > 0 ? $digits(__('contracts.hours-short', ['count' => (int) $contract->weekly_hours])) : '—',
            'salary' => $salary,
        ];
    }

    /**
     * The last amendment in effect, told up front: HR may open this page from
     * the list without knowing the contract was amended only days ago, and the
     * new amendment starts from the terms that one left.
     *
     * @return array{number: int, date: string, ago: string, recent: bool, summary: string, count: int}|null
     */
    private function previousAmendment(): ?array
    {
        $applied = $this->contract->appliedAmendments()->with('payTiers')->get();
        $last = $applied->sortByDesc('number')->first();

        if ($last === null) {
            return null;
        }

        return [
            'number' => $last->number,
            'date' => $last->effective_from->format('d/m/Y'),
            'ago' => $last->effective_from->diffForHumans(),
            // A month or less: likely to be the same change being entered twice.
            'recent' => $last->effective_from->gte(today()->subDays(30)),
            'summary' => app(ContractAmendmentDescriber::class)->summary($last, auth()->user()?->canViewSalary() ?? false),
            'count' => $applied->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function draftSeed(): array
    {
        $draft = $this->draft();
        // A new amendment may not take effect before the last one in effect
        // (ContractAmendmentService::assertEffectiveDate); the picker says so first.
        // Not through appliedAmendments(): its ORDER BY on an aggregate trips MySQL's ONLY_FULL_GROUP_BY.
        $latest = ContractAmendment::query()
            ->where('contract_id', $this->contract->id)
            ->where('status', ContractAmendmentStatus::Applied)
            ->max('effective_from');
        $min = $latest ? ['minEffectiveFrom' => Carbon::parse($latest)->toDateString()] : [];

        return $draft
            ? [...$min, 'effectiveFrom' => $draft->effective_from->toDateString(), 'amendmentReason' => $draft->reason ?? '']
            : $min;
    }
}
