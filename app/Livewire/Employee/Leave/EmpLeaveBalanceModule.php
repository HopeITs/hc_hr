<?php

namespace App\Livewire\Employee\Leave;

use App\Livewire\Concerns\AuthorizesActivePermissions;
use App\Livewire\Concerns\GuardsModalClose;
use App\Livewire\Concerns\WithFeedback;
use App\Livewire\Employee\Leave\Concerns\FormatsLeaveDuration;
use App\Livewire\Forms\LeaveBalanceForm;
use App\Models\LeaveBalance;
use Blade;
use Livewire\Component;

class EmpLeaveBalanceModule extends Component
{
    use AuthorizesActivePermissions;
    use FormatsLeaveDuration;
    use GuardsModalClose;
    use WithFeedback;

    public LeaveBalance $leaveBalance;

    public LeaveBalanceForm $form;

    public $showModal = false;

    public $modalTitle = '';

    protected $listeners = [
        'openEdit' => 'edit',

        'processSave' => 'processSave',
    ];

    public function edit(LeaveBalance $id)
    {
        // A reopened form starts from the defaults, not from an abandoned edit.
        $this->form->reset();
        $this->form->resetValidation();
        $this->form->setLeaveBalance($id);

        $this->modalTitle = __('general.edit');
        $this->markClean();
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

    protected function guardedEntries(string $dialog): array
    {
        return $this->form->only(['operation', 'unit', 'amount', 'add_badge', 'notes']);
    }

    protected function closeGuardedDialog(string $dialog): void
    {
        $this->showModal = false;
        $this->form->reset();
        $this->form->resetValidation();
    }

    protected function confirmationMessage(): string
    {

        $name = $this->form->balance->contract->employee->name;
        $operation = __('general.'.$this->form->operation);
        $amount = $this->formatLeaveAmount($this->form->amount, $this->form->unit);
        $add_badge = $this->form->add_badge ? __('general.yes') : __('general.no');
        $notes = $this->form->add_badge ? $this->form->notes : null;

        return Blade::render(
            '<x-leave.balance-confirmation
                :name="$name"
                :operation="$operation"
                :amount="$amount"
                :add-badge="$add_badge"
                :notes="$notes"
            />', compact('name', 'operation', 'amount', 'add_badge', 'notes'),
        );
    }
}
