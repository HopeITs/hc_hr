<?php

namespace Tests\Feature;

use App\Enums\BadgeType;
use App\Livewire\Employee\EmployeeProfile;
use App\Models\City;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\TerminationReason;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * D1 (profile-contract.md): photo, timeline visibility and in-place section
 * editing. Lina (Aleppo) reports to Sami; Rana is an Aleppo colleague with
 * only the Directory tier; Huda is HR-ALP; Karim is HR-HMS.
 */
class EmployeeProfileD1Test extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    private City $aleppo;

    private City $homs;

    private Employee $lina;

    private Employee $sami;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setDate(2026, 10, 27)->setTime(10, 0));
        app()->setLocale('en');
        $this->aleppo = $this->makeCity('ALP', '001');
        $this->homs = $this->makeCity('HMS', '002');

        $this->sami = $this->makeEmployee($this->aleppo, 'sami', ['first_name_en' => 'Sami']);
        $samiPosition = $this->makePosition($this->aleppo);
        $this->makeContractFor($this->sami, ['position_id' => $samiPosition->id]);

        $this->lina = $this->makeEmployee($this->aleppo, 'lina', [
            'first_name_en' => 'Lina', 'national_number' => '01234567890', 'work_phone' => '0911111111',
            'personal_phone' => '0922222222', 'skills' => 'teamwork', 'interests' => 'reading',
        ]);
        $reason = TerminationReason::query()->forceCreate(['title_en' => 'Relocation', 'title_ar' => 'انتقال']);
        $linaPosition = $this->makePosition($this->aleppo, ['direct_manager_id' => $samiPosition->id]);
        $this->makeContractFor($this->lina, [
            'position_id' => $linaPosition->id, 'start_date' => '2025-01-01', 'end_date' => '2025-12-31',
            'is_active' => false, 'termination_reason_id' => $reason->id, 'salary' => 987654,
        ]);
        $this->makeContractFor($this->lina, ['position_id' => $linaPosition->id, 'salary' => 987654]);
        $this->lina->badges()->create(['type' => BadgeType::Reward, 'date' => '2026-03-01', 'reason' => 'Great tutoring', 'notes' => 'HR-INTERNAL-NOTE']);
        $this->lina->badges()->create(['type' => BadgeType::Warning, 'date' => '2026-06-01', 'reason' => 'Late reports']);
        $this->lina->badges()->create(['type' => BadgeType::Salary_Increase, 'date' => '2026-07-01', 'reason' => 'Raise', 'new_salary' => 555444]);
    }

    // ── AC-D1-01: photographs ────────────────────────────────────────────

    public function test_the_photo_uses_the_authorized_route_with_a_fallback(): void
    {
        $viewer = $this->makeUser($this->aleppo, 'employee');

        $this->profileAs($viewer, $this->lina)
            ->assertSeeHtml('src="'.asset('images/default-avatar.png').'"');

        $this->lina->addMedia(UploadedFile::fake()->image('lina.png', 40, 40))->toMediaCollection('avatar');
        $html = $this->profileAs($viewer, $this->lina->fresh())->html();

        $this->assertStringContainsString(route('employees.avatar', ['employee' => $this->lina->id], false), str_replace(url('/'), '', $html));
        $this->assertStringNotContainsString('/storage/', $html);
    }

    // ── AC-D1-02: timeline visibility ────────────────────────────────────

    public function test_a_directory_only_colleague_gets_no_timeline_and_no_trace_of_events(): void
    {
        $rana = $this->makeUser($this->aleppo, 'employee', [], $this->makeEmployee($this->aleppo, 'rana'));

        $component = $this->profileAs($rana, $this->lina);

        $component->assertDontSeeHtml('data-section="timeline"')
            ->assertDontSeeHtml('data-event=')
            ->assertDontSeeHtml('data-section="badges"')
            ->assertDontSee('Relocation')
            ->assertDontSee('Late reports')
            ->assertDontSee('Great tutoring');
        $this->assertSame([], $component->viewData('timeline'));
    }

    public function test_the_direct_manager_sees_career_with_the_termination_reason_but_no_pay(): void
    {
        $sami = $this->makeUser($this->aleppo, 'manager', [], $this->sami);

        $component = $this->profileAs($sami, $this->lina);

        $this->assertSame(['contract.started', 'contract.ended', 'contract.joined'], $this->kinds($component));
        $component->assertSee('Relocation')->assertDontSee('987,654')->assertDontSee('987654')
            // Labels are translated, never the raw key.
            ->assertSee('Joined')->assertSee('Contract ended')->assertDontSee('employees.profile.events');
    }

    public function test_the_owner_sees_own_rewards_and_warnings_without_pay_or_notes(): void
    {
        $self = $this->makeUser($this->aleppo, 'employee', [], $this->lina);

        $component = $this->profileAs($self, $this->lina);

        $this->assertSame(['badge.warning', 'badge.reward', 'contract.started', 'contract.ended', 'contract.joined'], $this->kinds($component));
        $component->assertSee('Late reports')
            ->assertDontSee('HR-INTERNAL-NOTE')
            ->assertDontSee('555444')
            ->assertDontSee('555,444')
            ->assertDontSee('987,654');
    }

    public function test_hr_with_pay_sees_the_salary_increase_and_a_salary_blind_role_does_not(): void
    {
        $huda = $this->makeUser($this->aleppo, 'HR-ALP', ['employees.view', 'employees.update', 'badges.view']);
        $component = $this->profileAs($huda, $this->lina);
        $this->assertContains('badge.salary', $this->kinds($component));

        $admin = $this->makeUser($this->aleppo, 'super-admin');
        $this->assertNotContains('badge.salary', $this->kinds($this->profileAs($admin, $this->lina)));
        $this->profileAs($admin, $this->lina)->assertDontSee('555,444');
    }

    // ── AC-D1-03: section editing ────────────────────────────────────────

    public function test_the_owner_saves_skills_and_nothing_else_changes(): void
    {
        $self = $this->makeUser($this->aleppo, 'employee', [], $this->lina);
        $before = $this->lina->fresh()->only(['national_number', 'work_phone', 'personal_phone', 'first_name_en']);
        $contracts = Contract::query()->where('employee_id', $this->lina->id)->get(['id', 'salary', 'updated_at'])->toArray();

        $this->profileAs($self, $this->lina)
            ->call('openSection', 'skills')
            ->assertSet('sectionModal', true)
            ->set('sectionFields.skills', 'teamwork,first aid')
            // A field of another section sent anyway is ignored.
            ->set('sectionFields.national_number', '99999999999')
            ->call('saveSection')
            ->assertHasNoErrors()
            ->assertSet('sectionModal', false);

        $fresh = $this->lina->fresh();
        $this->assertSame('teamwork,first aid', $fresh->skills);
        $this->assertSame($before, $fresh->only(['national_number', 'work_phone', 'personal_phone', 'first_name_en']));
        $this->assertSame($contracts, Contract::query()->where('employee_id', $this->lina->id)->get(['id', 'salary', 'updated_at'])->toArray());
        $this->assertTrue(Activity::query()->where('subject_type', $fresh->getMorphClass())->where('subject_id', $fresh->id)->where('causer_id', $self->id)->exists());

        // Contact is audited by field name only, never by value.
        $this->profileAs($self, $fresh)->call('openSection', 'personal-contact')
            ->set('sectionFields.personal_phone', '0966666666')->call('saveSection')->assertHasNoErrors();
        $log = Activity::query()->where('subject_id', $fresh->id)->where('causer_id', $self->id)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertStringNotContainsString('0966666666', json_encode($log->properties));
    }

    public function test_who_may_edit_which_section(): void
    {
        $self = $this->makeUser($this->aleppo, 'employee', [], $this->lina);
        $rana = $this->makeUser($this->aleppo, 'employee', [], $this->makeEmployee($this->aleppo, 'rana'));
        $huda = $this->makeUser($this->aleppo, 'HR-ALP', ['employees.view', 'employees.update']);
        $karim = $this->makeUser($this->homs, 'HR-HMS', ['employees.view', 'employees.update']);

        // The owner: contact and skills, not work contact.
        $this->assertSame(['personal-contact', 'skills'], $this->profileAs($self, $this->lina)->viewData('editable'));
        $this->profileAs($self, $this->lina)->call('openSection', 'work-contact')->assertNotFound();

        // HR in the city: all three. HR of another city and a colleague: none.
        $this->assertSame(['personal-contact', 'skills', 'work-contact'], $this->profileAs($huda, $this->lina)->viewData('editable'));
        $this->profileAs($karim, $this->lina)->call('openSection', 'skills')->assertNotFound();
        $this->profileAs($rana, $this->lina)->call('openSection', 'personal-contact')->assertNotFound();
        $this->profileAs($rana, $this->lina)->assertDontSeeHtml('data-testid="edit-');

        // A former employee cannot edit their own record.
        $this->lina->update(['is_active' => false]);
        $this->profileAs($self, $this->lina->fresh())->call('openSection', 'skills')->assertNotFound();
    }

    public function test_a_stale_save_writes_nothing_and_keeps_the_entries(): void
    {
        $self = $this->makeUser($this->aleppo, 'employee', [], $this->lina);
        $component = $this->profileAs($self, $this->lina)
            ->call('openSection', 'personal-contact')
            ->set('sectionFields.personal_phone', '0933333333');

        // HR saves the same section in the meantime.
        Employee::query()->whereKey($this->lina->id)->update(['personal_phone' => '0944444444']);

        $component->call('saveSection')
            ->assertHasErrors('section')
            ->assertSet('sectionModal', true)
            ->assertSet('sectionFields.personal_phone', '0933333333')
            ->assertSeeHtml('data-testid="section-stale"');
        $this->assertSame('0944444444', $this->lina->fresh()->personal_phone);

        // "Show current values" reloads, and a save then goes through.
        $component->call('reloadSection')
            ->assertSet('sectionFields.personal_phone', '0944444444')
            ->set('sectionFields.personal_phone', '0955555555')
            ->call('saveSection')
            ->assertHasNoErrors();
        $this->assertSame('0955555555', $this->lina->fresh()->personal_phone);
    }

    public function test_an_invalid_entry_is_reported_on_its_field_and_nothing_is_saved(): void
    {
        $self = $this->makeUser($this->aleppo, 'employee', [], $this->lina);

        $this->profileAs($self, $this->lina)
            ->call('openSection', 'personal-contact')
            ->set('sectionFields.personal_phone', '12345')
            ->call('saveSection')
            ->assertHasErrors('sectionFields.personal_phone')
            ->assertSet('sectionModal', true)
            ->assertSet('sectionFields.personal_phone', '12345');

        $this->assertSame('0922222222', $this->lina->fresh()->personal_phone);
    }

    public function test_deep_links_open_an_allowed_section_only_and_the_old_edit_page_still_works(): void
    {
        $self = $this->makeUser($this->aleppo, 'employee', [], $this->lina);
        $rana = $this->makeUser($this->aleppo, 'employee', [], $this->makeEmployee($this->aleppo, 'rana'));

        Livewire::withQueryParams(['edit' => 'skills'])->actingAs($self)
            ->test(EmployeeProfile::class, ['employee' => $this->lina])
            ->assertSet('sectionModal', true);

        // Not allowed: the page simply opens, with no error that reveals anything.
        Livewire::withQueryParams(['edit' => 'personal-contact'])->actingAs($rana)
            ->test(EmployeeProfile::class, ['employee' => $this->lina])
            ->assertOk()
            ->assertSet('sectionModal', false)
            ->assertSet('editing', '');

        $huda = $this->makeUser($this->aleppo, 'HR-ALP', ['employees.view', 'employees.update']);
        $this->actingAs($huda)->get(route('employees.edit', $this->lina))->assertOk();
    }

    private function profileAs(User $user, Employee $subject)
    {
        return Livewire::actingAs($user)->test(EmployeeProfile::class, ['employee' => $subject]);
    }

    private function kinds($component): array
    {
        return array_column($component->viewData('timeline'), 'kind');
    }
}
