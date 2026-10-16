<?php

namespace App\Livewire\Contract;

use App\Livewire\Concerns\WithFeedback;
use App\Livewire\Contract\Concerns\DrivesContractComposer;
use App\Livewire\Contract\Concerns\ResolvesContractLocationOptions;
use App\Livewire\Forms\ContractForm;
use App\Livewire\Forms\LeaveBalanceForm;
use App\Models\Contract;
use App\Models\TerminationReason;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Single-page contract composer, editing an existing contract.
 *
 * The same screen as ContractCreate — same sections, same Alpine component,
 * same one-request submit — opened on a contract that already exists and with
 * one extra section on the end: termination, which cannot apply to a contract
 * that has not been signed yet.
 *
 * Work days are deliberately *not* required here even though create demands
 * them: contracts imported from Excel before the field existed have null work
 * days, and editing an unrelated salary must not be blocked on filling them in.
 */
class ContractEdit extends Component
{
    use DrivesContractComposer, ResolvesContractLocationOptions {
        DrivesContractComposer::composerSections as private baseComposerSections;
        DrivesContractComposer::sectionOfField as private baseSectionOfField;
    }
    use WithFeedback;

    public ContractForm $form;

    public LeaveBalanceForm $balanceForm;

    public Contract $contract;

    public ?int $city_id = null;

    public ?int $program_id = null;

    public ?int $center_id = null;

    // ────────────────────────────────────────────────
    // Lifecycle
    // ────────────────────────────────────────────────

    public function mount(Contract $contract): void
    {
        Gate::authorize('update', $contract);

        $this->contract = $contract->loadMissing('position.center');

        $this->form->setDefaults();
        $this->form->setContract($contract);

        $this->initLocationContext($contract);
    }

    // ────────────────────────────────────────────────
    // Submission
    // ────────────────────────────────────────────────

    public function submit(array $payload): mixed
    {
        Gate::authorize('update', $this->contract);
        $this->guardAutoRenewPayload($payload);

        $this->applyComposerPayload($payload);

        try {
            $this->form->validateIdentity();
            $this->form->validateTerms();
            $this->form->validatePay();
        } catch (ValidationException $exception) {
            // Reopen the offending section before Livewire renders the errors.
            $this->dispatch('composer-invalid', sections: $this->sectionsFor(array_keys($exception->errors())));

            throw $exception;
        }

        if (! $this->validateCityConsistency()) {
            $this->dispatch('composer-invalid', sections: $this->sectionsFor($this->getErrorBag()->keys()));

            return null;
        }

        $this->form->update($this->contract);

        // All contract types receive annual leave balances.
        if ($this->contract->leaveBalances()->exists()) {
            $this->balanceForm->update($this->contract->id);
        } else {
            $this->balanceForm->store($this->contract->id);
        }

        $this->toast();

        return redirect()->route('contracts.show', $this->contract->id);
    }

    // ────────────────────────────────────────────────
    // Rendering
    // ────────────────────────────────────────────────

    public function render(): View
    {
        $locale = app()->getLocale();
        $nameColumn = "name_{$locale}";

        $cities = $this->getCities($nameColumn);

        return view('livewire.contract.contract-edit', [
            'cities' => $cities,
            'lockedCity' => $cities->count() === 1 ? $cities->first() : null,
            'types' => $this->contractTypeCards($nameColumn),
            'terminationReasons' => TerminationReason::query()
                ->select('id', "title_{$locale}")
                ->orderBy("title_{$locale}")
                ->get(),
            'seed' => $this->composerSeed(),
            'canManageAutoRenew' => auth()->user()->can('manageAutoRenew', $this->contract),
        ]);
    }

    protected function locationOptionsPermission(): string
    {
        return 'contracts.update';
    }

    protected function locationOptionsEmployeeId(): ?int
    {
        Gate::authorize('update', $this->contract);

        return $this->contract->employee_id;
    }

    protected function composerContractKey(): ?int
    {
        return $this->contract->id;
    }

    /** Editing adds the one section a contract that does not exist cannot have. */
    protected function composerSections(): array
    {
        return [...$this->baseComposerSections(), 'termination'];
    }

    /**
     * The termination trio is editable here, and recording one deactivates the
     * contract — the same rule ContractForm::saveTerms() has always applied.
     * Clearing the date again does not reactivate, and does not switch
     * auto-renew back on either: both are decisions for the contract list,
     * not side effects of tidying up a field.
     */
    protected function applyComposerTermination(array $payload): void
    {
        if (auth()->user()->can('manageAutoRenew', $this->contract)) {
            $this->form->auto_renew = (bool) ($payload['autoRenew'] ?? false);
        }

        $this->form->terminated_at = $this->dateOrNull($payload['terminatedAt'] ?? null);
        $this->form->termination_reason_id = $this->selectionId($payload, 'terminationReasonId');
        $this->form->reason_of_termination = $this->textOrNull($payload['reasonOfTermination'] ?? null);

        $isTerminated = filled($this->form->terminated_at) || filled($this->form->termination_reason_id);

        $this->form->is_active = $isTerminated ? false : (bool) $this->contract->is_active;

        // A terminated contract never rolls over. ContractObserver::saving()
        // enforces this on every write; mirroring it here keeps the form state
        // honest for whatever is rendered after the save.
        if ($isTerminated) {
            $this->form->auto_renew = false;
        }
    }

    /** The termination fields belong to the section that owns them. */
    protected function sectionOfField(): array
    {
        return array_merge($this->baseSectionOfField(), [
            'terminated_at' => 'termination',
            'termination_reason_id' => 'termination',
            'reason_of_termination' => 'termination',
            'auto_renew' => 'termination',
        ]);
    }

    /**
     * An edit keeps the term it already runs from.
     *
     * Without this, switching contract type on a contract that started in March
     * would advertise — and on submit apply — a term beginning next month.
     */
    protected function contractTermAnchor(): ?string
    {
        return $this->form->start_date;
    }

    private function guardAutoRenewPayload(array $payload): void
    {
        if (! array_key_exists('autoRenew', $payload)) {
            return;
        }

        abort_unless(auth()->user()->can('manageAutoRenew', $this->contract), 403);
    }
}
