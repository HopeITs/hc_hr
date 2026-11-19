<?php

namespace Tests\Feature;

use App\Enums\BadgeType;
use App\Enums\MaritalStatus;
use App\Livewire\Employee\EmployeeProfile;
use App\Models\City;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\TerminationReason;
use App\Models\User;
use App\Notifications\ProfileUpdatedByEmployee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
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

        // Meeting 19/11: the owner and HR in the city edit every section here.
        $all = ['personal-contact', 'skills', 'work-contact', 'family-status'];
        $this->assertSame($all, $this->profileAs($self, $this->lina)->viewData('editable'));
        $this->assertSame($all, $this->profileAs($huda, $this->lina)->viewData('editable'));

        // HR of another city and a colleague: none, not even on someone they can see.
        $this->profileAs($karim, $this->lina)->call('openSection', 'skills')->assertNotFound();
        $this->profileAs($rana, $this->lina)->call('openSection', 'personal-contact')->assertNotFound();
        $this->profileAs($rana, $this->lina)->call('openSection', 'work-contact')->assertNotFound();
        $this->profileAs($rana, $this->lina)->call('openSection', 'family-status')->assertNotFound();
        $this->profileAs($rana, $this->lina)->assertDontSeeHtml('data-testid="edit-');

        // Identity, contract and pay are no section at all.
        foreach (['identity', 'personal-information', 'contracts', 'salary'] as $section) {
            $this->profileAs($self, $this->lina)->call('openSection', $section)->assertNotFound();
        }

        // A former employee cannot edit their own record.
        $this->lina->update(['is_active' => false]);
        $this->profileAs($self, $this->lina->fresh())->call('openSection', 'skills')->assertNotFound();
    }

    // ── Meeting 19/11: direct edits reported to HR of the city ───────────

    public function test_the_owner_edits_work_contact_and_family_status_and_identity_keys_are_ignored(): void
    {
        Notification::fake();
        $self = $this->makeUser($this->aleppo, 'employee', [], $this->lina);

        $this->profileAs($self, $this->lina)->call('openSection', 'work-contact')
            ->set('sectionFields.work_phone', '٠٩٣٣٣٣٣٣٣٣')
            ->set('sectionFields.telegram_work', '@lina')
            ->call('saveSection')->assertHasNoErrors();

        $this->profileAs($self, $this->lina->fresh())->call('openSection', 'family-status')
            ->set('sectionFields.marital_status', (string) MaritalStatus::Married)
            ->set('sectionFields.children', '2')
            // Identity sent anyway is not a column of this section.
            ->set('sectionFields.national_number', '99999999999')
            ->set('sectionFields.first_name_en', 'Changed')
            ->set('sectionFields.salary', '1')
            ->call('saveSection')->assertHasNoErrors()->assertSet('sectionModal', false);

        $fresh = $this->lina->fresh();
        $this->assertSame('0933333333', $fresh->work_phone);
        $this->assertSame('@lina', $fresh->telegram_work);
        $this->assertSame(MaritalStatus::Married, (int) $fresh->marital_status);
        $this->assertSame(2, $fresh->children);
        $this->assertSame(['01234567890', 'Lina'], [$fresh->national_number, $fresh->first_name_en]);
    }

    public function test_family_status_is_validated_like_the_edit_page(): void
    {
        $self = $this->makeUser($this->aleppo, 'employee', [], $this->lina);
        $before = $this->lina->fresh()->only(['marital_status', 'children']);

        $this->profileAs($self, $this->lina)->call('openSection', 'family-status')
            ->set('sectionFields.marital_status', '99')
            ->set('sectionFields.children', '-1')
            ->call('saveSection')
            ->assertHasErrors(['sectionFields.marital_status', 'sectionFields.children'])
            ->assertSet('sectionModal', true);

        $this->assertSame($before, $this->lina->fresh()->only(['marital_status', 'children']));
    }

    public function test_hr_of_the_city_reads_what_changed_with_sensitive_values_withheld(): void
    {
        Notification::fake();
        $self = $this->makeUser($this->aleppo, 'employee', [], $this->lina);
        $huda = $this->makeUser($this->aleppo, 'HR-ALP', ['employees.view', 'employees.update']);
        $karim = $this->makeUser($this->homs, 'HR-HMS', ['employees.view', 'employees.update']);
        // An Aleppo HR role that may not update records is not told either.
        $viewer = $this->makeUser($this->aleppo, 'HR-ALP-VIEW', ['employees.view']);

        // Two fields in one save: one notification, one line each.
        $this->profileAs($self, $this->lina)->call('openSection', 'skills')
            ->set('sectionFields.skills', 'teamwork,first aid')
            ->set('sectionFields.interests', '')
            ->call('saveSection')->assertHasNoErrors();
        $this->profileAs($self, $this->lina->fresh())->call('openSection', 'personal-contact')
            ->set('sectionFields.personal_phone', '0966666666')
            ->call('saveSection')->assertHasNoErrors();

        Notification::assertSentToTimes($huda, ProfileUpdatedByEmployee::class, 2);
        Notification::assertNotSentTo([$karim, $viewer, $self], ProfileUpdatedByEmployee::class);

        $bodies = [];
        Notification::assertSentTo($huda, ProfileUpdatedByEmployee::class, function (ProfileUpdatedByEmployee $n) use ($huda, &$bodies): bool {
            $bodies[] = $n->toArray($huda)['body'];

            return true;
        });
        $this->assertSame('Skills: teamwork → teamwork,first aid; Interests: reading → (empty)', $bodies[0]['en']);
        $this->assertStringContainsString('المهارات: teamwork ← teamwork,first aid', $bodies[0]['ar']);
        $this->assertSame('Personal Phone: changed', $bodies[1]['en']);
        $this->assertStringNotContainsString('0966666666', json_encode($bodies));
        $this->assertStringNotContainsString('0922222222', json_encode($bodies));
    }

    public function test_nothing_is_sent_without_a_change_or_when_hr_edits(): void
    {
        Notification::fake();
        $self = $this->makeUser($this->aleppo, 'employee', [], $this->lina);
        $huda = $this->makeUser($this->aleppo, 'HR-ALP', ['employees.view', 'employees.update']);

        // Saved as it was: nothing changed, nothing sent.
        $this->profileAs($self, $this->lina)->call('openSection', 'skills')->call('saveSection')->assertHasNoErrors();
        // HR's own edit is the ordinary audited path.
        $this->profileAs($huda, $this->lina)->call('openSection', 'skills')
            ->set('sectionFields.skills', 'leadership')->call('saveSection')->assertHasNoErrors();
        // A refused (stale) save reports nothing either.
        $component = $this->profileAs($self, $this->lina->fresh())->call('openSection', 'skills')->set('sectionFields.skills', 'x');
        Employee::query()->whereKey($this->lina->id)->update(['skills' => 'someone else']);
        $component->call('saveSection')->assertHasErrors('section');

        Notification::assertNothingSent();
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
