<?php

namespace Tests\Feature;

use App\Enums\BadgeType;
use App\Livewire\Employee\EmployeeProfile;
use App\Models\City;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * The Badges card on a profile follows the Badges page: BadgePolicy's
 * viewForEmployee, through Badge::scopeVisibleTo(). Owning the profile
 * grants nothing by itself.
 */
class EmployeeProfileBadgesVisibilityTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    private City $aleppo;

    private City $homs;

    private Employee $aleppoSubject;

    private Employee $homsSubject;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->aleppo = $this->makeCity('ALP', '001');
        $this->homs = $this->makeCity('HMS', '002');
        $this->aleppoSubject = $this->withBadge($this->makeEmployee($this->aleppo, 'subject.aleppo'), 'ALEPPO-BADGE');
        $this->homsSubject = $this->withBadge($this->makeEmployee($this->homs, 'subject.homs'), 'HOMS-BADGE');
    }

    public function test_city_hr_sees_badges_in_its_own_city_only(): void
    {
        $hr = $this->makeUser($this->aleppo, 'HR-ALP', ['badges.view']);

        $this->profileAs($hr, $this->aleppoSubject)
            ->assertSet('canViewBadges', true)
            ->assertSee('ALEPPO-BADGE');

        $this->profileAs($hr, $this->homsSubject)
            ->assertSet('canViewBadges', false)
            ->assertDontSee('HOMS-BADGE');
    }

    public function test_hr_syr_and_general_manager_see_badges_in_every_city(): void
    {
        foreach (['HR-SYR', 'general-manager'] as $role) {
            $this->profileAs($this->makeUser($this->aleppo, $role), $this->homsSubject)
                ->assertSet('canViewBadges', true)
                ->assertSee('HOMS-BADGE');
        }
    }

    /**
     * D1 (profile-contract.md Q2 = C, HR 2026-10-27): the owner now sees their
     * own rewards and warnings on their profile; still nobody else's without
     * badges.view, and never a salary increase or a note.
     */
    public function test_manager_without_the_permission_sees_only_their_own_badges(): void
    {
        $managerEmployee = $this->withBadge($this->makeEmployee($this->aleppo, 'manager.self'), 'OWN-BADGE');
        $managerEmployee->badges()->create(['type' => BadgeType::Salary_Increase, 'date' => now(), 'reason' => 'OWN-RAISE', 'new_salary' => 987654, 'notes' => 'HR-NOTE']);
        $manager = $this->makeUser($this->aleppo, 'manager', [], $managerEmployee);

        $this->profileAs($manager, $managerEmployee)
            ->assertSet('isSelf', true)
            ->assertSet('canViewBadges', false)
            ->assertSee('OWN-BADGE')
            ->assertDontSee('OWN-RAISE')
            ->assertDontSee('987654')
            ->assertDontSee('HR-NOTE');

        $this->profileAs($manager, $this->aleppoSubject)
            ->assertSet('canViewBadges', false)
            ->assertDontSee('ALEPPO-BADGE');
    }

    public function test_manager_granted_badges_view_sees_only_their_reports(): void
    {
        $managerEmployee = $this->makeEmployee($this->aleppo, 'manager.granted');
        $managerPosition = $this->makePosition($this->aleppo);
        $this->makeContractFor($managerEmployee, [
            'position_id' => $managerPosition->id,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
        ]);
        $techReport = $this->withBadge($this->makeEmployee($this->homs, 'manager.tech'), 'REPORT-BADGE');
        $this->makeContractFor($techReport, [
            'position_id' => $this->reportingTo($managerPosition, $this->homs)->id,
        ]);

        $manager = $this->makeUser($this->aleppo, 'manager', ['badges.view'], $managerEmployee);

        $this->profileAs($manager, $techReport)
            ->assertSet('canViewBadges', true)
            ->assertSee('REPORT-BADGE');

        $this->profileAs($manager, $this->aleppoSubject)
            ->assertSet('canViewBadges', false)
            ->assertDontSee('ALEPPO-BADGE');
    }

    /** D1 (Q2 = C): the employee sees their own warning on their own profile. */
    public function test_employee_sees_their_own_badges_on_their_own_profile(): void
    {
        $this->profileAs($this->makeUser($this->aleppo, 'employee', [], $this->aleppoSubject), $this->aleppoSubject)
            ->assertSet('canViewBadges', false)
            ->assertSee('ALEPPO-BADGE');
    }

    private function profileAs(User $user, Employee $subject)
    {
        return Livewire::actingAs($user)->test(EmployeeProfile::class, ['employee' => $subject]);
    }

    private function reportingTo(Position $managerPosition, City $city): Position
    {
        // Direct reports only, like Team contracts (G-C-8).
        return $this->makePosition($city, ['direct_manager_id' => $managerPosition->id]);
    }

    private function withBadge(Employee $employee, string $reason): Employee
    {
        $employee->badges()->create([
            'type' => BadgeType::Warning,
            'date' => now(),
            'reason' => $reason,
        ]);

        return $employee;
    }
}
