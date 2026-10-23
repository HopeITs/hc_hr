<?php

namespace Tests\Feature;

use App\Enums\BadgeType;
use App\Livewire\Employee\EmployeeProfile;
use App\Models\Contract;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * The colleague-records tier (contracts without pay, education, experience,
 * skills, courses) follows docs/ux/access-policy.md §4.3 / A2D-Q2 as signed
 * off on 2026-10-20: the employee role sees it on their own profile only; a
 * manager on their direct reports only; the general manager and HR (in city
 * scope) everywhere. Badges follow BadgePolicy, which the employee role never
 * passes.
 */
class EmployeeProfileRecordsVisibilityTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    private const RECORD_MARKERS = [
        'OLD-CONTRACT-POSITION',
        'EDUCATION-MARKER',
        'EXPERIENCE-MARKER',
        'SKILL-MARKER',
        'COURSE-MARKER',
        'BADGE-MARKER',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_the_employee_role_does_not_see_the_record_cards_on_another_profile(): void
    {
        $city = $this->makeCity('ALP', '001');
        $subject = $this->makeSubjectWithRecords($city);

        $component = Livewire::actingAs($this->makeUser($city, 'employee'))
            ->test(EmployeeProfile::class, ['employee' => $subject])
            ->assertOk()
            ->assertSet('canViewRecords', false)
            ->assertSet('canViewBadges', false);

        $this->assertRecordCardsHidden($component);
    }

    /**
     * Expectation changed by the signed policy (access-policy.md §9, A2D-Q2:
     * "employee sees own"). It used to assert the employee role saw no record
     * cards even on their own profile. Badges stay hidden: BadgePolicy, not
     * profile ownership, decides those.
     */
    public function test_the_employee_role_sees_the_record_cards_on_their_own_profile_but_not_badges(): void
    {
        $city = $this->makeCity('ALP', '001');
        $subject = $this->makeSubjectWithRecords($city);

        $component = Livewire::actingAs($this->makeUser($city, 'employee', [], $subject))
            ->test(EmployeeProfile::class, ['employee' => $subject])
            ->assertOk()
            ->assertSet('isSelf', true)
            ->assertSet('canViewRecords', true)
            ->assertSet('canViewBadges', false)
            ->assertSee(__('general.career-history'))
            ->assertSee(__('general.courses'))
            ->assertDontSee('BADGE-MARKER');

        foreach (array_diff(self::RECORD_MARKERS, ['BADGE-MARKER']) as $marker) {
            $component->assertSee($marker);
        }
    }

    public function test_the_employee_role_still_sees_the_current_position_from_the_active_contract(): void
    {
        $city = $this->makeCity('ALP', '001');
        $subject = $this->makeSubjectWithRecords($city);

        Livewire::actingAs($this->makeUser($city, 'employee'))
            ->test(EmployeeProfile::class, ['employee' => $subject])
            ->assertViewHas('currentContract', fn (?Contract $contract) => $contract?->position?->title_en === 'CURRENT-POSITION')
            ->assertViewHas('contracts', fn ($contracts) => $contracts->count() === 1);
    }

    public function test_hr_still_sees_every_record_card(): void
    {
        $city = $this->makeCity('ALP', '001');
        $subject = $this->makeSubjectWithRecords($city);

        // badges.view: the live HR-ALP role holds it, the fixture role does not.
        $component = Livewire::actingAs($this->makeUser($city, 'HR-ALP', ['badges.view']))
            ->test(EmployeeProfile::class, ['employee' => $subject])
            ->assertOk()
            ->assertSet('canViewRecords', true)
            ->assertSet('canViewBadges', true)
            ->assertSee(__('general.career-history'))
            ->assertSee(__('general.education'))
            ->assertSee(__('general.experience'))
            ->assertSee(__('general.skills-and-interests'))
            ->assertSee(__('general.courses'))
            ->assertSee(__('general.badges'));

        foreach (self::RECORD_MARKERS as $marker) {
            $component->assertSee($marker);
        }
    }

    public function test_the_general_manager_still_sees_the_record_cards(): void
    {
        $city = $this->makeCity('ALP', '001');
        $subject = $this->makeSubjectWithRecords($city);

        Livewire::actingAs($this->makeUser($city, 'general-manager'))
            ->test(EmployeeProfile::class, ['employee' => $subject])
            ->assertSet('canViewRecords', true)
            ->assertSee('OLD-CONTRACT-POSITION')
            ->assertSee('EDUCATION-MARKER')
            ->assertSee('COURSE-MARKER');
    }

    /**
     * Expectation changed by the signed policy (access-policy.md §9, A2D-Q2
     * and the DEC-05 revision): a manager gets the records of their DIRECT
     * reports only. This used to assert every manager saw every profile's
     * records; now a manager who does not lead the subject gets the
     * Directory tier, and one who does still sees the records.
     */
    public function test_a_manager_sees_the_record_cards_of_direct_reports_only(): void
    {
        $city = $this->makeCity('ALP', '001');
        $subject = $this->makeSubjectWithRecords($city);

        $component = Livewire::actingAs($this->makeUser($city, 'manager'))
            ->test(EmployeeProfile::class, ['employee' => $subject])
            ->assertOk()
            ->assertSet('canViewRecords', false);
        $this->assertRecordCardsHidden($component);

        $managerEmployee = $this->makeEmployee($city, 'profile.manager.'.uniqid());
        $managerPosition = $this->makeContractFor($managerEmployee, [
            'start_date' => today()->subYear(),
            'end_date' => today()->addYear(),
        ])->position;
        $subject->allContracts()->where('is_active', true)->first()->position
            ->update(['direct_manager_id' => $managerPosition->id]);

        Livewire::actingAs($this->makeUser($city, 'manager', [], $managerEmployee))
            ->test(EmployeeProfile::class, ['employee' => $subject])
            ->assertSet('canViewRecords', true)
            ->assertSee('OLD-CONTRACT-POSITION')
            ->assertSee('EDUCATION-MARKER')
            ->assertSee('COURSE-MARKER');
    }

    /** An inactive employee role must not hide anything from someone signed in as HR. */
    public function test_the_rule_follows_the_active_role_only(): void
    {
        $city = $this->makeCity('ALP', '001');
        $subject = $this->makeSubjectWithRecords($city);
        $hr = $this->assignInactiveRole($this->makeUser($city, 'HR-ALP'), 'employee');

        Livewire::actingAs($hr)
            ->test(EmployeeProfile::class, ['employee' => $subject])
            ->assertSet('canViewRecords', true)
            ->assertSee('OLD-CONTRACT-POSITION');
    }

    public function test_the_employee_role_is_refused_the_contract_and_badge_routes(): void
    {
        $city = $this->makeCity('ALP', '001');
        $subject = $this->makeSubjectWithRecords($city);
        $contract = $subject->allContracts()->first();
        $user = $this->makeUser($city, 'employee', config('access-control.role_baselines.employee'), $subject);

        // Checked at the guard rather than over HTTP: under test the routes
        // register without their locale prefix and every request redirects.
        foreach (['contracts.index', 'contracts.show', 'contracts.export', 'badges.index'] as $name) {
            $permission = collect(Route::getRoutes()->getByName($name)->gatherMiddleware())
                ->first(fn ($middleware) => str_starts_with($middleware, 'active_permission:'));

            $this->assertNotNull($permission, "{$name} is not behind active_permission");
            $this->assertFalse(
                $user->hasActivePermission(substr($permission, strlen('active_permission:'))),
                "The employee role may open {$name}",
            );
        }

        $this->assertFalse(Gate::forUser($user)->allows('export', $contract));
    }

    private function assertRecordCardsHidden($component): void
    {
        $component
            ->assertDontSee(__('general.career-history'))
            ->assertDontSee(__('general.education'))
            ->assertDontSee(__('general.experience'))
            ->assertDontSee(__('general.skills-and-interests'))
            ->assertDontSee(__('general.courses'))
            ->assertDontSee(__('general.badges'));

        foreach (self::RECORD_MARKERS as $marker) {
            $component->assertDontSee($marker);
        }
    }

    private function makeSubjectWithRecords($city): Employee
    {
        $subject = $this->makeEmployee($city, 'profile.subject.'.uniqid());
        $subject->forceFill(['skills' => 'SKILL-MARKER'])->save();

        $this->makeContractFor($subject, [
            'start_date' => today()->subYears(3),
            'end_date' => today()->subYears(2),
            'is_active' => false,
        ])->position->update(['title_en' => 'OLD-CONTRACT-POSITION', 'title_ar' => 'OLD-CONTRACT-POSITION']);

        $this->makeContractFor($subject, [
            'start_date' => today()->subMonth(),
            'end_date' => today()->addMonths(6),
        ])->position->update(['title_en' => 'CURRENT-POSITION', 'title_ar' => 'CURRENT-POSITION']);

        $subject->educations()->create(['name' => 'EDUCATION-MARKER', 'specialization' => 'Accounting', 'degree' => 1, 'status' => 1]);
        $subject->experiences()->create(['job_title' => 'EXPERIENCE-MARKER', 'company' => 'Acme', 'start_date' => today()->subYears(5)]);
        $subject->courses()->create(['title' => 'COURSE-MARKER']);
        $subject->badges()->create([
            'type' => BadgeType::Salary_Increase,
            'currency' => 2,
            'date' => now(),
            'reason' => 'BADGE-MARKER',
        ]);

        return $subject->fresh();
    }
}
