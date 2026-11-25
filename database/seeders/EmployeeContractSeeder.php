<?php

namespace Database\Seeders;

use App\Models\Contract;
use App\Models\Employee;
use App\Models\Position;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class EmployeeContractSeeder extends Seeder
{
    public function run(): void
    {
        $contracts = [
            ['employee' => 'sami.haddad', 'position' => 'Chief Executive Officer', 'start_date' => '2018-01-01', 'end_date' => '2090-12-31'],
            ['employee' => 'nabil.khoury', 'position' => 'Deputy Chief Executive Officer', 'start_date' => '2018-01-01', 'end_date' => '2090-12-31'],
            ['employee' => 'rana.saad', 'position' => 'IT Manager', 'start_date' => '2018-01-01', 'end_date' => '2090-12-31'],
            ['employee' => 'tarek.nassar', 'position' => 'Communication Manager', 'start_date' => '2018-01-01', 'end_date' => '2090-12-31'],
            // One-year contracts are dated back from today, so they are still
            // running whenever the database is seeded. Fixed dates had all
            // expired, and sign-in (OrgRoleSync) then stood down every seeded
            // account's role but the permanent ones.
            ['employee' => 'maya.azar', 'position' => 'HR Generalist', 'months_ago' => 9],
            ['employee' => 'omar.hilal', 'position' => 'Legal Affairs Consultant', 'months_ago' => 8],
            ['employee' => 'dina.sabbagh', 'position' => 'Second Deputy Chief Executive Officer', 'months_ago' => 2],
            ['employee' => 'hala.mansour', 'position' => 'Center Keeper', 'months_ago' => 0],
            ['employee' => 'rami.toma', 'position' => 'Senior Financial Manager', 'months_ago' => 9],
            ['employee' => 'fares.kassab', 'position' => 'Program Manager / Micro Projects', 'months_ago' => 1],
        ];

        foreach ($contracts as $data) {
            $employee = Employee::where('username', $data['employee'])->first();
            $position = Position::where('title_en', $data['position'])->first();

            if (! $employee || ! $position) {
                $this->command?->warn("No contract for {$data['employee']}: employee or position '{$data['position']}' not found.");

                continue;
            }

            Contract::create([
                'employee_id' => $employee->id,
                'position_id' => $position->id,
                'contract_type_id' => 1, // تأكد من وجود نوع عقد بالمعرف 1
                'start_date' => $start = isset($data['months_ago'])
                    ? now()->subMonths($data['months_ago'])->startOfMonth()
                    : Carbon::parse($data['start_date']),
                'end_date' => isset($data['end_date']) ? Carbon::parse($data['end_date']) : $start->copy()->addYear()->subDay(),
                'terminated_at' => null,
                'reason_of_termination' => null,
                'salary' => 1000000.00, // مثال، يمكن تعديله حسب الحاجة
                'currency' => 1, // 1 = SYP
                'salary_per' => 1, // 1 = شهري
                'weekly_hours' => 40,
                'is_active' => true,
            ]);
        }
    }
}
