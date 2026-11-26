<?php

namespace Database\Factories;

use App\Models\City;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    public function definition()
    {
        return [
            'first_name_en' => $this->faker->firstName,
            'first_name_ar' => 'اسم '.$this->faker->unique()->numberBetween(1, 1000),
            'last_name_en' => $this->faker->lastName,
            'last_name_ar' => 'عائلة '.$this->faker->unique()->numberBetween(1, 1000),
            'city_id' => City::query()->inRandomOrder()->value('id'),
            'username' => $this->faker->userName,
            'actual_dob' => $this->faker->date('Y-m-d', '-20 years'),
            'national_number' => $this->faker->unique()->numerify('###########'),
            'dob' => $this->faker->date('Y-m-d', '-20 years'),
            'civil_registry_office' => $this->faker->city,
            'civil_registry_number' => strtoupper($this->faker->bothify('###??')),
            'gender' => $this->faker->randomElement([0, 1, 2]),
            'father_name_en' => $this->faker->firstNameMale,
            'father_name_ar' => 'اسمأب'.$this->faker->unique()->numberBetween(1, 1000),
            'mother_name_en' => $this->faker->firstNameFemale,
            'mother_name_ar' => 'اسمأم'.$this->faker->unique()->numberBetween(1, 1000),
            'nationality' => 1,
            'marital_status' => $this->faker->randomElement([0, 1, 2]),
            'children' => $this->faker->numberBetween(0, 5),
            'work_phone' => $this->faker->numerify('09########'),
            'personal_phone' => $this->faker->numerify('09########'),
            'personal_email' => $this->faker->unique()->safeEmail,
            'address_en' => $this->faker->city,
            'address_ar' => 'مدينة'.$this->faker->unique()->numberBetween(1, 1000),
            'skills' => implode(', ', $this->faker->words(3)),
            'interests' => implode(', ', $this->faker->words(3)),
            // Deterministically active. This was faker->boolean(90), which made
            // roughly one in ten factory-built employees inactive at random —
            // so any test that signs a user in was silently flaky, and now that
            // EnsureAccountIsActive rejects inactive accounts it would fail
            // outright about a tenth of the time. Use the inactive() state when
            // a test actually wants a deactivated employee.
            'is_active' => true,
            // Deterministically off: board membership shows the board's pages in the menu and lists the person at its meetings.
            'is_board_member' => false,
            'board_member_started_at' => null,
            'board_member_end_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
