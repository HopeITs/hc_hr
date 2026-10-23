<?php

namespace Tests\Feature;

use App\Livewire\Contract\ContractCreate;
use App\Livewire\Contract\ContractEdit;
use App\Models\Center;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\JobLevel;
use App\Models\Position;
use App\Models\Program;
use App\Services\LeaveBalanceService;
use Carbon\Carbon;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

class ContractCreatePerformanceTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    public function test_city_options_are_compact_scoped_and_query_bounded(): void
    {
        $fixtures = $this->makeFixtures();
        $queries = [];

        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $component = Livewire::actingAs($fixtures['hr'])
            ->test(ContractCreate::class);

        $queries = [];

        $component
            ->call('locationOptions', $fixtures['city']->id)
            ->assertReturned(function (array $options) use ($fixtures): bool {
                $employeeIds = collect($options['employees'])->pluck('value');
                $positionIds = collect($options['positions'])->pluck('value');

                return $employeeIds->contains($fixtures['employee']->id)
                    && ! $employeeIds->contains($fixtures['remoteEmployee']->id)
                    && $positionIds->contains($fixtures['position']->id)
                    && $positionIds->contains($fixtures['centerScopedPosition']->id)
                    && collect($options['programs'])->pluck('value')->contains($fixtures['program']->id)
                    && collect($options['centers'])->pluck('value')->contains($fixtures['center']->id);
            });

        // 12 was the budget before the composer. It buys two more, both constant
        // and neither an N+1: one eager-load of the avatar media collection, and
        // one lookup of active contracts so duplicates can be warned about.
        $this->assertLessThanOrEqual(14, count($queries), 'The renderless city option request exceeded its query budget.');

        $employeeQuery = collect($queries)->first(fn (string $sql): bool => str_contains($sql, 'employees'));

        $this->assertNotNull($employeeQuery);
        $this->assertStringNotContainsString('select *', strtolower($employeeQuery));
    }

    public function test_the_composer_type_cards_carry_the_dates_the_form_would_apply(): void
    {
        $fixtures = $this->makeFixtures();

        $trialStart = Carbon::now()->addMonthNoOverflow()->startOfMonth();

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractCreate::class)
            ->assertViewHas('types', function ($types) use ($fixtures, $trialStart): bool {
                $card = collect($types)->firstWhere('id', $fixtures['trialType']->id);

                return $card !== null
                    && $card->category === 'trial'
                    && $card->startDate === $trialStart->toDateString()
                    && $card->endDate === $trialStart->copy()->addMonthsNoOverflow(3)->toDateString();
            })
            // Probing the form for each card must not leave its own dates behind.
            ->assertSet('form.start_date', null)
            ->assertSet('form.end_date', null);
    }

    public function test_the_composer_renders_all_three_sections_and_locks_the_single_city(): void
    {
        $fixtures = $this->makeFixtures();

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractCreate::class)
            // The three sections and their chrome — Assignment now also
            // carries what used to be its own separate Contract section.
            ->assertSeeHtml('composer-section-assignment')
            ->assertSeeHtml('composer-section-schedule')
            ->assertSeeHtml('composer-section-pay')
            // The cascade fields (the center field was removed in dbbeb4c0).
            ->assertSeeHtml('id="composer_employee"')
            ->assertSeeHtml('id="composer_program"')
            ->assertSeeHtml('id="composer_position"')
            // A city-scoped role gets a locked chip, never a dropdown of one.
            ->assertSee(__('general.composer.locked-city'))
            ->assertDontSeeHtml('id="composer_city"')
            // Termination has no place on a contract that does not exist yet.
            // The properties still ride along in the Livewire snapshot as nulls;
            // what must be gone is the whole section and every control in it.
            ->assertDontSeeHtml('composer-section-termination')
            ->assertDontSeeHtml('id="composer_terminated_at"')
            ->assertDontSeeHtml('id="composer_reason_of_termination"')
            // The contract card and its entitlement row.
            ->assertSee(__('general.composer.annual-leave'))
            ->assertSee(__('general.composer.capacity'));
    }

    public function test_dates_submitted_by_the_user_win_over_the_contract_type_defaults(): void
    {
        $fixtures = $this->makeFixtures();

        Notification::fake();
        $this->mock(LeaveBalanceService::class)->shouldReceive('create')->once();

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractCreate::class)
            ->call('submit', $this->payload($fixtures, [
                'startDate' => '2026-03-01',
                'endDate' => '2026-09-30',
            ]))
            ->assertHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('contracts', [
            'employee_id' => $fixtures['employee']->id,
            'start_date' => '2026-03-01 00:00:00',
            'end_date' => '2026-09-30 00:00:00',
        ]);
    }

    public function test_tampered_city_and_center_selections_are_still_rejected_server_side(): void
    {
        $fixtures = $this->makeFixtures();

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractCreate::class)
            ->call('locationOptions', $fixtures['remoteCity']->id)
            ->assertStatus(403);

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractCreate::class)
            ->call('submit', $this->payload($fixtures, [
                'centerId' => $fixtures['otherCenter']->id,
            ]))
            ->assertHasErrors('form.position_id');

        $this->assertDatabaseCount('contracts', 0);
    }

    public function test_a_full_position_is_refused_even_when_the_client_submits_it(): void
    {
        $fixtures = $this->makeFixtures();

        $fixtures['position']->forceFill(['exist_emp' => $fixtures['position']->max_emp])->save();

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractCreate::class)
            ->call('submit', $this->payload($fixtures))
            ->assertHasErrors('form.position_id');

        $this->assertDatabaseCount('contracts', 0);
    }

    public function test_the_leave_preview_is_scoped_to_the_callers_city(): void
    {
        $fixtures = $this->makeFixtures();

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractCreate::class)
            ->call('leavePreview', [
                'employeeId' => $fixtures['remoteEmployee']->id,
                'contractTypeId' => $fixtures['trialType']->id,
                'startDate' => '2026-01-01',
                'endDate' => '2026-12-31',
                'weeklyHours' => 40,
            ])
            ->assertReturned(null);
    }

    /**
     * Syria is the headquarters scope, not a city beside Aleppo and Homs, so a
     * contract issued against it may be held by an employee from anywhere.
     */
    public function test_the_nationwide_city_offers_employees_from_every_city(): void
    {
        $fixtures = $this->makeFixtures();
        $syria = $this->makeCity('SYR', '000');

        // Both roles that can reach Syria: headquarters HR, and the nationwide
        // roles above it. Nobody lives in Syria itself, so an unexempted query
        // returns nothing at all — which is exactly how this used to fail.
        $nationwide = [
            $this->makeUser($syria, 'HR-SYR', ['contracts.create']),
            $this->makeUser($fixtures['city'], 'general-manager', ['contracts.create']),
        ];

        foreach ($nationwide as $user) {
            Livewire::actingAs($user)
                ->test(ContractCreate::class)
                ->call('locationOptions', $syria->id)
                ->assertReturned(function (array $options) use ($fixtures): bool {
                    $employeeIds = collect($options['employees'])->pluck('value');

                    // Neither of these lives in Syria, and both are offered.
                    return $employeeIds->contains($fixtures['employee']->id)
                        && $employeeIds->contains($fixtures['remoteEmployee']->id);
                });
        }
    }

    /**
     * A nationwide role is not pinned to its own profile city.
     *
     * Which cities a user may touch is decided once, from activeCityIds(). This
     * method used to narrow it a second time for any non-HR role, so a
     * general-manager was offered every position in a city and no employees at
     * all — the screen read "Nothing matches" rather than refusing outright.
     */
    public function test_a_nationwide_role_is_not_pinned_to_its_own_profile_city(): void
    {
        $fixtures = $this->makeFixtures();

        // Profile sits in the remote city; the contract is being written in the
        // other one, which a nationwide role is entitled to do.
        $manager = $this->makeUser($fixtures['remoteCity'], 'general-manager', ['contracts.create']);

        Livewire::actingAs($manager)
            ->test(ContractCreate::class)
            ->call('locationOptions', $fixtures['city']->id)
            ->assertReturned(fn (array $options): bool => collect($options['employees'])
                ->pluck('value')
                ->contains($fixtures['employee']->id));
    }

    /** A city-scoped role still cannot reach past its own city at all. */
    public function test_a_city_scoped_role_is_still_refused_another_city(): void
    {
        $fixtures = $this->makeFixtures();

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractCreate::class)
            ->call('locationOptions', $fixtures['remoteCity']->id)
            ->assertStatus(403);
    }

    /** Every other city still shows only its own people. */
    public function test_an_ordinary_city_still_offers_only_its_own_employees(): void
    {
        $fixtures = $this->makeFixtures();

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractCreate::class)
            ->call('locationOptions', $fixtures['city']->id)
            ->assertReturned(function (array $options) use ($fixtures): bool {
                $employeeIds = collect($options['employees'])->pluck('value');

                return $employeeIds->contains($fixtures['employee']->id)
                    && ! $employeeIds->contains($fixtures['remoteEmployee']->id);
            });
    }

    public function test_edit_uses_the_same_fast_options_and_opens_on_the_contracts_own_dates(): void
    {
        $fixtures = $this->makeFixtures();
        $contract = $this->makeExistingContract($fixtures);

        $fixtures['employee']->update(['is_active' => false]);

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractEdit::class, ['contract' => $contract])
            // A deactivated employee is still offered, because this contract
            // already belongs to them.
            ->call('locationOptions', $fixtures['city']->id)
            ->assertReturned(fn (array $options): bool => collect($options['employees'])
                ->pluck('value')
                ->contains($fixtures['employee']->id))
            // Probing the type cards must not move the dates the screen opens on.
            ->assertSet('form.start_date', $contract->start_date->toDateString())
            ->assertSet('form.end_date', $contract->end_date->toDateString());
    }

    public function test_the_edit_screen_is_the_same_composer_plus_termination(): void
    {
        $fixtures = $this->makeFixtures();
        $contract = $this->makeExistingContract($fixtures);

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractEdit::class, ['contract' => $contract])
            // The three sections the create screen has, unchanged...
            ->assertSeeHtml('composer-section-assignment')
            ->assertSeeHtml('composer-section-schedule')
            ->assertSeeHtml('composer-section-pay')
            ->assertSeeHtml('id="composer_employee"')
            ->assertSeeHtml('id="composer_position"')
            ->assertSee(__('general.composer.annual-leave'))
            // ...plus the one only an existing contract can have.
            ->assertSeeHtml('composer-section-termination')
            ->assertSeeHtml('id="composer_terminated_at"')
            ->assertSeeHtml('id="composer_reason_of_termination"')
            // Whose contract this is, and the term it runs for, are stated once
            // — in the card — rather than repeated in a banner above the form.
            ->assertSeeHtml(sprintf(
                '%s: %s — %s',
                __('general.current-period'),
                $contract->start_date->format('d-m-Y'),
                $contract->end_date->format('d-m-Y'),
            ))
            ->assertDontSee(__('general.renewing-contract-for', ['name' => '']));
    }

    public function test_the_edit_composer_saves_everything_in_one_request(): void
    {
        $fixtures = $this->makeFixtures();
        $contract = $this->makeExistingContract($fixtures);

        $this->mock(LeaveBalanceService::class)->shouldReceive('create')->once();

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractEdit::class, ['contract' => $contract])
            ->call('submit', $this->payload($fixtures, [
                'salary' => 750,
                'startDate' => $contract->start_date->toDateString(),
                'endDate' => $contract->end_date->toDateString(),
            ]))
            ->assertHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('contracts', [
            'id' => $contract->id,
            'is_active' => true,
            'terminated_at' => null,
        ]);

        // Read through the model, not the column: salary is stored encrypted,
        // so the row holds a ciphertext envelope rather than the figure.
        $this->assertSame('750.00', $contract->fresh()->salary);
    }

    public function test_recording_a_termination_on_edit_deactivates_the_contract(): void
    {
        $fixtures = $this->makeFixtures();
        $contract = $this->makeExistingContract($fixtures);
        $terminatedAt = $contract->start_date->copy()->addMonth()->toDateString();

        $this->mock(LeaveBalanceService::class)->shouldReceive('create')->once();

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractEdit::class, ['contract' => $contract])
            ->call('submit', $this->payload($fixtures, [
                'startDate' => $contract->start_date->toDateString(),
                'endDate' => $contract->end_date->toDateString(),
                'terminatedAt' => $terminatedAt,
                'reasonOfTermination' => 'Resigned.',
            ]))
            ->assertHasNoErrors();

        $this->assertDatabaseHas('contracts', [
            'id' => $contract->id,
            'terminated_at' => $terminatedAt.' 00:00:00',
            'reason_of_termination' => 'Resigned.',
            'is_active' => false,
        ]);
    }

    public function test_the_composer_creates_the_complete_contract_in_one_request(): void
    {
        $fixtures = $this->makeFixtures();

        Notification::fake();
        $this->mock(LeaveBalanceService::class)
            ->shouldReceive('create')
            ->once()
            ->withArgs(fn (Contract $contract): bool => $contract->employee_id === $fixtures['employee']->id);

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractCreate::class)
            ->call('submit', $this->payload($fixtures, ['salary' => 500]))
            ->assertHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('contracts', [
            'employee_id' => $fixtures['employee']->id,
            'position_id' => $fixtures['position']->id,
            'contract_type_id' => $fixtures['trialType']->id,
            'weekly_hours' => 40,
            'is_active' => true,
            // Never set on create, whatever the client sends.
            'terminated_at' => null,
            'termination_reason_id' => null,
        ]);

        // Encrypted at rest — see the edit test above.
        $this->assertSame('500.00', Contract::latest('id')->first()->salary);
    }

    private function makeExistingContract(array $fixtures): Contract
    {
        return Contract::query()->forceCreate([
            'employee_id' => $fixtures['employee']->id,
            'position_id' => $fixtures['position']->id,
            'contract_type_id' => $fixtures['trialType']->id,
            'start_date' => now()->subMonth()->startOfMonth()->toDateString(),
            'end_date' => now()->addMonths(5)->endOfMonth()->toDateString(),
            'currency' => 1,
            'salary' => 100,
            'salary_per' => 1,
            'weekly_hours' => 40,
            'is_active' => true,
        ]);
    }

    /**
     * One complete, valid composer payload, exactly as the Alpine layer sends it.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $fixtures, array $overrides = []): array
    {
        return array_merge([
            'cityId' => (string) $fixtures['city']->id,
            'employeeId' => (string) $fixtures['employee']->id,
            'programId' => (string) $fixtures['program']->id,
            'centerId' => (string) $fixtures['center']->id,
            'positionId' => (string) $fixtures['position']->id,
            'contractTypeId' => (string) $fixtures['trialType']->id,
            'startDate' => Carbon::now()->addMonthNoOverflow()->startOfMonth()->toDateString(),
            'endDate' => Carbon::now()->addMonthNoOverflow()->startOfMonth()
                ->addMonthsNoOverflow(3)->toDateString(),
            'workHoursFrom' => '09:00',
            'workHoursTo' => '17:00',
            'weeklyHours' => 40,
            'salary' => 500,
            'currency' => 1,
            'salaryPer' => 1,
        ], $overrides);
    }

    private function makeFixtures(): array
    {
        $city = $this->makeCity('ALP', '001');
        $remoteCity = $this->makeCity('HMS', '002');
        $center = $this->makeCenter($city, 'Hope Center');
        $otherCenter = $this->makeCenter($city, 'Other Center');

        $department = Department::query()->forceCreate(['name' => 'Programs', 'code' => 'PRG']);
        $jobLevel = JobLevel::query()->forceCreate([
            'name_en' => 'Officer',
            'name_ar' => 'مسؤول',
            'grade' => 2,
        ]);
        $program = Program::query()->forceCreate([
            'name' => 'Core Program',
            'slug' => 'core-program',
            'code' => 'CORE',
            'is_active' => true,
        ]);
        $trialType = ContractType::query()->forceCreate([
            'id' => 3,
            'name_en' => 'Trial',
            'name_ar' => 'تجريبي',
        ]);

        $position = $this->makePosition(
            $center,
            $city->id,
            $department,
            $jobLevel,
            $program,
            'Program Officer',
        );

        // Position::inCity() intentionally includes this row through its center,
        // even though the legacy direct city_id points elsewhere.
        $centerScopedPosition = $this->makePosition(
            $center,
            $remoteCity->id,
            $department,
            $jobLevel,
            $program,
            'Center Scoped Officer',
        );

        $employee = $this->makeEmployee($city, 'local-contract-employee');
        $remoteEmployee = $this->makeEmployee($remoteCity, 'remote-contract-employee');
        $hr = $this->makeUser($city, 'HR-ALP', ['contracts.create', 'contracts.update']);

        return compact(
            'city',
            'remoteCity',
            'center',
            'otherCenter',
            'program',
            'trialType',
            'position',
            'centerScopedPosition',
            'employee',
            'remoteEmployee',
            'hr',
        );
    }

    private function makeCenter($city, string $name): Center
    {
        return Center::query()->create([
            'name_en' => $name,
            'name_ar' => $name,
            'city_id' => $city->id,
            'is_active' => true,
            'weekends' => 'friday',
            'open_at' => '08:00:00',
            'close_at' => '16:00:00',
        ]);
    }

    private function makePosition(
        Center $center,
        int $cityId,
        Department $department,
        JobLevel $jobLevel,
        Program $program,
        string $title,
    ): Position {
        return Position::query()->forceCreate([
            'center_id' => $center->id,
            'city_id' => $cityId,
            'department_id' => $department->id,
            'job_level_id' => $jobLevel->id,
            'program_id' => $program->id,
            'title_en' => $title,
            'title_ar' => $title,
            'max_emp' => 2,
            'exist_emp' => 0,
            'is_active' => true,
        ]);
    }
}
