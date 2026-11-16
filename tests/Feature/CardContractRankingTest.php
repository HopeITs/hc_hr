<?php

namespace Tests\Feature;

use App\Models\ContractType;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\Program;
use App\Services\DigitalIdManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesDigitalIdCards;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * Which of an employee's current contracts the ID card shows: the one whose
 * position has the highest job level, a fixed contract before a trial one on
 * the same level, then the newest. A volunteer who takes a new position in
 * another project keeps one card; the program in its link follows the winner.
 */
class CardContractRankingTest extends TestCase
{
    use CreatesDigitalIdCards, CreatesHrFixtures, RefreshDatabase;

    public function test_the_higher_job_level_wins_even_when_the_other_contract_is_newer(): void
    {
        $city = $this->makeCity('ALP', '001');
        $employee = $this->makeEmployee($city, 'abdelmassih');
        $manager = JobLevel::query()->forceCreate(['name_en' => 'Manager', 'name_ar' => 'مدير', 'grade' => 1]);

        // Officer on Micro Project, newest; Manager on Study Zone, older.
        $this->giveCurrentPosition($employee->fresh(), '011', ['start_date' => now()->subDay()->toDateString()]);
        $this->giveManagerPosition($employee, '022', $manager);

        $this->assertSame('022', $this->cardProgramCode($employee));
    }

    public function test_a_fixed_contract_beats_a_trial_one_on_the_same_level(): void
    {
        $city = $this->makeCity('ALP', '001');
        $employee = $this->makeEmployee($city, 'rami');
        $trial = ContractType::query()->forceCreate(['id' => 3, 'name_en' => 'Trial', 'name_ar' => 'تجريبي']);

        // The trial contract starts later, so only the type can put the other first.
        $this->giveCurrentPosition($employee->fresh(), '011', ['start_date' => now()->subMonths(2)->toDateString()]);
        $this->giveCurrentPosition($employee->fresh(), '022', ['contract_type_id' => $trial->id, 'start_date' => now()->subDay()->toDateString()]);

        $this->assertSame('011', $this->cardProgramCode($employee));
    }

    public function test_the_newest_contract_wins_when_level_and_type_are_equal(): void
    {
        $city = $this->makeCity('ALP', '001');
        $employee = $this->makeEmployee($city, 'maya');

        $this->giveCurrentPosition($employee->fresh(), '011', ['start_date' => now()->subMonths(3)->toDateString()]);
        $this->giveCurrentPosition($employee->fresh(), '022', ['start_date' => now()->subMonth()->toDateString()]);

        $this->assertSame('022', $this->cardProgramCode($employee));
    }

    private function giveManagerPosition(Employee $employee, string $programCode, JobLevel $level): void
    {
        $program = Program::query()->firstOrCreate(['code' => $programCode], [
            'name' => 'Program '.$programCode, 'slug' => 'program-'.$programCode.'-'.uniqid(), 'is_active' => true,
        ]);
        $position = $this->makePosition($employee->city, ['program_id' => $program->id, 'job_level_id' => $level->id]);

        $this->makeContractFor($employee, [
            'position_id' => $position->id,
            'start_date' => now()->subMonths(2)->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
        ]);
    }

    /** The program code the card shows, read from the card's own contract. */
    private function cardProgramCode(Employee $employee): ?string
    {
        $manager = app(DigitalIdManager::class);

        return $manager->programCode($employee->fresh());
    }
}
