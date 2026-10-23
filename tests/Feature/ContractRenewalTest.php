<?php

namespace Tests\Feature;

use App\Enums\LeaveTypeCases;
use App\Livewire\Contract\ContractCreate;
use App\Livewire\Contract\ContractDetails;
use App\Livewire\Contract\ContractRenewModal;
use App\Livewire\Contract\ContractsTable;
use App\Models\AnnualLeaveRule;
use App\Models\Center;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\JobLevel;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\Program;
use App\Models\TerminationReason;
use App\Services\LeaveBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use ReflectionProperty;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

class ContractRenewalTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    // ────────────────────────────────────────────────
    // Renewal window
    // ────────────────────────────────────────────────

    public function test_the_renewal_window_starts_the_day_after_the_source_ends_and_keeps_its_length(): void
    {
        $fixtures = $this->makeFixtures();

        $annual = $this->makeContract($fixtures, ['start_date' => '2026-01-01', 'end_date' => '2026-12-31']);

        $this->assertSame(['2027-01-01', '2027-12-31'], $annual->renewalWindow());
    }

    public function test_the_renewal_window_reproduces_a_three_month_trial_period(): void
    {
        $fixtures = $this->makeFixtures();

        $trial = $this->makeContract($fixtures, ['start_date' => '2026-01-01', 'end_date' => '2026-03-31']);

        $this->assertSame(['2026-04-01', '2026-06-30'], $trial->renewalWindow());
    }

    public function test_the_renewal_window_never_exceeds_one_year(): void
    {
        $fixtures = $this->makeFixtures();

        // An 18-month source contract must still produce a window the terms
        // validator will accept (end_date <= start_date + 1 year).
        $long = $this->makeContract($fixtures, ['start_date' => '2025-01-01', 'end_date' => '2026-06-30']);

        $this->assertSame(['2026-07-01', '2027-06-30'], $long->renewalWindow());
    }

    // ────────────────────────────────────────────────
    // Renew as is
    // ────────────────────────────────────────────────

    /** A5-M: a changed period is guarded; closing never creates a contract. */
    public function test_closing_the_renew_dialog_after_changing_the_period_asks_first(): void
    {
        $fixtures = $this->makeFixtures();
        $source = $this->makeContract($fixtures);

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractRenewModal::class)
            ->call('open', $source->id)
            ->call('requestClose', 'contractRenewModal')
            ->assertSet('show', false)
            ->assertNotDispatched('confirm-dialog')
            ->call('open', $source->id)
            ->set('form.end_date', '2027-06-30')
            ->call('requestClose', 'contractRenewModal')
            ->assertSet('show', true)
            ->assertSet('form.end_date', '2027-06-30')
            ->assertDispatched('confirm-dialog', method: 'discardChanges', payload: 'contractRenewModal')
            ->call('discardChanges', 'contractRenewModal')
            ->assertSet('show', false)
            ->assertSet('contractId', null)
            ->call('open', $source->id)
            ->assertSet('form.end_date', '2027-12-31');

        $this->assertSame(1, Contract::query()->count());
    }

    public function test_renew_as_is_clones_every_field_except_the_dates(): void
    {
        $fixtures = $this->makeFixtures();
        $source = $this->makeContract($fixtures);

        $this->ignoreLeaveBalances();

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractRenewModal::class)
            ->call('open', $source->id)
            ->assertSet('form.start_date', '2027-01-01')
            ->assertSet('form.end_date', '2027-12-31')
            ->call('renewAsIs')
            ->assertHasNoErrors()
            ->assertRedirect();

        $renewal = Contract::query()->where('id', '!=', $source->id)->sole();

        $this->assertSame('2027-01-01', $renewal->start_date->toDateString());
        $this->assertSame('2027-12-31', $renewal->end_date->toDateString());

        foreach ([
            'employee_id', 'position_id', 'contract_type_id', 'weekly_hours',
            'currency', 'salary', 'salary_per', 'work_days_en', 'work_days_ar',
            'work_hours_from', 'work_hours_to',
        ] as $attribute) {
            $this->assertSame(
                $source->getAttribute($attribute),
                $renewal->getAttribute($attribute),
                "The renewal did not carry [{$attribute}] over from the source contract.",
            );
        }

        $this->assertTrue($renewal->is_active);
        $this->assertNull($renewal->terminated_at);
    }

    public function test_renew_as_is_honours_dates_edited_in_the_modal(): void
    {
        $fixtures = $this->makeFixtures();
        $source = $this->makeContract($fixtures);

        $this->ignoreLeaveBalances();

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractRenewModal::class)
            ->call('open', $source->id)
            ->set('form.start_date', '2027-02-01')
            ->set('form.end_date', '2027-07-31')
            ->call('renewAsIs')
            ->assertHasNoErrors();

        $renewal = Contract::query()->where('id', '!=', $source->id)->sole();

        $this->assertSame('2027-02-01', $renewal->start_date->toDateString());
        $this->assertSame('2027-07-31', $renewal->end_date->toDateString());
    }

    public function test_renew_as_is_succeeds_on_a_position_that_is_already_full(): void
    {
        $fixtures = $this->makeFixtures();
        $source = $this->makeContract($fixtures);

        // The source contract occupies the position's only seat — the exact
        // situation every real renewal starts from.
        $fixtures['position']->update(['max_emp' => 1, 'exist_emp' => 1]);

        $this->ignoreLeaveBalances();

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractRenewModal::class)
            ->call('open', $source->id)
            ->call('renewAsIs')
            ->assertHasNoErrors();

        $this->assertSame(2, Contract::query()->count());
    }

    public function test_a_plain_create_on_a_full_position_is_still_rejected(): void
    {
        $fixtures = $this->makeFixtures();
        $fixtures['position']->update(['max_emp' => 1, 'exist_emp' => 1]);

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractCreate::class)
            ->call('submit', [
                'cityId' => $fixtures['city']->id,
                'employeeId' => $fixtures['employee']->id,
                'programId' => $fixtures['program']->id,
                'centerId' => $fixtures['center']->id,
                'positionId' => $fixtures['position']->id,
                'contractTypeId' => $fixtures['annualType']->id,
            ])
            ->assertHasErrors('form.position_id');

        $this->assertSame(0, Contract::query()->count());
    }

    public function test_renew_as_is_generates_leave_balances_for_the_new_contract(): void
    {
        $fixtures = $this->makeFixtures();
        $source = $this->makeContract($fixtures);

        $this->mock(LeaveBalanceService::class)
            ->shouldReceive('create')
            ->once()
            ->withArgs(fn (Contract $contract): bool => $contract->id !== $source->id
                && $contract->employee_id === $source->employee_id);

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractRenewModal::class)
            ->call('open', $source->id)
            ->call('renewAsIs')
            ->assertHasNoErrors();
    }

    public function test_renew_as_is_leaves_the_source_contract_untouched(): void
    {
        $fixtures = $this->makeFixtures();
        $source = $this->makeContract($fixtures);

        // Snapshot from the database, not from the in-memory model: the two
        // hydrate differently, and only a database-to-database comparison
        // proves nothing was written (updated_at included).
        $before = $source->fresh()->getAttributes();

        $this->ignoreLeaveBalances();

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractRenewModal::class)
            ->call('open', $source->id)
            ->call('renewAsIs')
            ->assertHasNoErrors();

        $this->assertSame($before, $source->fresh()->getAttributes());
    }

    public function test_a_renewal_longer_than_one_year_is_rejected(): void
    {
        $fixtures = $this->makeFixtures();
        $source = $this->makeContract($fixtures);

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractRenewModal::class)
            ->call('open', $source->id)
            ->set('form.start_date', '2027-01-01')
            ->set('form.end_date', '2028-06-30')
            ->call('renewAsIs')
            ->assertHasErrors('form.end_date');

        $this->assertSame(1, Contract::query()->count());
    }

    public function test_an_end_date_before_the_start_date_is_rejected(): void
    {
        $fixtures = $this->makeFixtures();
        $source = $this->makeContract($fixtures);

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractRenewModal::class)
            ->call('open', $source->id)
            ->set('form.start_date', '2027-06-01')
            ->set('form.end_date', '2027-01-01')
            ->call('renewAsIs')
            ->assertHasErrors('form.end_date');

        $this->assertSame(1, Contract::query()->count());
    }

    // ────────────────────────────────────────────────
    // Renew with edit
    // ────────────────────────────────────────────────

    public function test_renew_with_edit_prefills_the_wizard_from_the_source_contract(): void
    {
        $fixtures = $this->makeFixtures();
        $source = $this->makeContract($fixtures);

        Livewire::actingAs($fixtures['hr'])
            ->withQueryParams(['start' => '2027-03-01', 'end' => '2027-08-31'])
            ->test(ContractCreate::class, ['contract' => $source])
            ->assertSet('form.employee_id', $source->employee_id)
            ->assertSet('form.position_id', $source->position_id)
            ->assertSet('form.contract_type_id', $source->contract_type_id)
            ->assertSet('form.salary', $source->salary)
            ->assertSet('form.weekly_hours', $source->weekly_hours)
            ->assertSet('form.start_date', '2027-03-01')
            ->assertSet('form.end_date', '2027-08-31')
            ->assertSet('city_id', $fixtures['city']->id)
            ->assertSet('program_id', $fixtures['program']->id)
            ->assertSet('center_id', $fixtures['center']->id)
            ->assertSet('form.renewal_source_id', $source->id);
    }

    public function test_renew_with_edit_falls_back_to_the_default_window_without_query_dates(): void
    {
        $fixtures = $this->makeFixtures();
        $source = $this->makeContract($fixtures);

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractCreate::class, ['contract' => $source])
            ->assertSet('form.start_date', '2027-01-01')
            ->assertSet('form.end_date', '2027-12-31');
    }

    public function test_renew_with_edit_saves_a_new_contract_on_a_full_position(): void
    {
        $fixtures = $this->makeFixtures();
        $source = $this->makeContract($fixtures);
        $fixtures['position']->update(['max_emp' => 1, 'exist_emp' => 1]);

        $this->ignoreLeaveBalances();

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractCreate::class, ['contract' => $source])
            ->call('submit', [
                'cityId' => $fixtures['city']->id,
                'employeeId' => $source->employee_id,
                'programId' => $fixtures['program']->id,
                'centerId' => $fixtures['center']->id,
                'positionId' => $source->position_id,
                'contractTypeId' => $source->contract_type_id,
                'startDate' => '2027-01-01',
                'endDate' => '2027-12-31',
                'workHoursFrom' => $source->work_hours_from,
                'workHoursTo' => $source->work_hours_to,
                'weeklyHours' => $source->weekly_hours,
                'salary' => 999,
                'currency' => $source->currency,
                'salaryPer' => $source->salary_per,
            ])
            ->assertHasNoErrors()
            ->assertRedirect();

        $renewal = Contract::query()->where('id', '!=', $source->id)->sole();

        $this->assertSame('999.00', $renewal->salary);
        $this->assertSame($source->position_id, $renewal->position_id);
        $this->assertSame('2027-01-01', $renewal->start_date->toDateString());
    }

    public function test_renewing_into_a_different_full_position_is_still_rejected(): void
    {
        $fixtures = $this->makeFixtures();
        $source = $this->makeContract($fixtures);

        // Moving to another position during a renewal IS new headcount, so the
        // capacity bypass must not apply to it.
        $fixtures['otherPosition']->update(['max_emp' => 1, 'exist_emp' => 1]);

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractCreate::class, ['contract' => $source])
            ->call('submit', [
                'cityId' => $fixtures['city']->id,
                'employeeId' => $fixtures['employee']->id,
                'programId' => $fixtures['program']->id,
                'centerId' => $fixtures['center']->id,
                'positionId' => $fixtures['otherPosition']->id,
                'contractTypeId' => $fixtures['annualType']->id,
            ])
            ->assertHasErrors('form.position_id');

        $this->assertSame(1, Contract::query()->count());
    }

    public function test_changing_the_contract_type_keeps_the_renewal_anchored_to_its_start_date(): void
    {
        $fixtures = $this->makeFixtures();
        $source = $this->makeContract($fixtures);

        // The composer previews each type's dates client-side from the
        // typeDefaults handed down at render time; switching to the trial
        // type must shorten the term to three months from the renewal's own
        // start date, never "start of next month".
        $rendered = Livewire::actingAs($fixtures['hr'])
            ->test(ContractCreate::class, ['contract' => $source])
            ->html();

        // @js() encodes with JSON_HEX_QUOT, so every double quote in the
        // embedded JSON — including the ones around these keys — comes out
        // as the escape sequence below rather than a literal ".
        $escapedQuote = '\\u0022';
        $this->assertStringContainsString("{$escapedQuote}start{$escapedQuote}:{$escapedQuote}2027-01-01{$escapedQuote}", $rendered);
        $this->assertStringContainsString("{$escapedQuote}end{$escapedQuote}:{$escapedQuote}2027-04-01{$escapedQuote}", $rendered);
    }

    // ────────────────────────────────────────────────
    // Authorization
    // ────────────────────────────────────────────────

    public function test_a_user_from_another_city_cannot_open_the_renew_modal(): void
    {
        $fixtures = $this->makeFixtures();
        $source = $this->makeContract($fixtures);

        $outsider = $this->makeUser($fixtures['remoteCity'], 'HR-HMS', ['contracts.create', 'contracts.view']);

        Livewire::actingAs($outsider)
            ->test(ContractRenewModal::class)
            ->call('open', $source->id)
            ->assertStatus(403);
    }

    public function test_a_user_from_another_city_cannot_open_the_renew_wizard(): void
    {
        $fixtures = $this->makeFixtures();
        $source = $this->makeContract($fixtures);

        $outsider = $this->makeUser($fixtures['remoteCity'], 'HR-HMS', ['contracts.create', 'contracts.view']);

        Livewire::actingAs($outsider)
            ->test(ContractCreate::class, ['contract' => $source])
            ->assertStatus(403);
    }

    public function test_a_user_without_the_create_permission_cannot_renew(): void
    {
        $fixtures = $this->makeFixtures();
        $source = $this->makeContract($fixtures);

        $viewer = $this->makeUser($fixtures['city'], 'HR-ALP-viewer', ['contracts.view']);

        Livewire::actingAs($viewer)
            ->test(ContractRenewModal::class)
            ->call('open', $source->id)
            ->assertStatus(403);
    }

    public function test_a_terminated_contract_cannot_be_renewed(): void
    {
        $fixtures = $this->makeFixtures();
        $reason = TerminationReason::query()->forceCreate([
            'title_en' => 'Resignation',
            'title_ar' => 'استقالة',
        ]);

        $source = $this->makeContract($fixtures, [
            'terminated_at' => '2026-09-30',
            'termination_reason_id' => $reason->id,
        ]);

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractRenewModal::class)
            ->call('open', $source->id)
            ->assertStatus(403);
    }

    public function test_an_inactive_contract_cannot_be_renewed(): void
    {
        $fixtures = $this->makeFixtures();
        $source = $this->makeContract($fixtures, ['is_active' => false]);

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractRenewModal::class)
            ->call('open', $source->id)
            ->assertStatus(403);
    }

    public function test_renew_as_is_reauthorizes_the_contract_at_save_time(): void
    {
        $fixtures = $this->makeFixtures();
        $source = $this->makeContract($fixtures);

        $this->ignoreLeaveBalances();

        $component = Livewire::actingAs($fixtures['hr'])
            ->test(ContractRenewModal::class)
            ->call('open', $source->id);

        // The contract is terminated between opening the modal and confirming.
        $source->update(['terminated_at' => '2026-10-01']);

        $component->call('renewAsIs')->assertStatus(403);

        $this->assertSame(1, Contract::query()->count());
    }

    public function test_the_renewal_marker_cannot_be_set_from_the_browser(): void
    {
        $fixtures = $this->makeFixtures();
        $source = $this->makeContract($fixtures);
        $fixtures['position']->update(['max_emp' => 1, 'exist_emp' => 1]);

        // Every public Livewire property is writable by the client. If this one
        // were, an ordinary create page could be turned into a capacity bypass
        // by pointing it at any existing contract on the full position.
        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractCreate::class)
            ->set('form.renewal_source_id', $source->id);
    }

    public function test_the_renewal_source_cannot_be_swapped_from_the_browser(): void
    {
        $fixtures = $this->makeFixtures();
        $source = $this->makeContract($fixtures);

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractCreate::class, ['contract' => $source])
            ->set('renewalSource', null);
    }

    public function test_the_modal_target_contract_cannot_be_set_from_the_browser(): void
    {
        $fixtures = $this->makeFixtures();
        $source = $this->makeContract($fixtures);

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractRenewModal::class)
            ->set('contractId', $source->id);
    }

    // ────────────────────────────────────────────────
    // Entry points
    // ────────────────────────────────────────────────

    public function test_the_contracts_table_offers_renew_only_on_renewable_contracts(): void
    {
        $fixtures = $this->makeFixtures();
        $renewable = $this->makeContract($fixtures);
        $terminated = $this->makeContract($fixtures, [
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'terminated_at' => '2024-06-30',
        ]);

        $rendered = Livewire::actingAs($fixtures['hr'])
            ->test(ContractsTable::class)
            ->html();

        $this->assertStringContainsString("contractId: {$renewable->id}", $rendered);
        $this->assertStringNotContainsString("contractId: {$terminated->id}", $rendered);
    }

    public function test_the_renew_button_does_not_add_a_query_per_table_row(): void
    {
        $fixtures = $this->makeFixtures();
        $fixtures['position']->update(['max_emp' => 50]);

        for ($i = 0; $i < 10; $i++) {
            $this->makeContract($fixtures, [
                'employee_id' => $this->makeEmployee($fixtures['city'], "row-employee-{$i}")->id,
            ]);
        }

        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        Livewire::actingAs($fixtures['hr'])->test(ContractsTable::class)->html();

        // ContractPolicy::renew resolves city scope with an EXISTS against
        // employees. Calling the gate once per row would emit one of these per
        // contract on the page; the column must instead reuse the permission
        // resolved once in columns(), because query() has already constrained
        // every row to the active city.
        //
        // Scoped deliberately to the policy's own probe rather than a total
        // query budget: this table already carries an unrelated pre-existing
        // N+1, and a raw total would measure that instead of this feature.
        $policyProbes = collect($statements)
            ->filter(fn (string $sql): bool => str_starts_with(strtolower(trim($sql)), 'select exists')
                && str_contains($sql, 'employees'))
            ->count();

        $this->assertSame(
            0,
            $policyProbes,
            "The renew gate ran {$policyProbes} per-row city-scope queries; it should run none.",
        );
    }

    public function test_the_contract_details_page_renders_the_renew_button_and_modal(): void
    {
        $fixtures = $this->makeFixtures();
        $source = $this->makeContract($fixtures);

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractDetails::class, ['contract' => $source])
            ->assertOk()
            ->assertSeeHtml("Livewire.dispatch('open-renew-modal', { contractId: {$source->id} })");
    }

    public function test_the_contract_details_page_hides_renew_for_a_terminated_contract(): void
    {
        $fixtures = $this->makeFixtures();
        $source = $this->makeContract($fixtures, ['terminated_at' => '2026-06-30']);

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractDetails::class, ['contract' => $source])
            ->assertOk()
            ->assertDontSeeHtml('open-renew-modal');
    }

    public function test_the_modal_shows_the_employee_avatar(): void
    {
        $fixtures = $this->makeFixtures();
        $source = $this->makeContract($fixtures);

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractRenewModal::class)
            ->call('open', $source->id)
            ->assertOk()
            // No photo on file falls back to the shipped placeholder rather
            // than rendering a broken image.
            ->assertSeeHtml('images/default-avatar.png');
    }

    public function test_the_renew_wizard_banner_shows_the_employee_avatar(): void
    {
        $fixtures = $this->makeFixtures();
        $source = $this->makeContract($fixtures);

        $this->actingAs($fixtures['hr'])
            ->get(route('contracts.renew', $source))
            ->assertOk()
            ->assertSee('images/default-avatar.png', false);
    }

    public function test_the_renewal_surfaces_use_no_tinted_red(): void
    {
        $fixtures = $this->makeFixtures();
        $source = $this->makeContract($fixtures);

        $modal = Livewire::actingAs($fixtures['hr'])
            ->test(ContractRenewModal::class)
            ->call('open', $source->id)
            ->html();

        $wizard = $this->actingAs($fixtures['hr'])
            ->get(route('contracts.renew', $source))
            ->getContent();

        // resources/css/app.css: "a low-opacity #c2151c reads as pink, which is
        // not allowed in this UI". Opacity-modified brand utilities are the way
        // that creeps back in, so pin it on both renewal surfaces.
        foreach (['bg-primary/', 'border-primary/', 'text-primary/'] as $tinted) {
            $this->assertStringNotContainsString($tinted, $modal, "The modal uses {$tinted}.");
            $this->assertStringNotContainsString($tinted, $wizard, "The renew wizard uses {$tinted}.");
        }

        // Greys plus one red. A second red (Tailwind's red-500/600) next to
        // #c2151c reads as a mistake, so the shared label and error components
        // use the brand token instead.
        foreach (['text-red-', 'bg-red-', 'border-red-'] as $offBrand) {
            $this->assertStringNotContainsString($offBrand, $modal, "The modal uses {$offBrand}.");
        }
    }

    public function test_the_open_renew_modal_renders_the_source_contract_details(): void
    {
        $fixtures = $this->makeFixtures();
        $source = $this->makeContract($fixtures);

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractRenewModal::class)
            ->call('open', $source->id)
            ->assertOk()
            ->assertSee($fixtures['employee']->name)
            ->assertSee('Program Officer')
            ->assertSee(__('general.renew-as-is'))
            ->assertSee(__('general.renew-with-edit'));
    }

    public function test_the_renew_wizard_reauthorizes_the_source_at_save_time(): void
    {
        $fixtures = $this->makeFixtures();
        $source = $this->makeContract($fixtures);

        $this->ignoreLeaveBalances();

        $component = Livewire::actingAs($fixtures['hr'])
            ->test(ContractCreate::class, ['contract' => $source]);

        // Terminated after the wizard was opened but before it was submitted.
        $source->update(['terminated_at' => '2026-10-01']);

        $component->call('submit', [])->assertStatus(403);

        $this->assertSame(1, Contract::query()->count());
    }

    public function test_the_renew_wizard_refuses_to_save_when_the_source_was_deleted(): void
    {
        $fixtures = $this->makeFixtures();
        $source = $this->makeContract($fixtures);

        $this->ignoreLeaveBalances();

        $component = Livewire::actingAs($fixtures['hr'])
            ->test(ContractCreate::class, ['contract' => $source]);

        $source->delete();

        // Livewire re-resolves the `renewalSource` model property from the
        // database on every request, so a deleted source fails at hydration
        // (404) before submit() runs. Either way the guarantee is the same:
        // a contract that no longer exists cannot produce a successor.
        $component->call('submit', [])->assertNotFound();

        $this->assertSame(0, Contract::query()->count());
    }

    // ────────────────────────────────────────────────
    // Full HTTP stack: real routes, middleware and layout
    // ────────────────────────────────────────────────

    public function test_the_renew_page_loads_over_http(): void
    {
        $fixtures = $this->makeFixtures();
        $source = $this->makeContract($fixtures);

        $this->actingAs($fixtures['hr'])
            ->get(route('contracts.renew', $source))
            ->assertOk()
            ->assertSee(__('general.renew-contract'))
            ->assertSee($fixtures['employee']->name);
    }

    public function test_the_renew_page_applies_the_dates_from_the_query_string(): void
    {
        $fixtures = $this->makeFixtures();
        $source = $this->makeContract($fixtures);

        $this->actingAs($fixtures['hr'])
            ->get(route('contracts.renew', ['contract' => $source, 'start' => '2027-03-01', 'end' => '2027-08-31']))
            ->assertOk()
            ->assertSee('2027-03-01')
            ->assertSee('2027-08-31');
    }

    public function test_the_renew_page_ignores_a_malformed_query_string(): void
    {
        $fixtures = $this->makeFixtures();
        $source = $this->makeContract($fixtures);

        // The page has <script> tags of its own (Livewire, the layout), so the
        // probe is a payload that cannot occur there: it must never come back
        // unescaped, and the default window must be used instead.
        $payload = '<script>window.__a6Probe=1</script>';

        $this->actingAs($fixtures['hr'])
            ->get(route('contracts.renew', ['contract' => $source, 'start' => 'not-a-date', 'end' => $payload]))
            ->assertOk()
            ->assertSee('2027-01-01')
            ->assertDontSee($payload, false)
            ->assertDontSee('window.__a6Probe', false);
    }

    public function test_the_renew_page_survives_array_and_out_of_range_query_values(): void
    {
        $fixtures = $this->makeFixtures();
        $source = $this->makeContract($fixtures);

        $malformed = [
            // Array-shaped values (?start[]=...) must not reach a string cast.
            ['start' => ['2027-03-01'], 'end' => ['x' => '2027-08-31']],
            // Impossible calendar dates.
            ['start' => '2027-02-30', 'end' => '2027-13-01'],
            // End before start.
            ['start' => '2027-08-31', 'end' => '2027-03-01'],
            // Only one side present.
            ['start' => '2027-03-01'],
        ];

        foreach ($malformed as $query) {
            $this->actingAs($fixtures['hr'])
                ->get(route('contracts.renew', ['contract' => $source] + $query))
                ->assertOk()
                ->assertSee('2027-01-01');
        }

        // Nothing was created by merely opening the page.
        $this->assertSame(1, Contract::query()->count());
    }

    public function test_the_create_page_ignores_stray_renewal_query_values(): void
    {
        $fixtures = $this->makeFixtures();

        $this->actingAs($fixtures['hr'])
            ->get(route('contracts.create', ['start' => ['x'], 'end' => '<script>window.__a6Probe=1</script>']))
            ->assertOk()
            ->assertDontSee('window.__a6Probe', false);
    }

    public function test_the_plain_create_page_still_loads_over_http(): void
    {
        $fixtures = $this->makeFixtures();

        // Regression guard: mount() gained an optional route-model parameter,
        // and the parameterless route must keep resolving it as "no source".
        $this->actingAs($fixtures['hr'])
            ->get(route('contracts.create'))
            ->assertOk()
            ->assertSee(__('general.add-new'))
            ->assertDontSee(__('general.renew-contract'));
    }

    public function test_the_renew_route_is_blocked_without_the_create_permission(): void
    {
        $fixtures = $this->makeFixtures();
        $source = $this->makeContract($fixtures);

        $viewer = $this->makeUser($fixtures['city'], 'HR-ALP-reader', ['contracts.view']);

        $this->actingAs($viewer)
            ->get(route('contracts.renew', $source))
            ->assertForbidden();
    }

    public function test_the_renew_route_is_blocked_for_another_city(): void
    {
        $fixtures = $this->makeFixtures();
        $source = $this->makeContract($fixtures);

        $outsider = $this->makeUser($fixtures['remoteCity'], 'HR-HMS', ['contracts.create', 'contracts.view']);

        $this->actingAs($outsider)
            ->get(route('contracts.renew', $source))
            ->assertForbidden();
    }

    public function test_the_renew_route_is_blocked_for_a_terminated_contract(): void
    {
        $fixtures = $this->makeFixtures();
        $source = $this->makeContract($fixtures, ['terminated_at' => '2026-06-30']);

        $this->actingAs($fixtures['hr'])
            ->get(route('contracts.renew', $source))
            ->assertForbidden();
    }

    public function test_the_renew_page_escapes_the_employee_name(): void
    {
        $fixtures = $this->makeFixtures();
        $source = $this->makeContract($fixtures);

        $fixtures['employee']->update([
            'first_name_en' => '<script>alert(1)</script>',
            'last_name_en' => 'Payload',
        ]);

        $response = $this->actingAs($fixtures['hr'])
            ->get(route('contracts.renew', $source))
            ->assertOk();

        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_the_renew_page_renders_in_arabic(): void
    {
        $fixtures = $this->makeFixtures();
        $source = $this->makeContract($fixtures);

        app()->setLocale('ar');

        $this->actingAs($fixtures['hr'])
            ->get(route('contracts.renew', $source))
            ->assertOk()
            // A missing key would echo the raw "general.renew-contract" back.
            ->assertSee('تجديد العقد')
            ->assertDontSee('general.renew');
    }

    public function test_the_contracts_index_page_loads_with_the_renew_modal(): void
    {
        $fixtures = $this->makeFixtures();
        $source = $this->makeContract($fixtures);

        $this->actingAs($fixtures['hr'])
            ->get(route('contracts.index'))
            ->assertOk()
            ->assertSee("contractId: {$source->id}", false);
    }

    public function test_the_contracts_index_freezes_the_first_two_columns(): void
    {
        $fixtures = $this->makeFixtures();
        $this->makeContract($fixtures);

        // The freeze is CSS keyed off this wrapper class (see .table-frozen-2
        // in resources/css/app.css). Losing the wrapper silently unfreezes the
        // columns, with nothing else to catch it.
        $this->actingAs($fixtures['hr'])
            ->get(route('contracts.index'))
            ->assertOk()
            ->assertSee('table-frozen-2', false);
    }

    public function test_the_contract_details_page_loads_with_the_renew_button(): void
    {
        $fixtures = $this->makeFixtures();
        $source = $this->makeContract($fixtures);

        $this->actingAs($fixtures['hr'])
            ->get(route('contracts.show', $source))
            ->assertOk()
            ->assertSee("Livewire.dispatch('open-renew-modal', { contractId: {$source->id} })", false);
    }

    // ────────────────────────────────────────────────
    // Leave balances, end to end (service NOT mocked)
    // ────────────────────────────────────────────────

    public function test_renew_as_is_really_generates_the_leave_balances(): void
    {
        $fixtures = $this->makeFixtures();
        $this->seedLeaveConfiguration();
        $source = $this->makeContract($fixtures);

        // Deliberately no LeaveBalanceService mock here: every other renewal
        // test stubs it out, so this is the only one that proves the real
        // entitlement calculation runs and writes rows for the successor.
        Livewire::actingAs($fixtures['hr'])
            ->test(ContractRenewModal::class)
            ->call('open', $source->id)
            ->call('renewAsIs')
            ->assertHasNoErrors();

        $renewal = Contract::query()->where('id', '!=', $source->id)->sole();
        $balances = $renewal->leaveBalances()->pluck('deserved', 'type_id');

        $this->assertCount(4, $balances, 'Annual, both sick types and unpaid were expected.');

        // 12-month term, 35h week => 7h days. Annual = 14 days * 7h.
        $this->assertSame(98, (int) $balances[LeaveTypeCases::Annual]);
        // Sick and unpaid = 12 * daily hours.
        $this->assertSame(84, (int) $balances[LeaveTypeCases::FullSick]);
        $this->assertSame(84, (int) $balances[LeaveTypeCases::HalfSick]);
        $this->assertSame(84, (int) $balances[LeaveTypeCases::Unpaid]);

        // The source contract keeps its own balances; they are not moved.
        $this->assertSame(0, $source->leaveBalances()->count());
    }

    // ────────────────────────────────────────────────
    // ContractObserver: the web-request capacity path
    // ────────────────────────────────────────────────

    public function test_the_observer_blocks_a_non_renewal_create_on_a_full_position(): void
    {
        $fixtures = $this->makeFixtures();
        $this->makeContract($fixtures);
        $fixtures['position']->update(['max_emp' => 1, 'exist_emp' => 1]);

        $this->simulateWebRequest();

        $this->expectException(ValidationException::class);

        Contract::query()->create($this->contractAttributes($fixtures));
    }

    public function test_the_observer_allows_a_renewal_in_place_on_a_full_position(): void
    {
        $fixtures = $this->makeFixtures();
        $source = $this->makeContract($fixtures);
        $fixtures['position']->update(['max_emp' => 1, 'exist_emp' => 1]);

        $this->simulateWebRequest();

        $renewal = new Contract($this->contractAttributes($fixtures));
        $renewal->renewalSourceId = $source->id;
        $renewal->save();

        $this->assertTrue($renewal->exists);
        $this->assertSame(2, (int) $fixtures['position']->fresh()->exist_emp);
    }

    public function test_the_observer_still_blocks_a_renewal_onto_a_different_full_position(): void
    {
        $fixtures = $this->makeFixtures();
        $source = $this->makeContract($fixtures);
        $fixtures['otherPosition']->update(['max_emp' => 1, 'exist_emp' => 1]);

        $this->simulateWebRequest();

        $this->expectException(ValidationException::class);

        $renewal = new Contract($this->contractAttributes($fixtures, [
            'position_id' => $fixtures['otherPosition']->id,
        ]));
        $renewal->renewalSourceId = $source->id;
        $renewal->save();
    }

    public function test_renew_as_is_survives_the_observer_on_a_full_position(): void
    {
        $fixtures = $this->makeFixtures();
        $source = $this->makeContract($fixtures);
        $fixtures['position']->update(['max_emp' => 1, 'exist_emp' => 1]);

        $this->ignoreLeaveBalances();
        $this->simulateWebRequest();

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractRenewModal::class)
            ->call('open', $source->id)
            ->call('renewAsIs')
            ->assertHasNoErrors();

        $this->assertSame(2, Contract::query()->count());
    }

    // ────────────────────────────────────────────────
    // Position counter lifecycle
    // ────────────────────────────────────────────────

    public function test_the_position_counter_stays_consistent_across_a_renewal_lifecycle(): void
    {
        $fixtures = $this->makeFixtures();
        $position = $fixtures['position'];
        $source = $this->makeContract($fixtures);
        $position->update(['max_emp' => 1, 'exist_emp' => 1]);

        $this->ignoreLeaveBalances();

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractRenewModal::class)
            ->call('open', $source->id)
            ->call('renewAsIs')
            ->assertHasNoErrors();

        // During the overlap exist_emp reads 2. That is not drift: it still
        // equals the number of is_active contracts, which is the invariant
        // positions:fix-counts enforces.
        $this->assertSame(2, (int) $position->fresh()->exist_emp);
        $this->assertSame(2, $this->activeContractCount($position->id));

        // Because the two agree, fix-counts is a no-op — critically, it does
        // NOT ratchet max_emp up to 2, which is what it does on a mismatch.
        Artisan::call('positions:fix-counts');

        $this->assertSame(1, (int) $position->fresh()->max_emp);
        $this->assertSame(2, (int) $position->fresh()->exist_emp);

        // Once the source expires, the scheduled deactivation reclaims its seat.
        $this->travelTo('2027-01-02');
        Artisan::call('deactivate:contract');

        $this->assertSame(1, (int) $position->fresh()->exist_emp);
        $this->assertSame(1, (int) $position->fresh()->max_emp);
        $this->assertSame(1, $this->activeContractCount($position->id));

        // The renewal is the survivor; the source is the one stood down.
        $this->assertFalse((bool) $source->fresh()->is_active);
    }

    private function seedLeaveConfiguration(): void
    {
        AnnualLeaveRule::query()->create([
            'days' => 14,
            'day_per_month' => 1.5,
            'years_of_volunteering' => 0,
        ]);

        // base_days = 12 for all of them keeps LeaveBalanceService's
        // "whereNot(base_days, 12)" set empty, so the gender/marital-specific
        // branch stays out of this test's way.
        foreach ([
            LeaveTypeCases::Annual => 'Annual',
            LeaveTypeCases::FullSick => 'Full Sick',
            LeaveTypeCases::HalfSick => 'Half Sick',
            LeaveTypeCases::Unpaid => 'Unpaid',
        ] as $id => $name) {
            LeaveType::query()->forceCreate([
                'id' => $id,
                'name_en' => $name,
                'name_ar' => $name,
                'base_days' => 12,
            ]);
        }
    }

    private function activeContractCount(int $positionId): int
    {
        return Contract::query()
            ->where('position_id', $positionId)
            ->where('is_active', true)
            ->count();
    }

    /**
     * Undo PHPUnit's CLI SAPI for one test.
     *
     * ContractObserver::creating() short-circuits on runningInConsole() so bulk
     * imports can exceed capacity. That makes the observer's capacity check —
     * and the renewal bypass inside it — dead code under the test runner, while
     * being live on every real web request. These tests flip the memoized flag
     * so the production path is the one under test.
     */
    private function simulateWebRequest(): void
    {
        (new ReflectionProperty($this->app, 'isRunningInConsole'))
            ->setValue($this->app, false);

        $this->assertFalse($this->app->runningInConsole());
    }

    private function contractAttributes(array $fixtures, array $overrides = []): array
    {
        return array_merge([
            'employee_id' => $fixtures['employee']->id,
            'position_id' => $fixtures['position']->id,
            'contract_type_id' => $fixtures['annualType']->id,
            'start_date' => '2027-01-01',
            'end_date' => '2027-12-31',
            'salary' => 777.50,
            'currency' => 2,
            'salary_per' => 1,
            'weekly_hours' => 35,
            'is_active' => true,
        ], $overrides);
    }

    // ────────────────────────────────────────────────
    // Fixtures
    // ────────────────────────────────────────────────

    private function ignoreLeaveBalances(): void
    {
        $this->mock(LeaveBalanceService::class)
            ->shouldReceive('create')
            ->andReturnNull();
    }

    private function makeContract(array $fixtures, array $overrides = []): Contract
    {
        return Contract::query()->forceCreate(array_merge([
            'employee_id' => $fixtures['employee']->id,
            'position_id' => $fixtures['position']->id,
            'contract_type_id' => $fixtures['annualType']->id,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'salary' => 777.50,
            'currency' => 2,
            'salary_per' => 1,
            'weekly_hours' => 35,
            'work_days_en' => 'Monday,Tuesday,Wednesday,Thursday,Friday',
            'work_days_ar' => 'الاثنين,الثلاثاء,الأربعاء,الخميس,الجمعة',
            'work_hours_from' => '08:30',
            'work_hours_to' => '16:30',
            'is_active' => true,
        ], $overrides));
    }

    private function makeFixtures(): array
    {
        $city = $this->makeCity('ALP', '001');
        $remoteCity = $this->makeCity('HMS', '002');

        $center = Center::query()->create([
            'name_en' => 'Hope Center',
            'name_ar' => 'مركز الرجاء',
            'city_id' => $city->id,
            'is_active' => true,
            'weekends' => 'friday',
            'open_at' => '08:00:00',
            'close_at' => '16:00:00',
        ]);

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

        $annualType = ContractType::query()->forceCreate([
            'id' => 1,
            'name_en' => 'Annual',
            'name_ar' => 'سنوي',
        ]);

        // Types 3 and 4 are the trial contracts the form shortens to 3 months.
        $trialType = ContractType::query()->forceCreate([
            'id' => 3,
            'name_en' => 'Trial',
            'name_ar' => 'تجريبي',
        ]);

        $position = $this->makePosition($center, $city->id, $department, $jobLevel, $program, 'Program Officer');
        $otherPosition = $this->makePosition($center, $city->id, $department, $jobLevel, $program, 'Field Officer');

        $employee = $this->makeEmployee($city, 'renewal-employee');
        $hr = $this->makeUser($city, 'HR-ALP', ['contracts.create', 'contracts.update', 'contracts.view']);

        return compact(
            'city', 'remoteCity', 'center', 'program', 'annualType', 'trialType',
            'position', 'otherPosition', 'employee', 'hr',
        );
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
