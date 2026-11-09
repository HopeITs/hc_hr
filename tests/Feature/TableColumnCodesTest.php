<?php

namespace Tests\Feature;

use App\Livewire\Contract\ContractsTable;
use App\Livewire\Employee\EmployeeTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * Switching EN/AR hid columns on the contracts and employees lists: the saved
 * column choice is a list of column codes, and some codes followed the locale
 * (a closure column's md5(label), a title_en / title_ar column). Codes are
 * fixed now, and codes saved under the old scheme are translated on restore.
 */
class TableColumnCodesTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->makeUser($this->makeCity('SYR', '000'), 'HR-SYR', ['contracts.view', 'employees.view']);
    }

    public function test_contract_column_codes_do_not_change_with_the_language(): void
    {
        $this->assertSame($this->codes(ContractsTable::class, 'en'), $this->codes(ContractsTable::class, 'ar'));
        $this->assertContains('actions', $this->codes(ContractsTable::class, 'en'));
        $this->assertContains('contract_type', $this->codes(ContractsTable::class, 'en'));
    }

    public function test_employee_column_codes_do_not_change_with_the_language(): void
    {
        $this->assertSame($this->codes(EmployeeTable::class, 'en'), $this->codes(EmployeeTable::class, 'ar'));
        $this->assertContains('actions', $this->codes(EmployeeTable::class, 'en'));
        $this->assertContains('city', $this->codes(EmployeeTable::class, 'en'));
    }

    public function test_the_chosen_columns_survive_a_language_switch(): void
    {
        app()->setLocale('en');
        Livewire::actingAs($this->user)->test(ContractsTable::class)
            ->set('columns', ['employee', 'contract_type', 'period', 'actions']);

        app()->setLocale('ar');
        Livewire::actingAs($this->user)->test(ContractsTable::class)
            ->assertSet('columns', ['employee', 'contract_type', 'period', 'actions']);
    }

    public function test_columns_saved_under_the_old_codes_are_kept(): void
    {
        app()->setLocale('ar');
        // What a session held before this fix, saved while in Arabic.
        $component = Livewire::actingAs($this->user)->test(ContractsTable::class);
        $actionsAr = md5(__('general.actions', [], 'ar'));
        session()->put($this->sessionKey($component), [
            'columns' => ['employee', 'contractType_name_ar', 'position_jobLevel_name_ar', $actionsAr],
            'columnOrder' => ['employee', 'position_program_slug', 'position_title_ar', 'contractType_name_ar', 'city', 'position_department_name',
                'position_jobLevel_name_ar', 'duration', 'period', 'terminated_at', 'leaveBalances', 'salary_per', 'weekly_hours', 'is_active', 'auto_renew', $actionsAr],
            'sortColumn' => 'contractType_name_ar',
            'sortDirection' => 'asc',
        ]);

        app()->setLocale('en');
        $table = Livewire::actingAs($this->user)->test(ContractsTable::class)
            ->assertSet('columns', ['employee', 'contract_type', 'job_level', 'actions'])
            ->assertSet('sortColumn', 'contract_type');

        // And already in order on that first render: Actions last, not sorted to the front.
        $html = $table->html();
        $this->assertLessThan(strpos($html, '>'.__('general.actions').'<'), strpos($html, '>'.trans_choice('general.contract-type', 1).'<'));
    }

    /** @return list<string> */
    private function codes(string $table, string $locale): array
    {
        app()->setLocale($locale);

        $component = Livewire::actingAs($this->user)->test($table)->instance();
        $resolve = (new ReflectionMethod($component, 'resolveColumns'));

        return $resolve->invoke($component)->map(fn ($c) => $c->code())->values()->all();
    }

    private function sessionKey($testable): string
    {
        $component = $testable->instance();

        return (new ReflectionMethod($component, 'sessionKey'))->invoke($component);
    }
}
