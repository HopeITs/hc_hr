<?php

namespace Tests\Feature;

use App\Livewire\Contract\ContractCreate;
use App\Models\Center;
use App\Models\City;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\JobLevel;
use App\Models\Position;
use App\Models\Program;
use App\Services\ContractAmendmentDescriber;
use App\Services\ContractAmendmentService;
use App\Services\ContractPdfRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * Study Zone's optional "Friday is considered a day off…" sentence: the day
 * comes from the weekend of the centers the position is shared by in its
 * city, since a Study Zone position has no center of its own.
 */
class ContractWeekendClauseTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    private City $city;

    private Program $studyZone;

    private Position $position;

    protected function setUp(): void
    {
        parent::setUp();

        $this->city = $this->makeCity('LAT', '005');
        $this->studyZone = Program::query()->forceCreate([
            'id' => Program::STUDY_ZONE_ID, 'name' => 'Study Zone', 'slug' => 'SZ', 'code' => 'SZ', 'is_active' => true,
        ]);

        $this->position = Position::query()->forceCreate([
            'center_id' => null,
            'city_id' => $this->city->id,
            'department_id' => Department::query()->forceCreate(['name' => 'Programs', 'code' => 'PRG'])->id,
            'job_level_id' => JobLevel::query()->forceCreate(['name_en' => 'Officer', 'name_ar' => 'مسؤول', 'grade' => 2])->id,
            'program_id' => $this->studyZone->id,
            'title_en' => 'Project Team Member',
            'title_ar' => 'عضو فريق المشروع',
            'max_emp' => 16,
            'exist_emp' => 0,
            'is_active' => true,
        ]);

        ContractType::query()->forceCreate(['id' => 1, 'name_en' => 'Volunteer Contract', 'name_ar' => 'عقد تطوّع']);
    }

    public function test_ticked_it_names_the_linked_centers_weekend(): void
    {
        $this->linkCenter('Study Zone Ziraa 1', '5');
        $this->linkCenter('Study Zone Ziraa 2', '5');

        $html = $this->render($this->makeContract(true));

        $this->assertStringContainsString('Friday is considered a day off, unless management deems it necessary, and is not considered overtime.', $html);
        $this->assertStringContainsString('يعتبر يوم الجمعة يوم عطلة', $html);
    }

    public function test_unticked_it_prints_nothing(): void
    {
        $this->linkCenter('Study Zone Ziraa 1', '5');

        $this->assertStringNotContainsString('day off', $this->render($this->makeContract(false)));
    }

    public function test_without_a_center_weekend_it_prints_nothing_rather_than_a_guess(): void
    {
        $this->assertStringNotContainsString('day off', $this->render($this->makeContract(true)));
    }

    public function test_two_day_weekend_reads_in_the_plural(): void
    {
        $this->linkCenter('Study Zone Al-Haram', '5,6');

        $html = $this->render($this->makeContract(true));

        $this->assertStringContainsString('Friday and Saturday are considered days off', $html);
        $this->assertStringContainsString('يعتبر يوما الجمعة والسبت يومي عطلة', $html);
    }

    public function test_location_options_hand_the_composer_the_weekend_of_study_zone_positions_only(): void
    {
        $this->linkCenter('Study Zone Ziraa 1', '5');

        $other = Position::query()->forceCreate([
            ...$this->position->only(['city_id', 'department_id', 'job_level_id', 'max_emp', 'exist_emp', 'is_active']),
            'center_id' => $this->linkCenter('Hope Center', '0,6', link: false)->id,
            'program_id' => Program::query()->forceCreate(['name' => 'Core', 'slug' => 'core', 'code' => 'CORE', 'is_active' => true])->id,
            'title_en' => 'Officer',
            'title_ar' => 'مسؤول',
        ]);

        Livewire::actingAs($this->makeUser($this->city, 'HR-LAT', ['contracts.create']))
            ->test(ContractCreate::class)
            ->call('locationOptions', $this->city->id)
            ->assertReturned(function (array $options) use ($other): bool {
                $positions = collect($options['positions']);

                return $positions->firstWhere('value', $this->position->id)['weekend'] === [5]
                    && $positions->firstWhere('value', $other->id)['weekend'] === null;
            });
    }

    public function test_an_amendment_can_add_the_clause_and_its_sheet_states_it(): void
    {
        $this->linkCenter('Study Zone Ziraa 1', '5');
        $contract = $this->makeContract(false, inForce: true)->fresh();

        $amendment = app(ContractAmendmentService::class)
            ->draft($contract, ['show_weekend_clause' => true], today()->toDateString(), null, null);

        $this->assertSame(['show_weekend_clause'], $amendment->changedFields());
        $this->assertSame(
            [[__('contracts.amendment.weekend-clause'), __('contracts.amendment.weekend-clause-off'), __('contracts.amendment.weekend-clause-on')]],
            app(ContractAmendmentDescriber::class)->lines($amendment, withPay: false),
        );
        $this->assertStringContainsString(
            'Friday is considered a day off',
            app(ContractPdfRenderer::class)->amendmentView($amendment)->render(),
        );

        app(ContractAmendmentService::class)->approve($amendment);

        $this->assertTrue($contract->fresh()->show_weekend_clause);
    }

    /** Drafted before the clause was amendable: its snapshots hold no such key. */
    public function test_an_amendment_recorded_before_the_clause_existed_still_matches_its_contract(): void
    {
        $contract = $this->makeContract(true, inForce: true)->fresh();
        $snapshot = ContractAmendment::snapshotOf($contract);
        unset($snapshot['show_weekend_clause']);

        $amendment = ContractAmendment::query()->create([
            'contract_id' => $contract->id,
            'number' => 1,
            'status' => 1,
            'effective_from' => today()->toDateString(),
            'terms_before' => $snapshot,
            'terms_after' => [...$snapshot, 'weekly_hours' => 30],
        ]);

        $this->assertTrue($amendment->matchesContract($contract));
        $this->assertSame(['weekly_hours'], $amendment->changedFields());
    }

    private function linkCenter(string $name, string $weekends, bool $link = true): Center
    {
        $center = Center::query()->create([
            'name_en' => $name, 'name_ar' => $name, 'city_id' => $this->city->id, 'is_active' => true, 'weekends' => $weekends,
        ]);

        if ($link) {
            $center->programs()->attach($this->studyZone);
        }

        return $center;
    }

    private function makeContract(bool $showWeekendClause, bool $inForce = false): Contract
    {
        return Contract::query()->forceCreate([
            'employee_id' => $this->makeEmployee($this->city, 'weekend-clause-'.uniqid())->id,
            'position_id' => $this->position->id,
            'contract_type_id' => 1,
            'type' => 1,
            'place' => 1,
            'is_active' => true,
            'start_date' => $inForce ? today()->subMonth()->toDateString() : '2026-12-01',
            'end_date' => $inForce ? today()->addMonths(2)->toDateString() : '2026-12-31',
            'pay_mode' => 1,
            'currency' => 1,
            'salary_per' => 1,
            'show_weekend_clause' => $showWeekendClause,
        ]);
    }

    private function render(Contract $contract): string
    {
        return view('components.contract.weekend-clause', ['contract' => $contract, 'forPdf' => false])->render();
    }
}
