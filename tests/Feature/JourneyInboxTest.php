<?php

namespace Tests\Feature;

use App\Livewire\Inbox\Index as Inbox;
use App\Models\City;
use App\Models\Employee;
use App\Models\OnboardingJourney;
use App\Models\User;
use App\Services\Inbox\JourneyInbox;
use App\Services\WorkInbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * DEC-09 increment 2: unfinished onboarding steps that wait on HR show in
 * HR's work inbox, with the Late tag (docs/ux/journey-inbox-implementation.md).
 * Synthetic data only.
 */
class JourneyInboxTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    private City $homs;

    private City $aleppo;

    private User $hrHoms;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->travelTo(now()->setTime(10, 0));

        $this->homs = $this->makeCity('HMS', '002');
        $this->aleppo = $this->makeCity('ALP', '001');
        $this->hrHoms = $this->makeUser($this->homs, 'HR-HMS', ['employees.view', 'employees.update']);
    }

    public function test_hr_of_the_city_sees_the_item_with_the_late_tag_when_overdue(): void
    {
        $late = $this->journey($this->homs, ['orientation_due_on' => now()->subDays(2)->toDateString()]);
        $onTime = $this->journey($this->homs, ['orientation_due_on' => now()->addDays(5)->toDateString()]);

        $rows = app(WorkInbox::class)->rows($this->hrHoms)->where('action', 'journey')->keyBy('key');

        $this->assertEqualsCanonicalizing(
            ["onboarding:{$late->id}:journey", "onboarding:{$onTime->id}:journey"],
            $rows->keys()->all(),
        );

        $lateRow = $rows["onboarding:{$late->id}:journey"];
        $this->assertTrue($lateRow['late']);
        $this->assertSame(['card', 'orientation-schedule'], array_column($lateRow['steps'], 'key'));
        $this->assertSame(now()->subDays(2)->toDateString(), $lateRow['due']->toDateString());
        $this->assertSame(route('employees.edit', $late->employee_id), $lateRow['url']);
        $this->assertFalse($rows["onboarding:{$onTime->id}:journey"]['late']);

        Livewire::actingAs($this->hrHoms)->test(Inbox::class)
            ->assertSee(__('journey_inbox.onboarding.title'))
            ->assertSee(__('journey_inbox.onboarding.step.card'))
            ->assertSee(route('employees.edit', $late->employee_id), false)
            ->assertSeeHtml('data-testid="inbox-journey-late"')
            ->call('setFilter', 'journey')
            ->assertSet('filter', 'journey')
            ->assertViewHas('rows', fn ($rows) => $rows->count() === 2);

        // Only the overdue one carries the tag.
        Livewire::actingAs($this->hrHoms)->test(Inbox::class)
            ->assertViewHas('rows', fn ($rows) => $rows->where('late', true)->pluck('id')->all() === [$late->id]);
    }

    public function test_a_scheduled_orientation_is_to_record_and_a_missing_account_is_a_problem(): void
    {
        $journey = $this->journey($this->homs, [
            'orientation_scheduled_for' => now()->subDay()->toDateString(),
            'card_handed_over_at' => now()->subDay(),
        ], withAccount: false);

        $row = app(WorkInbox::class)->rows($this->hrHoms)->firstWhere('key', "onboarding:{$journey->id}:journey");

        $this->assertNotNull($row);
        $this->assertSame([
            ['key' => 'account', 'state' => 'problem'],
            ['key' => 'orientation-record', 'state' => 'todo'],
        ], array_map(fn (array $step) => ['key' => $step['key'], 'state' => $step['state']], $row['steps']));

        // Only the account left: still listed, as a problem, with no due date.
        $journey->forceFill(['orientation_completed_at' => now()])->save();
        $row = app(WorkInbox::class)->rows($this->hrHoms)->firstWhere('key', "onboarding:{$journey->id}:journey");

        $this->assertSame(['account'], array_column($row['steps'], 'key'));
        $this->assertNull($row['due']);
        $this->assertFalse($row['late']);
    }

    public function test_hr_of_another_city_does_not_see_it(): void
    {
        $this->journey($this->homs);
        $hrAleppo = $this->makeUser($this->aleppo, 'HR-ALP', ['employees.view', 'employees.update']);

        $snapshot = app(WorkInbox::class)->snapshot($hrAleppo);

        $this->assertSame(0, $snapshot['counts']['journey']);
        $this->assertTrue($snapshot['rows']->where('action', 'journey')->isEmpty());

        // Their own city's journey is theirs.
        $this->journey($this->aleppo);
        $this->assertSame(1, app(WorkInbox::class)->snapshot($hrAleppo)['counts']['journey']);
    }

    public function test_users_without_employees_update_do_not_see_it(): void
    {
        $this->journey($this->homs);

        $viewers = [
            'employee' => $this->makeUser($this->homs, 'employee', []),
            'manager' => $this->makeUser($this->homs, 'manager', ['employees.view']),
            // HR for every city, but without the permission the edit page needs.
            'hr-without-update' => $this->makeUser($this->homs, 'HR-SYR', ['employees.view']),
            // employees.update outside an HR role: not an HR inbox (as S5 and D1).
            'manager-with-update' => $this->makeUser($this->homs, 'center-manager', ['employees.update']),
        ];

        foreach ($viewers as $who => $user) {
            $snapshot = app(WorkInbox::class)->snapshot($user);
            $this->assertSame(0, $snapshot['counts']['journey'], $who);
            $this->assertTrue($snapshot['rows']->where('action', 'journey')->isEmpty(), $who);
            $this->assertFalse(app(JourneyInbox::class)->hasSources($user), $who);
        }

        $this->assertTrue(app(JourneyInbox::class)->hasSources($this->hrHoms));
    }

    public function test_a_completed_journey_and_an_inactive_employee_disappear(): void
    {
        $journey = $this->journey($this->homs);
        $this->assertSame(1, app(WorkInbox::class)->snapshot($this->hrHoms)['counts']['journey']);

        $journey->forceFill([
            'card_handed_over_at' => now(),
            'orientation_scheduled_for' => now()->toDateString(),
            'orientation_completed_at' => now(),
            'completed_at' => now(),
        ])->save();

        $snapshot = app(WorkInbox::class)->snapshot($this->hrHoms);
        $this->assertSame(0, $snapshot['counts']['journey']);
        $this->assertTrue($snapshot['rows']->where('action', 'journey')->isEmpty());

        // Every step done but completed_at not settled yet (account created later): nothing waits.
        $journey->forceFill(['completed_at' => null])->save();
        $this->assertSame(0, app(WorkInbox::class)->snapshot($this->hrHoms)['counts']['journey']);

        // Former staff never show.
        $leaver = $this->journey($this->homs);
        Employee::query()->whereKey($leaver->employee_id)->update(['is_active' => false]);
        $this->assertSame(0, app(WorkInbox::class)->snapshot($this->hrHoms)['counts']['journey']);
    }

    public function test_counts_match_the_list(): void
    {
        $this->journey($this->homs);
        $this->journey($this->homs, ['orientation_due_on' => now()->subDay()->toDateString()]);
        $this->journey($this->homs, withAccount: false);
        $this->journey($this->aleppo);

        $snapshot = app(WorkInbox::class)->snapshot($this->hrHoms);
        $listed = $snapshot['rows']->where('action', 'journey');

        $this->assertSame(3, $snapshot['counts']['journey']);
        $this->assertCount($snapshot['counts']['journey'], $listed);
        $this->assertSame($snapshot['rows']->count(), $snapshot['counts']['all']);
        $this->assertSame(3, app(WorkInbox::class)->count($this->hrHoms));
    }

    public function test_the_queries_do_not_grow_with_the_journeys(): void
    {
        $this->journey($this->homs);
        $this->journey($this->homs, withAccount: false);

        $before = $this->queriesFor(fn () => app(WorkInbox::class)->snapshot($this->hrHoms));
        $this->assertSame(2, $before['result']['counts']['journey']);

        foreach (range(1, 5) as $i) {
            $this->journey($this->homs, withAccount: $i % 2 === 0);
        }

        $after = $this->queriesFor(fn () => app(WorkInbox::class)->snapshot($this->hrHoms));

        $this->assertSame(7, $after['result']['counts']['journey']);
        $this->assertSame($before['queries'], $after['queries']);
    }

    /** @return array{result: mixed, queries: int} */
    private function queriesFor(callable $callback): array
    {
        // Warm the user's memo (role, permissions, cities) so every pass starts equal.
        $callback();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $result = $callback();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return ['result' => $result, 'queries' => $queries];
    }

    /** An active volunteer of $city with a started journey (nothing done yet by default). */
    private function journey(City $city, array $attributes = [], bool $withAccount = true): OnboardingJourney
    {
        $employee = $this->makeEmployee($city, 'volunteer.'.(++$this->seq));

        if ($withAccount) {
            User::factory()->create(['employee_id' => $employee->id]);
        }

        $journey = new OnboardingJourney;
        $journey->forceFill(array_merge([
            'employee_id' => $employee->id,
            'started_at' => now()->subDays(3),
            'orientation_due_on' => now()->addDays(11)->toDateString(),
        ], $attributes))->save();

        return $journey;
    }
}
