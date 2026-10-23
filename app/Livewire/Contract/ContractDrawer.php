<?php

namespace App\Livewire\Contract;

use App\Models\Contract;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Read-only contract details beside the contracts list (B4, table-contract.md §3).
 *
 * A separate component on purpose: opening or closing it never re-renders or
 * touches the table, so search, filters, sort and page survive (AC-B4-01).
 * It does not use GuardsModalClose (G-A open item 1): it has nothing to save,
 * so every dismissal is ordinary - Escape, the close button, the backdrop.
 *
 * It shows no pay figure to anyone. Pay lives on the full contract page, which
 * applies User::canViewSalary(); only the contract id is in the payload.
 */
class ContractDrawer extends Component
{
    /** Locked: only open() may choose the target, and only after authorizing it. */
    #[Locked]
    public ?int $contractId = null;

    #[On('open-contract-drawer')]
    public function open(int $contractId): void
    {
        $contract = Contract::query()->findOrFail($contractId);
        Gate::authorize('view', $contract);

        $this->contractId = $contract->getKey();
    }

    public function close(): void
    {
        $id = $this->contractId;
        $this->contractId = null;

        $this->dispatch('contract-drawer-closed', id: $id);
    }

    public function render(): View
    {
        $contract = null;

        if ($this->contractId) {
            $contract = Contract::query()
                ->with(['employee', 'position.department', 'position.program', 'position.jobLevel', 'position.city', 'position.center.city', 'contractType', 'terminationReason'])
                ->find($this->contractId);

            // Re-checked on every render: the role may have switched, or the record changed, while open.
            if (! $contract || ! Gate::allows('view', $contract)) {
                $this->contractId = null;
                $contract = null;
            }
        }

        return view('livewire.contract.contract-drawer', [
            'contract' => $contract,
            'canEdit' => $contract && Gate::allows('update', $contract),
        ]);
    }
}
