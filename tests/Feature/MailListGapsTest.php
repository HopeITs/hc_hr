<?php

namespace Tests\Feature;

use App\Livewire\Position\OrgChart;
use App\Models\MailList;
use App\Models\MailListMember;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * Loose ends of the mail-list work: starters queued by the daily command, and the
 * "Email this group" button of the org chart.
 */
class MailListGapsTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    public function test_the_org_chart_offers_the_list_of_the_city_it_is_filtered_to(): void
    {
        $city = $this->makeCity('SYR', '000');
        $aleppo = $this->makeCity('ALP', '001');
        $hr = $this->makeUser($city, 'HR-SYR', ['positions.view', 'employees.view']);
        MailList::query()->create([
            'name' => 'Aleppo', 'address' => 'hope.aleppo@hcsyria.org', 'position' => 1,
            'rule_type' => 'custom', 'rule_city_id' => $aleppo->id,
        ]);

        // Any list can be chosen; the one for the filtered city is put first.
        $chart = Livewire::actingAs($hr)->test(OrgChart::class)
            ->assertSeeHtml('data-testid="mail-list-menu"')
            ->assertSeeHtml('href="mailto:hope.aleppo@hcsyria.org"')
            ->assertDontSeeHtml('data-testid="mail-list-suggested"')
            ->set('city_id', $aleppo->id)
            ->assertSeeHtml('data-testid="mail-list-suggested"');

        // A search narrows past what a list holds, so nothing is suggested (the menu stays).
        $chart->set('search', 'anything')->assertDontSeeHtml('data-testid="mail-list-suggested"')
            ->assertSeeHtml('data-testid="mail-list-menu"');
    }

    public function test_the_daily_command_queues_people_whose_contract_just_started_and_nobody_twice(): void
    {
        $city = $this->makeCity('ALP', '001');
        $team = MailList::query()->create(['name' => 'Team', 'address' => 'team@hcsyria.org', 'position' => 1, 'rule_type' => 'all_staff']);

        $recent = $this->staff($city, 'recent', CarbonImmutable::today()->subDay());
        $old = $this->staff($city, 'old', CarbonImmutable::today()->subYear());
        // The account was made before the list had this rule, so nothing was queued then.
        $team->members()->delete();

        $this->artisan('mail-lists:queue-starters')->assertSuccessful();
        $this->artisan('mail-lists:queue-starters')->assertSuccessful();

        $this->assertSame(['recent@hcsyria.org'], $team->members()->pluck('email')->all());
        $this->assertSame(MailListMember::PENDING, $team->members()->first()->status);
        $this->assertInstanceOf(User::class, $old);
        $this->assertInstanceOf(User::class, $recent);
    }

    public function test_a_list_opens_a_message_to_the_list_with_the_viewers_manager_in_cc(): void
    {
        $city = $this->makeCity('ALP', '001');
        $list = MailList::query()->create(['name' => 'Team', 'address' => 'team@hcsyria.org', 'position' => 1]);

        $managerPosition = $this->makePosition($city);
        $manager = $this->staffOn($city, 'boss', $managerPosition);
        $mine = $this->makePosition($city, ['direct_manager_id' => $managerPosition->id]);
        $me = $this->staffOn($city, 'me', $mine);
        $lone = $this->staffOn($city, 'lone', $this->makePosition($city));

        // To the list, Cc the manager; the '@' is left as it is.
        $this->actingAs($me);
        $this->assertSame('mailto:team@hcsyria.org?cc=boss@hcsyria.org', $list->mailto());

        // No manager: no Cc. A manager who is the list itself is not copied twice.
        $this->actingAs($lone);
        $this->assertSame('mailto:team@hcsyria.org', $list->mailto());
        $this->assertInstanceOf(User::class, $manager);
    }

    private function staffOn($city, string $key, $position): User
    {
        $employee = $this->makeEmployee($city, $key, ['first_name_en' => ucfirst($key)]);
        $this->makeContractFor($employee, ['position_id' => $position->id]);

        return User::factory()->create(['employee_id' => $employee->id, 'email' => $key.'@hcsyria.org']);
    }

    private function staff($city, string $key, CarbonImmutable $start): User
    {
        $employee = $this->makeEmployee($city, $key, ['first_name_en' => ucfirst($key)]);
        $this->makeContractFor($employee, ['start_date' => $start->toDateString(), 'end_date' => $start->addYears(2)->toDateString()]);

        return User::factory()->create(['employee_id' => $employee->id, 'email' => $key.'@hcsyria.org']);
    }
}
