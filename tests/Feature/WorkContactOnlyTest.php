<?php

namespace Tests\Feature;

use App\Livewire\Position\ProfileDrawer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * Contact details shown to colleagues are the work ones — the work phone and
 * the account (organization) email — never the employee's personal phone or
 * personal email.
 */
class WorkContactOnlyTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    public function test_work_email_is_the_account_login(): void
    {
        $aleppo = $this->makeCity('ALP', '001');
        $employee = $this->makeEmployee($aleppo, 'rami', ['personal_email' => 'rami.home@gmail.com']);

        $this->assertNull($employee->work_email);

        User::factory()->create(['employee_id' => $employee->id, 'email' => 'rami@hopecenter.org']);

        $this->assertSame('rami@hopecenter.org', $employee->fresh()->work_email);
    }

    public function test_the_profile_drawer_shows_only_work_contact(): void
    {
        $aleppo = $this->makeCity('ALP', '001');
        $hr = $this->makeUser($aleppo, 'HR-ALP', ['employees.view']);
        $employee = $this->makeEmployee($aleppo, 'rami', [
            'work_phone' => '0911111111',
            'personal_phone' => '0922222222',
            'personal_email' => 'rami.home@gmail.com',
        ]);
        User::factory()->create(['employee_id' => $employee->id, 'email' => 'rami@hopecenter.org']);

        Livewire::actingAs($hr)
            ->test(ProfileDrawer::class)
            ->call('openProfile', $employee->id)
            ->assertSee('0911111111')
            ->assertSee('rami@hopecenter.org')
            ->assertDontSee('0922222222')
            ->assertDontSee('rami.home@gmail.com');
    }
}
