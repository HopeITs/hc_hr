<?php

namespace Tests\Feature;

use App\Enums\LeaveTypeCases;
use App\Livewire\Employee\Leave\EmpLeaveBalanceModule;
use App\Livewire\Employee\Leave\EmpLeaveBalanceTable;
use App\Livewire\ProgramManagement\Center\CentersModule;
use App\Livewire\ProgramManagement\Center\CentersTable;
use App\Models\Center;
use App\Models\LeaveBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\Livewire;
use ReflectionClass;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * A page's dialog saves through the page component, but its list is a child
 * component that does not re-render with it: every save must tell the table.
 * And a form's wire:submit is what Enter sends, so it must name a real method
 * (leaves/all and leaves/balance named a missing save(): Enter gave a 500,
 * a bare 419 in production). The All Leaves cases sit with their fixtures in
 * LeaveRequestWorkflowTest.
 */
class DialogSaveRefreshesTableTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    /** Views no component mounts; see ModalCloseGuardTest::READ_ONLY_DIALOGS. */
    private const UNMOUNTED_VIEWS = [
        'livewire.employee.leave.form', // UpdateLeaveRequest
    ];

    public function test_every_livewire_form_submits_to_a_method_its_component_has(): void
    {
        $missing = [];

        foreach (File::allFiles(app_path('Livewire')) as $file) {
            $class = 'App\\Livewire\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

            if (! class_exists($class) || ! is_subclass_of($class, Component::class) || (new ReflectionClass($class))->isAbstract()) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            $source = File::get($file->getPathname());
            preg_match_all('/view\(\s*[\'"]([^\'"]+)[\'"]/', $source, $matches);
            $views = $matches[1] ?: [
                'livewire.'.collect(explode('\\', Str::after($class, 'App\\Livewire\\')))->map(fn ($part) => Str::kebab($part))->implode('.'),
            ];

            foreach (array_diff(array_unique($views), self::UNMOUNTED_VIEWS) as $view) {
                if (! view()->exists($view)) {
                    continue;
                }

                preg_match_all('/wire:submit(?:\.[\w.]+)?="\s*([A-Za-z_]\w*)/', File::get(view()->getFinder()->find($view)), $submits);

                foreach ($submits[1] as $method) {
                    if (! $reflection->hasMethod($method) || ! $reflection->getMethod($method)->isPublic()) {
                        $missing[] = "{$view}: wire:submit=\"{$method}\" ({$class})";
                    }
                }
            }
        }

        $this->assertSame([], $missing, 'Enter in these forms calls a method that does not exist.');
    }

    public function test_saving_a_balance_refreshes_the_balance_table(): void
    {
        $balance = $this->makeAnnualBalance();
        $hr = $this->makeUser($balance->contract->employee->city, 'HR-ALP', ['leaves.manage-balances']);

        Livewire::actingAs($hr)
            ->test(EmpLeaveBalanceModule::class)
            ->assertSeeHtml('wire:submit.prevent="confirmSave"')
            ->assertDontSeeHtml('wire:click="confirmSave"')
            ->call('edit', $balance->id)
            ->set('form.operation', 'sum')
            ->set('form.unit', 'hour')
            ->set('form.amount', 4)
            ->call('processSave')
            ->assertHasNoErrors()
            ->assertDispatched('refreshEmpLeaveBalanceTable');

        $this->assertEquals(164, $balance->fresh()->deserved);
        $this->assertArrayHasKey('refreshEmpLeaveBalanceTable', $this->listenersOf(EmpLeaveBalanceTable::class));
    }

    public function test_saving_a_center_refreshes_the_centers_table(): void
    {
        $city = $this->makeCity('ALP', '001');
        $hr = $this->makeUser($city, 'HR-ALP', ['centers.create', 'centers.update']);
        $center = Center::query()->create([
            'name_en' => 'Hope Center',
            'name_ar' => 'مركز الرجاء',
            'city_id' => $city->id,
            'is_active' => true,
            'weekends' => 'friday,saturday',
            'open_at' => '08:00:00',
            'close_at' => '16:00:00',
        ]);

        Livewire::actingAs($hr)
            ->test(CentersModule::class)
            ->call('edit', $center->id)
            ->set('item.name_en', 'Hope Center Renamed')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('refreshCentersTable');

        $this->assertSame('Hope Center Renamed', $center->fresh()->name_en);
        $this->assertArrayHasKey('refreshCentersTable', $this->listenersOf(CentersTable::class));
    }

    private function listenersOf(string $table): array
    {
        $listeners = (new ReflectionClass($table))->getProperty('listeners')->getDefaultValue();

        return collect($listeners)->mapWithKeys(fn ($method, $event) => [is_int($event) ? $method : $event => $method])->all();
    }

    private function makeAnnualBalance(): LeaveBalance
    {
        $city = $this->makeCity('ALP', '001');
        $employee = $this->makeEmployee($city, 'leave-'.uniqid());
        $contract = $this->makeContractFor($employee, ['start_date' => now()->subMonth(), 'end_date' => now()->addYear()]);

        DB::table('leave_types')->insertOrIgnore([
            'id' => LeaveTypeCases::Annual,
            'name_en' => 'Annual Leave',
            'name_ar' => 'إجازة سنوية',
            'base_days' => 20,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $id = DB::table('leave_balances')->insertGetId([
            'contract_id' => $contract->id,
            'type_id' => LeaveTypeCases::Annual,
            'deserved' => 160,
            'used' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return LeaveBalance::query()->with('contract.employee.city')->findOrFail($id);
    }
}
