<?php

namespace Tests\Feature;

use App\Livewire\Employee\EmployeeCreate;
use App\Livewire\Employee\EmployeeEdit;
use App\Models\City;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * B5 phase 2: server-side autosave of an employee draft (DEC-04, B5D-Q10,
 * docs/ux/employee-lifecycle.md §5.6, docs/ux/b5-implementation.md §8).
 */
class EmployeeAutosaveTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Notification::fake();
        Role::findOrCreate('employee', 'web');
    }

    // ── below the minimum: nothing written, nothing shown ───────────

    public function test_autosave_below_the_draft_minimum_writes_nothing_and_shows_no_errors(): void
    {
        $city = $this->makeCity('ALP', '001');
        $before = Employee::query()->count();

        Livewire::actingAs($this->hr($city))->test(EmployeeCreate::class)
            ->set('infoForm.first_name_en', 'Lina')
            ->set('infoForm.national_number', '12345678909')
            ->call('autosaveDraft')
            ->assertReturned(['status' => 'incomplete'])
            ->assertHasNoErrors()
            ->assertSet('infoForm.id', null);

        $this->assertSame($before, Employee::query()->count());
        $this->assertSame(0, Employee::query()->drafts()->count());
    }

    public function test_autosave_with_a_taken_national_id_or_an_out_of_scope_city_writes_nothing(): void
    {
        $city = $this->makeCity('ALP', '001');
        $elsewhere = $this->makeCity('HMS', '002');
        $this->makeEmployee($city, 'existing', ['national_number' => '12345678909']);
        $hr = $this->hr($city);

        $this->draftMinimum(Livewire::actingAs($hr)->test(EmployeeCreate::class), $city)
            ->call('autosaveDraft')
            ->assertReturned(['status' => 'incomplete'])
            ->assertHasNoErrors();

        $this->draftMinimum(Livewire::actingAs($hr)->test(EmployeeCreate::class), $city)
            ->set('infoForm.national_number', '98765432101')
            ->set('infoForm.city_id', $elsewhere->id)
            ->call('autosaveDraft')
            ->assertReturned(['status' => 'incomplete'])
            ->assertHasNoErrors();

        $this->assertSame(0, Employee::query()->where('username', 'b5.autosave')->count());
    }

    public function test_autosave_leaves_the_errors_of_an_explicit_save_on_screen(): void
    {
        $city = $this->makeCity('ALP', '001');

        $component = Livewire::actingAs($this->hr($city))->test(EmployeeCreate::class)
            ->call('saveInformation')
            ->assertHasErrors(['infoForm.gender', 'infoForm.username']);

        $this->draftMinimum($component, $city)
            ->call('autosaveDraft')
            ->assertReturned(fn ($result) => $result['status'] === 'saved')
            ->assertHasErrors(['infoForm.gender']);
    }

    // ── the first valid autosave creates one draft, later ones update it ──

    public function test_the_first_valid_autosave_creates_one_draft_and_the_next_ones_update_that_row(): void
    {
        $city = $this->makeCity('ALP', '001');
        $hr = $this->hr($city);

        $component = $this->draftMinimum(Livewire::actingAs($hr)->test(EmployeeCreate::class), $city)
            ->call('autosaveDraft')
            ->assertHasNoErrors()
            ->assertReturned(fn ($result) => $result['status'] === 'saved'
                && filled($result['time'])
                && str_contains($result['url'], '/edit'))
            // Stays on the page: no draft-position step, no redirect, no toast.
            ->assertSet('showDraftPrompt', false)
            ->assertNoRedirect()
            ->assertNotDispatched('toast');

        $draft = Employee::query()->where('username', 'b5.autosave')->sole();
        $this->assertTrue($draft->isDraft());
        $component->assertSet('infoForm.id', $draft->id);

        $component->set('infoForm.address_en', 'First pause')->call('autosaveDraft')
            ->assertReturned(fn ($result) => $result['status'] === 'saved');
        $component->set('infoForm.address_en', 'Second pause')->call('autosaveDraft');

        $this->assertSame(1, Employee::query()->where('username', 'b5.autosave')->count());
        $this->assertSame(1, Employee::query()->drafts()->count());
        $this->assertSame('Second pause', $draft->fresh()->address_en);

        // "Save draft" after an autosave continues on the same row too.
        $component->set('infoForm.address_en', 'Explicit')->call('saveDraftRecord')->assertHasNoErrors();
        $this->assertSame(1, Employee::query()->where('username', 'b5.autosave')->count());
        $this->assertSame('Explicit', $draft->fresh()->address_en);
    }

    public function test_autosave_on_a_draft_edit_page_updates_that_draft(): void
    {
        $city = $this->makeCity('ALP', '001');
        $draft = $this->makeDraft($city);

        Livewire::actingAs($this->hr($city, 'employees.update'))->test(EmployeeEdit::class, ['employee' => $draft])
            ->assertSeeHtml('data-testid="draft-autosave"')
            ->set('infoForm.address_en', 'Typed on resume')
            ->call('autosaveDraft')
            ->assertHasNoErrors()
            ->assertReturned(fn ($result) => $result['status'] === 'saved');

        $this->assertSame('Typed on resume', $draft->fresh()->address_en);
        $this->assertTrue($draft->fresh()->isDraft());
        $this->assertSame(1, Employee::query()->drafts()->count());
    }

    // ── a complete record is never autosaved back to draft ──────────

    public function test_a_complete_record_is_never_autosaved(): void
    {
        $city = $this->makeCity('ALP', '001');
        $employee = $this->makeEmployee($city, 'b5.complete', ['address_en' => 'Original']);

        Livewire::actingAs($this->hr($city, 'employees.update'))->test(EmployeeEdit::class, ['employee' => $employee])
            ->assertDontSeeHtml('data-testid="draft-autosave"')
            ->set('infoForm.address_en', 'Changed')
            ->call('autosaveDraft')
            ->assertReturned(['status' => 'complete']);

        $employee->refresh();
        $this->assertFalse($employee->isDraft());
        $this->assertSame('Original', $employee->address_en);
    }

    public function test_a_record_completed_on_the_create_page_is_not_autosaved_back_to_draft(): void
    {
        $city = $this->makeCity('ALP', '001');

        $component = $this->draftMinimum(Livewire::actingAs($this->hr($city))->test(EmployeeCreate::class), $city)
            ->call('autosaveDraft')
            ->set('infoForm.gender', 1)
            ->set('infoForm.marital_status', 1)
            ->set('infoForm.nationality', 1)
            ->call('saveInformation')
            ->assertHasNoErrors();

        $record = Employee::query()->where('username', 'b5.autosave')->sole();
        $this->assertFalse($record->isDraft());

        $component->call('autosaveDraft')->assertReturned(['status' => 'complete']);

        // Even with the browser bringing the form back.
        $component->set('showDraftPrompt', false)
            ->set('infoForm.address_en', 'Late autosave')
            ->call('autosaveDraft')
            ->assertReturned(['status' => 'complete']);

        $this->assertFalse($record->fresh()->isDraft());
        $this->assertNull($record->fresh()->address_en);
        $this->assertSame(1, Employee::query()->where('username', 'b5.autosave')->count());
    }

    // ── re-authorized on every autosave ─────────────────────────────

    public function test_hr_of_another_city_gets_403_on_a_draft(): void
    {
        $city = $this->makeCity('ALP', '001');
        $draft = $this->makeDraft($city);
        $other = $this->hr($this->makeCity('HMS', '002'));

        $component = Livewire::actingAs($this->hr($city, 'employees.update'))
            ->test(EmployeeEdit::class, ['employee' => $draft])
            ->set('infoForm.address_en', 'Not theirs');

        $this->actingAs($other);
        $component->call('autosaveDraft')->assertForbidden();

        $this->assertNull($draft->fresh()->address_en);
    }

    public function test_hr_of_another_city_gets_403_on_a_draft_the_create_page_autosaved(): void
    {
        $city = $this->makeCity('ALP', '001');
        $other = $this->hr($this->makeCity('HMS', '002'));

        $component = $this->draftMinimum(Livewire::actingAs($this->hr($city))->test(EmployeeCreate::class), $city)
            ->call('autosaveDraft');

        $this->actingAs($other);
        $component->set('infoForm.address_en', 'Not theirs')->call('autosaveDraft')->assertForbidden();

        $this->assertNull(Employee::query()->where('username', 'b5.autosave')->sole()->address_en);
    }

    public function test_a_user_without_create_permission_gets_403(): void
    {
        $city = $this->makeCity('ALP', '001');

        $component = $this->draftMinimum(Livewire::actingAs($this->hr($city))->test(EmployeeCreate::class), $city);

        $this->actingAs($this->makeUser($city, 'employee-viewer', ['employees.view']));
        $component->call('autosaveDraft')->assertForbidden();

        $this->assertSame(0, Employee::query()->where('username', 'b5.autosave')->count());
    }

    // ── nothing activates ───────────────────────────────────────────

    public function test_autosave_never_activates_the_record_or_creates_an_account(): void
    {
        $city = $this->makeCity('ALP', '001');
        $hr = $this->hr($city);

        $this->draftMinimum(Livewire::actingAs($hr)->test(EmployeeCreate::class), $city)
            ->set('infoForm.is_active', true)
            ->set('infoForm.create_account', true)
            ->call('autosaveDraft')
            ->assertReturned(fn ($result) => $result['status'] === 'saved');

        $draft = Employee::query()->where('username', 'b5.autosave')->sole();
        $this->assertTrue($draft->isDraft());
        $this->assertFalse($draft->is_active);
        $this->assertNull($draft->account);
        $this->assertNull($draft->digital_id_token);

        $resumed = $this->makeDraft($city);
        Livewire::actingAs($hr)->test(EmployeeEdit::class, ['employee' => $resumed])
            ->set('infoForm.is_active', true)
            ->set('infoForm.create_account', true)
            ->set('infoForm.address_en', 'forged')
            ->call('autosaveDraft')
            ->assertReturned(fn ($result) => $result['status'] === 'saved');

        $this->assertFalse($resumed->fresh()->is_active);
        $this->assertTrue($resumed->fresh()->isDraft());
        $this->assertNull($resumed->fresh()->account);
        $this->assertSame(0, User::query()->where('email', 'like', 'b5.%@hcsyria.org')->count());
        Notification::assertNothingSent();
    }

    // ── saved by ────────────────────────────────────────────────────

    public function test_autosave_records_who_saved_the_draft(): void
    {
        $city = $this->makeCity('ALP', '001');
        $draft = $this->makeDraft($city);
        $colleague = $this->hr($city, 'employees.update');
        $this->assertNull($draft->last_saved_by);

        Livewire::actingAs($colleague)->test(EmployeeEdit::class, ['employee' => $draft])
            ->set('infoForm.address_en', 'Colleague was here')
            ->call('autosaveDraft')
            ->assertReturned(fn ($result) => $result['status'] === 'saved'
                && str_contains($result['message'], $colleague->profile->nameForLocale('en')));

        $this->assertSame($colleague->id, $draft->fresh()->last_saved_by);
    }

    // ── the status line ─────────────────────────────────────────────

    public function test_the_create_page_shows_the_status_line_until_it_is_saved_explicitly(): void
    {
        $city = $this->makeCity('ALP', '001');

        $component = Livewire::actingAs($this->hr($city))->test(EmployeeCreate::class)
            ->assertSeeHtml('data-testid="draft-autosave"')
            ->assertSeeHtml('aria-live="polite"')
            ->assertSee(__('employees.onboarding.autosave-not-yet'));

        $this->draftMinimum($component, $city)->call('saveDraftRecord')
            ->assertSet('showDraftPrompt', true)
            ->assertDontSeeHtml('data-testid="draft-autosave"');
    }

    // ── fixtures ─────────────────────────────────────────────────────

    private function hr(City $city, string ...$permissions): User
    {
        return $this->makeUser($city, 'HR-'.$city->slug, $permissions ?: ['employees.create', 'employees.update']);
    }

    private function draftMinimum($component, City $city)
    {
        return $component
            ->set('infoForm.first_name_en', 'Lina')->set('infoForm.first_name_ar', 'لينا')
            ->set('infoForm.last_name_en', 'Haddad')->set('infoForm.last_name_ar', 'حداد')
            ->set('infoForm.father_name_en', 'Sami')->set('infoForm.father_name_ar', 'سامي')
            ->set('infoForm.mother_name_en', 'Rima')->set('infoForm.mother_name_ar', 'ريما')
            ->set('infoForm.city_id', $city->id)
            ->set('infoForm.national_number', '12345678909')
            ->set('infoForm.username', 'b5.autosave');
    }

    private function makeDraft(City $city): Employee
    {
        $draft = new Employee([
            'city_id' => $city->id,
            'first_name_en' => 'Draft', 'first_name_ar' => 'مسودة',
            'last_name_en' => 'Person', 'last_name_ar' => 'شخص',
            'father_name_en' => 'F', 'father_name_ar' => 'ف',
            'mother_name_en' => 'M', 'mother_name_ar' => 'م',
            'national_number' => (string) random_int(10000000000, 99999999999),
            'username' => 'b5.d.'.uniqid(),
        ]);
        $draft->forceFill(['record_status' => Employee::RECORD_DRAFT, 'is_active' => false, 'is_board_member' => false])->save();

        return $draft;
    }
}
