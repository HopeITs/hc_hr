<?php

namespace Tests\Concerns;

use App\Models\City;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\Program;
use App\Services\DigitalIdManager;

/**
 * Employees who hold a public digital-ID card.
 *
 * A card link needs the employee's city code and the program code of their
 * current position (access-policy.md §9 A2D-Q6), so a card holder is an
 * employee with a current contract on a position in a numerically coded
 * program. CreatesContractFixtures' default program is coded "CORE", which
 * deliberately yields no link.
 */
trait CreatesDigitalIdCards
{
    use CreatesContractFixtures;

    protected function makeCardHolder(City $city, string $username, string $programCode = '007', array $attributes = []): Employee
    {
        $employee = $this->makeEmployee($city, $username, $attributes);

        $this->giveCurrentPosition($employee, $programCode);

        return $employee->fresh();
    }

    /** A current (in-date, active) contract on a position in the program coded $programCode. */
    protected function giveCurrentPosition(Employee $employee, string $programCode = '007', array $contract = []): Contract
    {
        $program = Program::query()->firstOrCreate(['code' => $programCode], [
            'name' => 'Program '.$programCode,
            'slug' => 'program-'.$programCode.'-'.uniqid(),
            'is_active' => true,
        ]);

        $position = $this->makePosition($employee->city, ['program_id' => $program->id]);

        return $this->makeContractFor($employee, array_merge([
            'position_id' => $position->id,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
        ], $contract));
    }

    /** The path of the employee's current card, from their data as it is now. */
    protected function cardPath(Employee $employee): string
    {
        $code = app(DigitalIdManager::class)->cardCode($employee->fresh());

        $this->assertNotNull($code, 'The fixture employee has no card link.');

        return '/ID/'.$code;
    }
}
