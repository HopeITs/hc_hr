<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        $this->call([
            ProgramSeeder::class,
            CitySeeder::class,
            ContractTypeSeeder::class,
            DepartmentSeeder::class,
            CenterSeeder::class,
            CheckListItemSeeder::class,
            LeaveTypeSeeder::class,
            AnnualLeaveRuleSeeder::class,
            RolesPermissionsSeeder::class,
            JobLevelSeeder::class,
            PositionsSeeder::class,
            EmployeeSeeder::class,
            UserRoleSeeder::class,
            EmployeeContractSeeder::class,
            EducationFieldSeeder::class,
            CourseCategorySeeder::class,
            TerminationReasonSeeder::class,
            RecruitmentWorkflowSeeder::class,
        ]);

        //    $user = User::factory()->create([
        //         // 'name' => ['en' => 'Test User'],
        //         'email' => 'test@example.com',
        //     ]);
    }
}
