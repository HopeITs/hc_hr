<?php

namespace Tests\Feature;

use App\Livewire\Basics\BasicSettings;
use App\Models\City;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** The Project Basics reference tables as tabs of one page. */
class BasicSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const LEGACY = [
        'cities.index' => 'cities',
        'contract-types.index' => 'contract-types',
        'leave-types.index' => 'leave-types',
        'annual-leave-rules.index' => 'annual-leave-rules',
        'termination-reasons.index' => 'termination-reasons',
        'basic-checklist-items.index' => 'checklist-items',
        'job-levels.index' => 'job-levels',
        'departments.index' => 'departments',
        'course-categories.index' => 'course-categories',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_every_tab_renders_its_own_module(): void
    {
        $component = Livewire::actingAs($this->userWithRole('HR-SYR'))->test(BasicSettings::class);

        foreach (BasicSettings::TABS as $tab => [$module]) {
            $component->call('setTab', $tab)->assertSet('tab', $tab);

            $this->assertStringContainsString('wire:name="'.$module.'"', $component->html());
        }
    }

    public function test_legacy_pages_redirect_to_their_tab(): void
    {
        $user = $this->userWithRole('HR-SYR');

        foreach (self::LEGACY as $route => $tab) {
            $this->actingAs($user)->get(route($route))
                ->assertRedirect(route('basic-settings.index', ['tab' => $tab]));
        }
    }

    public function test_a_role_without_access_to_the_reference_pages_is_refused(): void
    {
        $this->actingAs($this->userWithRole('employee'))->get(route('basic-settings.index'))->assertForbidden();
        $this->actingAs($this->userWithRole('employee'))->get(route('cities.index'))->assertForbidden();
    }

    public function test_holidays_tab_is_for_national_hr_only(): void
    {
        $alp = Livewire::actingAs($this->userWithRole('HR-ALP'))->test(BasicSettings::class);
        $this->assertArrayNotHasKey('holidays', $alp->instance()->tabs());
        $alp->set('tab', 'holidays')->assertSet('tab', 'cities');

        $syr = Livewire::actingAs($this->userWithRole('HR-SYR'))->test(BasicSettings::class);
        $this->assertArrayHasKey('holidays', $syr->instance()->tabs());
    }

    private function userWithRole(string $roleName): User
    {
        $city = City::query()->where('slug', 'ALP')->first()
            ?? City::query()->forceCreate(['slug' => 'ALP', 'name_en' => 'Aleppo', 'name_ar' => 'حلب', 'code' => '001', 'is_active' => true]);
        $employee = Employee::query()->create([
            'city_id' => $city->id,
            'first_name_en' => 'Set', 'first_name_ar' => 'اعداد',
            'last_name_en' => 'Tester', 'last_name_ar' => 'اختبار',
            'username' => uniqid('set.', true),
            'is_active' => true,
        ]);
        $user = User::query()->create([
            'employee_id' => $employee->id,
            'email' => uniqid('set-', true).'@example.test',
            'password' => 'password',
        ]);
        $role = Role::findOrCreate($roleName, 'web');
        $user->assignRole($role);
        $user->setActiveRole($role->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}
