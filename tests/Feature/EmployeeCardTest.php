<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/** The small card under a person's name: Directory tier, nothing more. */
class EmployeeCardTest extends TestCase
{
    use CreatesContractFixtures;
    use RefreshDatabase;

    public function test_a_colleague_gets_only_the_directory_fields(): void
    {
        $city = $this->makeCity('ALP', '001');
        $colleague = $this->makeEmployee($city, 'colleague');
        $viewer = $this->makeUser($city, 'employee', ['leaves.view-own']);

        $response = $this->actingAs($viewer)->getJson(route('employees.card', $colleague))->assertOk();

        $this->assertSame(['name', 'avatar', 'title', 'details'], array_keys($response->json()));
        $this->assertSame((string) $colleague->name, $response->json('name'));
    }

    public function test_someone_who_does_not_exist_answers_not_found_and_a_guest_gets_nothing(): void
    {
        $city = $this->makeCity('ALP', '001');
        $colleague = $this->makeEmployee($city, 'colleague');
        $viewer = $this->makeUser($city, 'employee', ['leaves.view-own']);

        $this->actingAs($viewer)->getJson(route('employees.card', 999999))->assertNotFound();

        auth()->logout();
        $this->getJson(route('employees.card', $colleague))->assertUnauthorized();
    }
}
