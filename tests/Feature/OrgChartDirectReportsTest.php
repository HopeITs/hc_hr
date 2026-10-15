<?php

namespace Tests\Feature;

use App\Livewire\Position\OrgChart;
use App\Livewire\Position\ProfileDrawer;
use App\Models\City;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * The "Direct Reports" badge counts people, not positions. One position can
 * seat several employees, so counting positions under-reported every shared
 * role (a manager over one 5-seat position read "1 Direct Reports").
 */
class OrgChartDirectReportsTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    private City $city;

    private User $hr;

    private Position $manager;

    private Position $teamLead;

    private Employee $managerHolder;

    /** @var list<Employee> */
    private array $officers = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Id 1 is the main city the chart always keeps in scope.
        $this->city = $this->makeCity('SYR', '000');
        $this->hr = $this->makeUser($this->city, 'HR-SYR', ['positions.view', 'employees.view']);

        $ceo = $this->position('Chief Executive Officer', grade: 1, manager: null);
        $this->seat($ceo, 'ceo');

        // Level 3, rendered by <x-org-manager-column>.
        $this->manager = $this->position('Programs Manager', grade: 2, manager: $ceo);
        $this->managerHolder = $this->seat($this->manager, 'manager');

        // Reports of the manager: 1 + 3 people, one vacancy, one inactive position.
        $this->teamLead = $this->position('Team Lead', grade: 3, manager: $this->manager);
        $this->seat($this->teamLead, 'lead');

        $officers = $this->position('Field Officer', grade: 4, manager: $this->manager, maxEmp: 3);
        foreach (['officer-a', 'officer-b', 'officer-c'] as $username) {
            $this->officers[] = $this->seat($officers, $username);
        }

        $this->position('Driver', grade: 4, manager: $this->manager);

        $retired = $this->position('Retired Role', grade: 4, manager: $this->manager, active: false);
        $this->seat($retired, 'retired');

        // Reports of the team lead (nested, rendered by <x-org-section>): three
        // assistants, one of whom also holds a second position under the lead.
        $assistants = $this->position('Assistant', grade: 5, manager: $this->teamLead, maxEmp: 3);
        $twoHats = $this->seat($assistants, 'assistant-a');
        $this->seat($assistants, 'assistant-b');
        $this->seat($assistants, 'assistant-c');

        $clerk = $this->position('Clerk', grade: 5, manager: $this->teamLead);
        $this->seat($clerk, $twoHats);
    }

    public function test_headcount_counts_distinct_people_in_active_positions(): void
    {
        $team = $this->manager->directTeam()->with('activeContracts')->get();

        // Team Lead (1) + Field Officer (3) + Driver (vacant, 0); the inactive
        // position's holder is not a report.
        $this->assertSame(4, Position::headcount($team));

        $leadTeam = $this->teamLead->directTeam()->with('activeContracts')->get();

        // Three assistants; the one also seated as Clerk counts once.
        $this->assertSame(2, $leadTeam->count());
        $this->assertSame(3, Position::headcount($leadTeam));
    }

    public function test_the_org_chart_badges_show_people_not_positions(): void
    {
        Livewire::actingAs($this->hr)
            ->test(OrgChart::class)
            ->assertSeeHtml('<span>4 '.__('general.direct-reports').'</span>')
            ->assertSeeHtml('<span>3 '.__('general.direct-reports').'</span>')
            ->assertDontSeeHtml('<span>2 '.__('general.direct-reports').'</span>');
    }

    public function test_the_profile_drawer_lists_every_holder_of_a_shared_position(): void
    {
        $drawer = Livewire::actingAs($this->hr)
            ->test(ProfileDrawer::class)
            ->call('openProfile', $this->managerHolder->id);

        foreach ($this->officers as $officer) {
            $drawer->assertSee($officer->first_name_en);
        }

        $drawer->assertSee(__('general.vacant-position'))
            ->assertDontSee('Retired Role');
    }

    private function position(string $title, int $grade, ?Position $manager, int $maxEmp = 1, bool $active = true): Position
    {
        $jobLevel = JobLevel::query()->firstOrCreate(
            ['grade' => $grade],
            ['name_en' => 'Grade '.$grade, 'name_ar' => 'درجة '.$grade],
        );

        return $this->makePosition($this->city, [
            'title_en' => $title,
            'title_ar' => $title,
            'job_level_id' => $jobLevel->id,
            'direct_manager_id' => $manager?->id,
            'max_emp' => $maxEmp,
            'is_active' => $active,
        ]);
    }

    private function seat(Position $position, string|Employee $employee): Employee
    {
        if (is_string($employee)) {
            // Unique in both locales: the fixture's shared Arabic name would let
            // a name assertion match any holder.
            $employee = $this->makeEmployee($this->city, $employee, [
                'first_name_en' => ucfirst($employee),
                'first_name_ar' => ucfirst($employee),
            ]);
        }

        Contract::query()->create([
            'employee_id' => $employee->id,
            'position_id' => $position->id,
            'contract_type_id' => ContractType::query()->firstOrCreate(
                ['name_en' => 'Volunteer Contract'],
                ['name_ar' => 'عقد تطوّع'],
            )->id,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'currency' => 2,
            'salary_per' => 1,
            'salary' => 1500,
            'weekly_hours' => 40,
            'is_active' => true,
        ]);

        return $employee;
    }
}
