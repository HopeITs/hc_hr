<?php

namespace App\Livewire\Contract;

use App\Exceptions\ContractAmendmentCannotApply;
use App\Livewire\Concerns\WithFeedback;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Services\ContractAmendmentDescriber;
use App\Services\ContractAmendmentService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * The amendments card on a contract's page: every amendment with what it
 * changed, and the steps of the one in flight — approve once signed, discard
 * if it never will be, attach the signed copy.
 */
class ContractAmendments extends Component
{
    use WithFeedback, WithFileUploads;

    #[Locked]
    public int $contractId;

    /** @var array<int|string, mixed> one upload slot per amendment, keyed by its id */
    public array $signedFiles = [];

    public function mount(Contract $contract): void
    {
        Gate::authorize('view', $contract);

        $this->contractId = $contract->id;
    }

    public function askApprove(int $amendmentId): void
    {
        $amendment = $this->amendment($amendmentId);
        Gate::authorize('decideAmendment', $amendment);

        $this->confirm(
            'approve',
            $amendmentId,
            title: __('contracts.amendment.approve-title'),
            message: $amendment->effective_from->lte(today())
                ? __('contracts.amendment.approve-now', ['date' => $amendment->effective_from->format('d/m/Y')])
                : __('contracts.amendment.approve-later', ['date' => $amendment->effective_from->format('d/m/Y')]),
            confirmLabel: __('contracts.amendment.approve'),
            danger: false,
        );
    }

    public function approve(int $amendmentId, ContractAmendmentService $amendments): void
    {
        $amendment = $this->amendment($amendmentId);
        Gate::authorize('decideAmendment', $amendment);

        try {
            $amendment = $amendments->approve($amendment, auth()->user());
        } catch (ValidationException $e) {
            $this->toast(collect($e->errors())->flatten()->first(), 'error');

            return;
        } catch (ContractAmendmentCannotApply $e) {
            // Approval is kept; the daily run, or HR after fixing the cause, applies it.
            $this->toast($e->getMessage(), 'error');

            return;
        }

        $this->toast($amendment->isApplied() ? __('contracts.amendment.applied') : __('contracts.amendment.approved'));

        // The contract's own terms on this page have changed underneath it.
        if ($amendment->isApplied()) {
            $this->redirectRoute('contracts.show', $this->contractId, navigate: true);
        }
    }

    public function askDiscard(int $amendmentId): void
    {
        Gate::authorize('decideAmendment', $this->amendment($amendmentId));

        $this->confirm(
            'discard',
            $amendmentId,
            title: __('contracts.amendment.discard-title'),
            message: __('contracts.amendment.discard-body'),
            confirmLabel: __('contracts.amendment.discard'),
        );
    }

    public function discard(int $amendmentId, ContractAmendmentService $amendments): void
    {
        $amendment = $this->amendment($amendmentId);
        Gate::authorize('decideAmendment', $amendment);

        $amendments->discard($amendment);

        $this->toast(__('contracts.amendment.discarded'));
    }

    /** A file chosen in a row's upload slot is the signed copy of that amendment. */
    public function updatedSignedFiles(mixed $value, string|int $key): void
    {
        $amendment = $this->amendment((int) $key);
        Gate::authorize('attachSignedAmendment', $amendment);

        $this->validate([
            "signedFiles.{$key}" => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
        ], [], ["signedFiles.{$key}" => __('contracts.amendment.signed-copy')]);

        $file = $this->signedFiles[$key];

        $amendment
            ->addMedia($file->getRealPath())
            ->usingFileName('amendment-'.$amendment->contract_id.'-'.$amendment->number.'.'.strtolower($file->getClientOriginalExtension()))
            ->toMediaCollection('signed');

        unset($this->signedFiles[$key]);

        $this->toast(__('contracts.amendment.signed-attached'));
    }

    public function render(): View
    {
        $contract = Contract::query()->findOrFail($this->contractId);
        $user = auth()->user();

        $amendments = $contract->amendments()
            ->with(['payTiers', 'media'])
            ->get()
            ->sortByDesc(fn (ContractAmendment $a) => [$a->effective_from->toDateString(), $a->number])
            ->values();

        $withPay = $user?->canViewSalary() ?? false;
        $describer = app(ContractAmendmentDescriber::class);

        return view('livewire.contract.contract-amendments', [
            'contract' => $contract,
            'amendments' => $amendments,
            'changes' => $amendments->mapWithKeys(fn (ContractAmendment $a) => [$a->id => $describer->lines($a, $withPay)]),
            'canAmend' => $user?->can('amend', $contract) ?? false,
        ]);
    }

    private function amendment(int $id): ContractAmendment
    {
        return ContractAmendment::query()
            ->where('contract_id', $this->contractId)
            ->findOrFail($id);
    }
}
