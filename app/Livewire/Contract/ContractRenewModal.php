<?php

namespace App\Livewire\Contract;

use App\Livewire\Concerns\WithFeedback;
use App\Livewire\Forms\ContractForm;
use App\Livewire\Forms\LeaveBalanceForm;
use App\Models\Contract;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The renewal entry point, shared by the contracts table and the contract
 * details page: pick the new period, then either save the successor straight
 * away or carry the same period into the full creation wizard.
 *
 * One instance serves every Renew button on a page — they address it by
 * dispatching `open-renew-modal` with a contract id.
 */
class ContractRenewModal extends Component
{
    use WithFeedback;

    public ContractForm $form;

    public LeaveBalanceForm $balanceForm;

    public bool $show = false;

    /** Locked: only open() may choose the target, and only after authorizing it. */
    #[Locked]
    public ?int $contractId = null;

    #[On('open-renew-modal')]
    public function open(int $contractId): void
    {
        $contract = $this->authorizedContract($contractId);

        $this->resetErrorBag();

        $this->contractId = $contract->id;
        [$this->form->start_date, $this->form->end_date] = $contract->renewalWindow();
        $this->show = true;
    }

    public function close(): void
    {
        $this->reset(['show', 'contractId']);
        $this->resetErrorBag();
    }

    /**
     * Create the successor with every term copied from the source contract and
     * only the period replaced.
     */
    public function renewAsIs(): mixed
    {
        $source = $this->authorizedContract($this->contractId);

        // Keep whatever period is on screen; everything else comes from source.
        $this->form->fillFromRenewal(
            $source,
            (string) $this->form->start_date,
            (string) $this->form->end_date,
        );

        $this->form->validateIdentity();
        $this->form->validateTerms();
        $this->form->validatePay();

        $renewal = $this->form->create();

        // Same as ContractCreate::submit() — a renewal is entitled to its own
        // leave balances, including the carry-over from the contract it follows.
        $this->balanceForm->store($renewal->id);

        $this->show = false;

        $this->toast();

        return redirect()->route('contracts.show', $renewal->id);
    }

    /**
     * Hand the chosen period to the full wizard, where every field is editable
     * before anything is written.
     */
    public function renewWithEdit(): mixed
    {
        $source = $this->authorizedContract($this->contractId);

        $this->validate([
            'form.start_date' => ['required', 'date_format:Y-m-d'],
            'form.end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:form.start_date'],
        ]);

        return redirect()->route('contracts.renew', [
            'contract' => $source->id,
            'start' => $this->form->start_date,
            'end' => $this->form->end_date,
        ]);
    }

    public function render(): View
    {
        return view('livewire.contract.contract-renew-modal', [
            // employee.media is eager-loaded so Employee::$avatar_url resolves
            // from the loaded relation instead of firing its own query.
            'contract' => $this->contractId
                ? Contract::query()
                    ->with(['employee.media', 'position', 'contractType'])
                    ->find($this->contractId)
                : null,
        ]);
    }

    /**
     * Re-read the contract from the database and re-check the renew gate.
     *
     * The id reaches this component from the browser, and the contract may have
     * been terminated or moved out of the user's city between opening the modal
     * and confirming, so every action authorizes from scratch rather than
     * trusting anything held in component state.
     */
    private function authorizedContract(?int $contractId): Contract
    {
        abort_unless($contractId !== null, 403);

        $contract = Contract::query()->findOrFail($contractId);

        Gate::authorize('renew', $contract);

        return $contract;
    }
}
