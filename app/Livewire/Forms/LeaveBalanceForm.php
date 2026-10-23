<?php

namespace App\Livewire\Forms;

use App\Enums\BadgeType;
use App\Models\Badge;
use App\Models\Contract;
use App\Models\LeaveBalance;
use App\Notifications\ActionLeaveBalance;
use App\Services\LeaveBalanceService;
use Illuminate\Support\Facades\Gate;
use Livewire\Form;

class LeaveBalanceForm extends Form
{
    public ?LeaveBalance $balance = null;

    public $contract_id;

    public $used = 0;

    public $deserved;

    public $operation = 'sum';

    public $unit = 'hour';

    public $amount = 0;

    public $add_badge = false;

    public $notes = null;

    public function setLeaveBalance(LeaveBalance $balance): void
    {
        $this->balance = $balance;

    }

    /** ------------------------------------------------------------
     *  STORE — create the contract's full set of leave balances.
     * -------------------------------------------------------------*/
    public function store($contractId): void
    {
        app(LeaveBalanceService::class)->create(Contract::findOrFail($contractId));
    }

    /** ------------------------------------------------------------
     *  UPDATE — recalculate and reconcile the contract's balances.
     * -------------------------------------------------------------*/
    public function update($contractId): void
    {
        app(LeaveBalanceService::class)->update(Contract::findOrFail($contractId));
    }

    public function updateAnnualLeaves()
    {
        $this->validate([
            'operation' => 'required|in:sum,sub',
            'unit' => 'required|in:hour,day',
            'amount' => 'required|numeric|min:0',
            'add_badge' => 'required|boolean',
            'notes' => $this->add_badge ? 'required|string|max:5000' : 'nullable',
        ]);

        if ($this->add_badge) {
            Gate::authorize('create', [
                Badge::class,
                $this->balance->contract->employee,
            ]);
        }

        if ($this->unit === 'day') {
            $amount = $this->amount * $this->balance->contract->work_hour_per_day;
        } else {
            $amount = $this->amount;
        }
        if ($this->operation === 'sum') {
            $this->balance->update(['deserved' => $this->balance->deserved + $amount]);

        } else {
            $this->balance->update(['deserved' => $this->balance->deserved - $amount]);
        }
        if ($this->add_badge) {
            $this->balance->contract->employee->badges()->create([
                'type' => $this->operation === 'sum' ? BadgeType::Reward : BadgeType::Warning,
                'date' => now(),
                'notes' => $this->notes,
            ]);
        }

        // Notify
        $balance = $this->balance->load('contract.position');
        $directManage = $balance->contract->position->directManager?->employee()->account;
        $techManager = $balance->contract->position->techManager?->employee()->account;
        $employee = $balance->contract->employee->account;
        isset($directManage) ? $directManage->notify(new ActionLeaveBalance(
            balance: $this->balance,
            operation: $this->operation,
            unit: $this->unit,
            amount: $this->amount,
            notes: $this->notes,
            recipientType: 'manager',
        )) : '';

        isset($techManager) ? $techManager->notify(new ActionLeaveBalance(
            balance: $this->balance,
            operation: $this->operation,
            unit: $this->unit,
            amount: $this->amount,
            notes: $this->notes,
            recipientType: 'manager',
        )) : '';

        $employee?->notify(new ActionLeaveBalance(
            balance: $this->balance,
            operation: $this->operation,
            unit: $this->unit,
            amount: $this->amount,
            notes: $this->notes,
            recipientType: 'employee',
        ));

    }

    /**
     * Field names used in validation messages, in the current locale.
     *
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'operation' => __('general.operation'),
            'unit' => __('general.unit'),
            'amount' => __('general.amount'),
            'add_badge' => __('general.add-as-badge'),
            'notes' => __('general.note'),
        ];
    }

    protected function rules()
    {
        return [
            'contract_id' => 'required|exists:contracts,id',
        ];
    }
}
