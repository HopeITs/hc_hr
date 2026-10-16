<?php

namespace App\Livewire\Employee\Leave;

use App\Livewire\Concerns\AuthorizesActivePermissions;
use App\Livewire\Concerns\WithFeedback;
use App\Livewire\Forms\LeaveBalanceForm;
use App\Models\LeaveBalance;
use Blade;
use Livewire\Component;

class EmpLeaveBalanceModule extends Component
{
    use AuthorizesActivePermissions;
    use WithFeedback;

    public LeaveBalance $leaveBalance;

    public LeaveBalanceForm $form;

    public $showModal = false;

    public $modalTitle = 'Modal title';

    protected $listeners = [
        'openEdit' => 'edit',

        'processSave' => 'processSave',
    ];

    public function edit(LeaveBalance $id)
    {
        $this->form->setLeaveBalance($id);

        $this->modalTitle = __('general.edit');
        $this->showModal = true;
    }

    public function render()
    {

        return view('livewire.employee.leave.balance');
    }

    public function confirmSave()
    {
        $html = $this->confirmationMessage();

        $this->confirm('processSave', title: __('general.confirmation'), html: $html, confirmLabel: __('general.save'), danger: false);

    }

    public function processSave()
    {
        $this->authorizeActivePermission('leaves.manage-balances');

        $this->form->updateAnnualLeaves();

        $this->showModal = false;
        $this->form->reset();

        $this->toast();

    }

    protected function confirmationMessage(): string
    {

        $name = $this->form->balance->contract->employee->name;
        $operation = __('general.'.$this->form->operation);
        $amount = $this->form->amount.' '.trans_choice('general.'.$this->form->unit, $this->form->amount);
        $add_badge = $this->form->add_badge ? __('general.yes') : __('general.no');
        $notes = $add_badge ? $this->form->notes : '';

        return Blade::render(
            '<x-alerts.leave-balance-confirmation
                :name="$name"
                :operation="$operation"
                :amount="$amount"
                :add_badge="$add_badge"
                :notes="$notes"

            />', compact('name', 'operation', 'amount', 'add_badge', 'notes'),
        );
    }
}
