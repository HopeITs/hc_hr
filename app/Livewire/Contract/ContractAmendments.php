<?php

namespace App\Livewire\Contract;

use App\Enums\ContractType as ContractTypeEnum;
use App\Enums\Currency;
use App\Exceptions\ContractAmendmentCannotApply;
use App\Livewire\Concerns\WithFeedback;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Services\ContractAmendmentService;
use Carbon\Carbon;
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

        return view('livewire.contract.contract-amendments', [
            'contract' => $contract,
            'amendments' => $amendments,
            'changes' => $amendments->mapWithKeys(fn (ContractAmendment $a) => [$a->id => $this->describe($a, $withPay)]),
            'canAmend' => $user?->can('amend', $contract) ?? false,
        ]);
    }

    /**
     * What an amendment changed, one line per term a person would name:
     * [label, before, after]. Pay lines are left out for a role barred from
     * figures.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function describe(ContractAmendment $amendment, bool $withPay): array
    {
        $changed = $amendment->changedFields();
        $before = $amendment->terms_before ?? [];
        $after = $amendment->terms_after ?? [];
        $lines = [];

        $typeName = fn ($type) => filled($type) ? ContractTypeEnum::getLocalizedName((int) $type)['value'] : '—';
        $hours = fn (array $t) => filled($t['weekly_hours'] ?? null) ? trans_choice('contracts.amendment.weekly-hours', (int) $t['weekly_hours'], ['count' => (int) $t['weekly_hours']]) : '—';
        $time = fn ($v) => filled($v) ? substr((string) $v, 0, 5) : null;
        $shift = function (array $t) use ($time): string {
            $first = $time($t['work_hours_from'] ?? null) && $time($t['work_hours_to'] ?? null)
                ? $time($t['work_hours_from']).'–'.$time($t['work_hours_to'])
                : null;
            $second = $time($t['work_hours_2_from'] ?? null) && $time($t['work_hours_2_to'] ?? null)
                ? $time($t['work_hours_2_from']).'–'.$time($t['work_hours_2_to'])
                : null;

            return collect([$first, $second])->filter()->implode(' · ') ?: '—';
        };
        $days = function (array $t): string {
            if (! is_numeric($t['work_day_from'] ?? null) || ! is_numeric($t['work_day_to'] ?? null)) {
                return '—';
            }

            $name = fn (int $d) => Carbon::now()->startOfWeek(Carbon::SUNDAY)->addDays($d)->translatedFormat('l');

            return $name((int) $t['work_day_from']).' – '.$name((int) $t['work_day_to']);
        };

        if (in_array('type', $changed, true)) {
            $lines[] = [__('general.type'), $typeName($before['type'] ?? null), $typeName($after['type'] ?? null)];
        }

        if (in_array('weekly_hours', $changed, true)) {
            $lines[] = [__('general.weekly-hours'), $hours($before), $hours($after)];
        }

        if (array_intersect(['work_hours_from', 'work_hours_to', 'work_hours_2_from', 'work_hours_2_to'], $changed)) {
            $lines[] = [__('contracts.amendment.hours'), $shift($before), $shift($after)];
        }

        if (array_intersect(['work_day_from', 'work_day_to'], $changed)) {
            $lines[] = [__('general.work-days'), $days($before), $days($after)];
        }

        if ($withPay && array_intersect(['salary', 'currency', 'salary_per'], $changed)) {
            $money = fn ($amount, $currency) => filled($amount)
                ? number_format((float) $amount, 2).' '.(Currency::getLocalizedName((int) $currency)[app()->getLocale()] ?? '')
                : '—';

            $lines[] = [
                __('general.salary'),
                $money($amendment->salary_before, $before['currency'] ?? null),
                $money($amendment->salary_after, $after['currency'] ?? null),
            ];
        }

        if ($withPay && in_array('pay_tiers', $changed, true)) {
            $lines[] = [
                __('contracts.amendment.pay-tiers'),
                trans_choice('contracts.tiers-count', $amendment->payTiersBefore->count(), ['count' => $amendment->payTiersBefore->count()]),
                trans_choice('contracts.tiers-count', $amendment->payTiersAfter->count(), ['count' => $amendment->payTiersAfter->count()]),
            ];
        }

        $described = ['type', 'weekly_hours', 'work_hours_from', 'work_hours_to', 'work_hours_2_from', 'work_hours_2_to',
            'work_day_from', 'work_day_to', 'salary', 'currency', 'salary_per', 'pay_tiers'];
        $rest = array_diff($changed, $described, $withPay ? [] : ContractAmendment::PAY_FIELDS);

        if ($rest !== []) {
            $lines[] = [__('contracts.amendment.other-terms'), '', ''];
        }

        return $lines;
    }

    private function amendment(int $id): ContractAmendment
    {
        return ContractAmendment::query()
            ->where('contract_id', $this->contractId)
            ->findOrFail($id);
    }
}
