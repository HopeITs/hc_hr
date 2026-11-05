<?php

namespace App\Livewire\Contract;

use App\Livewire\Concerns\WithFeedback;
use App\Livewire\Contract\Concerns\DrivesContractComposer;
use App\Livewire\Forms\ContractForm;
use App\Models\Contract;
use App\Services\ContractAmendmentService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
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

    // Read by composerSeed(); an amendment has no location to choose.
    public ?int $city_id = null;

    public ?int $program_id = null;

    public ?int $center_id = null;

    public function mount(Contract $contract): void
    {
        Gate::authorize('amend', $contract);

        $this->contract = $contract->loadMissing(['employee', 'position', 'contractType', 'payTiers']);

        $this->form->setDefaults();
        $this->form->setContract($this->contract);
    }

    public function submit(array $payload, ContractAmendmentService $amendments): mixed
    {
        Gate::authorize('amend', $this->contract);

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

            $amendments->draft($contract, $this->form->amendmentTerms(), $effectiveFrom, $reason, auth()->user());
        } catch (ValidationException $exception) {
            $this->dispatch('composer-invalid', sections: $this->sectionsFor(array_keys($exception->errors())));

            throw $exception;
        }

        $this->toast(__('contracts.amendment.drafted'));

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
            'seed' => $this->composerSeed(),
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
}
