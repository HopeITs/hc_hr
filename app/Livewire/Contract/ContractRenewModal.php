<?php

namespace App\Livewire\Contract;

use App\Livewire\Concerns\GuardsModalClose;
use App\Livewire\Concerns\WithFeedback;
use App\Livewire\Forms\ContractForm;
use App\Livewire\Forms\LeaveBalanceForm;
use App\Models\Contract;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
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
    use GuardsModalClose;
    use WithFeedback;

    public ContractForm $form;

    public LeaveBalanceForm $balanceForm;

    public bool $show = false;

    /** Locked: only open() may choose the target, and only after authorizing it. */
    #[Locked]
    public ?int $contractId = null;

    /**
     * One per opened dialog (X1-I, the same rule as ContractCreate's B2
     * token): a double-sent or retried renewAsIs carries the same token and
     * finds the successor the first one created. Locked: the browser cannot
     * swap or clear it.
     */
    #[Locked]
    public string $submissionToken = '';

    #[On('open-renew-modal')]
    public function open(int $contractId): void
    {
        $contract = $this->authorizedContract($contractId);

        $this->resetErrorBag();

        $this->contractId = $contract->id;
        $this->submissionToken = (string) Str::uuid();
        [$this->form->start_date, $this->form->end_date] = $contract->renewalWindow();
        $this->markClean('contractRenewModal');
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
        $key = $this->submissionKey();

        try {
            return Cache::lock($key.':lock', 30)->block(10, fn () => $this->renewOnce($key));
        } catch (LockTimeoutException) {
            $this->toast(__('general.composer.submit-in-progress'), type: 'info');

            return null;
        }
    }

    /**
     * X1-I: the browser lost the answer to renewAsIs and asks whether the
     * operation was applied. A lookup by the dialog's token; it never renews,
     * and the period on screen stays when nothing was created.
     */
    public function reconcileOperation(): mixed
    {
        $this->authorizedContract($this->contractId);

        $renewal = $this->createdBy($this->submissionKey());

        if ($renewal === null) {
            $this->toast(__('general.unknown-result.not-applied'), type: 'info');

            return null;
        }

        $this->toast(__('general.unknown-result.applied'));

        return $this->redirectRoute('contracts.show', $renewal->id, navigate: true);
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

        return $this->redirectRoute('contracts.renew', [
            'contract' => $source->id,
            'start' => $this->form->start_date,
            'end' => $this->form->end_date,
        ], navigate: true);
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

    /** The dialog's id; it is shown by $show. */
    protected function guardedDialogs(): array
    {
        return ['contractRenewModal'];
    }

    protected function guardedDialogIsOpen(string $dialog): bool
    {
        return $this->show;
    }

    /** Only the period is editable here; every other term is copied. */
    protected function guardedEntries(string $dialog): array
    {
        return $this->form->only(['start_date', 'end_date']);
    }

    protected function closeGuardedDialog(string $dialog): void
    {
        $this->close();
    }

    private function renewOnce(string $key): mixed
    {
        $source = $this->authorizedContract($this->contractId);

        if ($renewal = $this->createdBy($key)) {
            return $this->redirectRoute('contracts.show', $renewal->id, navigate: true);
        }

        // Keep whatever period is on screen; everything else comes from source.
        $this->form->fillFromRenewal(
            $source,
            (string) $this->form->start_date,
            (string) $this->form->end_date,
        );

        $this->form->validateIdentity();
        $this->form->validateTerms();
        $this->form->validatePay();

        // The contract and its leave balances are one operation: a failure in
        // between must not leave a successor that a retry would then repeat.
        $renewal = DB::transaction(function (): Contract {
            $renewal = $this->form->create();

            // Same as ContractCreate::submit() — a renewal is entitled to its
            // own leave balances, including the carry-over from the contract
            // it follows.
            $this->balanceForm->store($renewal->id);

            return $renewal;
        });

        Cache::put($key, $renewal->id, now()->addDay());

        $this->show = false;

        $this->toast();

        return $this->redirectRoute('contracts.show', $renewal->id, navigate: true);
    }

    private function submissionKey(): string
    {
        return 'contract-renew-submission:'.auth()->id().':'.sha1($this->submissionToken);
    }

    /** The successor an earlier send created, if it is still this user's to see. */
    private function createdBy(string $key): ?Contract
    {
        $id = Cache::get($key);

        if (! $id) {
            return null;
        }

        $contract = Contract::query()->find($id);

        return $contract && auth()->user()->can('view', $contract) ? $contract : null;
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
