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
            ['employee' => 'tarek.nassar', 'position' => 'Communication Manager / Syria', 'start_date' => '2018-01-01', 'end_date' => '2090-12-31'],
            ['employee' => 'maya.azar', 'position' => 'HR Generalist', 'start_date' => '2025-02-01'],
            ['employee' => 'omar.hilal', 'position' => 'Legal Affairs Consultant', 'start_date' => '2025-03-01'],
            ['employee' => 'dina.sabbagh', 'position' => 'Second Deputy Chief Executive Officer', 'start_date' => '2025-09-01'],
            ['employee' => 'hala.mansour', 'position' => 'Center Keeper', 'start_date' => '2025-11-16'],
            ['employee' => 'rami.toma', 'position' => 'Senior Financial Manager', 'start_date' => '2025-02-01'],
            ['employee' => 'fares.kassab', 'position' => 'Program Manager', 'start_date' => '2025-10-01'],
        ];

        foreach ($contracts as $data) {
            $employee = Employee::where('username', $data['employee'])->first();
            $position = Position::where('title_en', $data['position'])->first();

            if (! $employee || ! $position) {
                continue;
            }

            Contract::create([
                'employee_id' => $employee->id,
                'position_id' => $position->id,
                'contract_type_id' => 1, // تأكد من وجود نوع عقد بالمعرف 1
                'start_date' => Carbon::parse($data['start_date']),
                'end_date' => array_key_exists('end_date', $data) ? Carbon::parse($data['end_date']) : Carbon::parse($data['start_date'])->addYears(1),
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
