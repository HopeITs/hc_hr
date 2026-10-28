<?php

namespace Tests\Feature;

use App\Livewire\Feedback\FeedbackForm;
use App\Livewire\Feedback\FeedbackIndex;
use App\Models\Center;
use App\Models\City;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\InAppFeedback;
use App\Models\JobLevel;
use App\Models\Position;
use App\Models\Program;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Livewire\Features\SupportFileUploads\WithFileUploads;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;
use Throwable;

/**
 * D3 / DEC-08 in-app feedback: AC-D3-03 (only disclosed context is stored,
 * attachments are not accepted) plus the IT-only reading list and retention.
 */
class InAppFeedbackTest extends TestCase
{
    use CreatesHrFixtures;
    use RefreshDatabase;

    private City $city;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app()->setLocale('en');
        $this->city = $this->makeCity('ALP', '001');
    }

    public function test_it_stores_only_the_disclosed_fields_with_server_derived_context(): void
    {
        $user = $this->makeUser($this->city, 'HR-ALP');
        app()->setLocale('ar');

        $this->send($user, "  Typo on the contracts page.\nSecond line.  ", 'idea', 'dashboard')
            ->assertHasNoErrors()
            ->assertSet('showFeedback', false)
            ->assertSet('message', '')
            ->assertDispatched('toast', type: 'success', message: __('feedback.form.sent'));

        $this->assertSame(
            ['id', 'user_id', 'category', 'message', 'route_name', 'locale', 'role_name', 'created_at'],
            Schema::getColumnListing('in_app_feedback'),
        );

        $row = InAppFeedback::query()->sole();
        $this->assertSame($user->id, (int) $row->user_id);
        $this->assertSame('idea', $row->category);
        $this->assertSame("Typo on the contracts page.\nSecond line.", $row->message);
        $this->assertSame('dashboard', $row->route_name);
        $this->assertSame('ar', $row->locale);
        $this->assertSame('HR-ALP', $row->role_name);
        $this->assertNotNull($row->created_at);
    }

    public function test_the_disclosure_lists_page_language_role_and_the_rules_before_sending(): void
    {
        $user = $this->makeUser($this->city, 'employee');

        Livewire::actingAs($user)
            ->test(FeedbackForm::class, ['pageRoute' => 'dashboard'])
            ->call('open')
            ->assertSee(__('feedback.disclosure.title'))
            ->assertSee('dashboard')
            ->assertSee('English')
            ->assertSee(role_label('employee'))
            ->assertSee(__('feedback.disclosure.no-personal'))
            ->assertSee(__('feedback.disclosure.retention'))
            ->assertSee(__('feedback.disclosure.readers'))
            ->assertDontSeeHtml('type="file"');
    }

    public function test_the_client_cannot_change_the_page_or_disclosed_role(): void
    {
        $user = $this->makeUser($this->city, 'employee');
        $component = Livewire::actingAs($user)->test(FeedbackForm::class, ['pageRoute' => 'dashboard'])->call('open');

        foreach (['pageRoute' => 'access-management.index', 'disclosedRole' => 'super-admin'] as $property => $value) {
            try {
                $component->set($property, $value);
                $this->fail("{$property} accepted a value from the browser.");
            } catch (CannotUpdateLockedPropertyException) {
                // expected
            }
        }

        $component->set('message', 'Hello IT team')->call('submit')->assertHasNoErrors();

        $row = InAppFeedback::query()->sole();
        $this->assertSame('dashboard', $row->route_name);
        $this->assertSame('employee', $row->role_name);
    }

    public function test_the_stored_role_is_the_active_one_not_another_assigned_role(): void
    {
        $user = $this->makeUser($this->city, 'employee');
        $user = $this->assignInactiveRole($user, 'super-admin');

        $this->send($user)->assertHasNoErrors();

        $this->assertSame('employee', InAppFeedback::query()->sole()->role_name);
    }

    public function test_an_extra_field_from_the_browser_is_not_stored(): void
    {
        $user = $this->makeUser($this->city, 'employee');
        $component = Livewire::actingAs($user)->test(FeedbackForm::class, ['pageRoute' => 'dashboard'])->call('open');

        foreach (['role_name' => 'super-admin', 'user_id' => 999, 'salary' => '5000'] as $property => $value) {
            try {
                $component->set($property, $value);
            } catch (Throwable) {
                // Refused outright is also fine; what matters is the stored row.
            }
        }

        $component->set('message', 'Hello IT team')->call('submit');

        $row = InAppFeedback::query()->sole();
        $this->assertSame('employee', $row->role_name);
        $this->assertSame($user->id, (int) $row->user_id);
        $this->assertArrayNotHasKey('salary', $row->getAttributes());
    }

    public function test_a_page_route_that_is_not_a_route_name_is_dropped(): void
    {
        $user = $this->makeUser($this->city, 'employee');

        foreach (['https://example.com/en/contracts?search=salary', 'not.a.route', str_repeat('a', 200)] as $route) {
            Livewire::actingAs($user)
                ->test(FeedbackForm::class, ['pageRoute' => $route])
                ->assertSet('pageRoute', null);
        }

        $this->send($user, route: 'contracts?search=1');
        $this->assertNull(InAppFeedback::query()->sole()->route_name);
    }

    public function test_validation_rejects_empty_too_long_and_unknown_category(): void
    {
        $user = $this->makeUser($this->city, 'employee');

        $this->send($user, '   ')->assertHasErrors(['message' => 'required'])->assertSet('showFeedback', true);
        $this->send($user, str_repeat('x', 2001))->assertHasErrors(['message' => 'max']);
        $this->send($user, 'Valid text here', 'salary-report')->assertHasErrors(['category']);

        $this->assertSame(0, InAppFeedback::query()->count());
    }

    public function test_submissions_are_rate_limited_per_user(): void
    {
        $user = $this->makeUser($this->city, 'employee');
        $other = $this->makeUser($this->city, 'manager');

        for ($i = 1; $i <= 5; $i++) {
            $this->send($user, "Note number {$i}")->assertHasNoErrors();
        }

        $this->send($user, 'Note number 6')
            ->assertHasErrors('message')
            ->assertSet('message', 'Note number 6')
            ->assertSet('showFeedback', true)
            ->assertDispatched('toast', type: 'error');

        $this->assertSame(5, InAppFeedback::query()->where('user_id', $user->id)->count());

        // Another user is not affected.
        $this->send($other, 'Unrelated note')->assertHasNoErrors();
        $this->assertSame(1, InAppFeedback::query()->where('user_id', $other->id)->count());

        RateLimiter::clear('in-app-feedback:'.$user->id);
    }

    public function test_a_role_switch_after_opening_is_shown_before_anything_is_sent(): void
    {
        $user = $this->makeUser($this->city, 'employee');
        $user = $this->assignInactiveRole($user, 'manager');

        $component = Livewire::actingAs($user)
            ->test(FeedbackForm::class, ['pageRoute' => 'dashboard'])
            ->call('open')
            ->set('message', 'Written as an employee');

        $user->setActiveRole(Role::findByName('manager', 'web')->id);

        $component->call('submit')
            ->assertHasErrors('message')
            ->assertSet('message', 'Written as an employee')
            ->assertSet('disclosedRole', 'manager');
        $this->assertSame(0, InAppFeedback::query()->count());

        $component->call('submit')->assertHasNoErrors();
        $this->assertSame('manager', InAppFeedback::query()->sole()->role_name);
    }

    public function test_guests_cannot_open_or_submit(): void
    {
        Livewire::test(FeedbackForm::class)->call('open')->assertForbidden();
        Livewire::test(FeedbackForm::class)->set('message', 'anonymous text')->call('submit')->assertForbidden();

        $this->assertSame(0, InAppFeedback::query()->count());
    }

    public function test_no_attachment_can_be_sent(): void
    {
        $this->assertNotContains(WithFileUploads::class, class_uses_recursive(FeedbackForm::class));

        $user = $this->makeUser($this->city, 'employee');
        $component = Livewire::actingAs($user)->test(FeedbackForm::class, ['pageRoute' => 'dashboard'])->call('open');

        $refused = false;

        try {
            $component->call('_startUpload', 'message', [['name' => 'shot.png', 'size' => 1000, 'type' => 'image/png']], false);
        } catch (Throwable) {
            $refused = true;
        }

        // Without WithFileUploads there is no upload endpoint to start: Livewire
        // refuses the call instead of issuing a signed upload URL.
        $this->assertTrue($refused, 'The upload start was accepted.');
        $this->assertSame(0, InAppFeedback::query()->count());
    }

    public function test_dirty_close_asks_and_keeps_text_until_discard(): void
    {
        $user = $this->makeUser($this->city, 'employee');

        Livewire::actingAs($user)
            ->test(FeedbackForm::class, ['pageRoute' => 'dashboard'])
            ->call('open')
            ->call('requestClose', 'showFeedback')
            ->assertSet('showFeedback', false)
            ->call('open')
            ->set('message', 'Half written')
            ->call('requestClose', 'showFeedback')
            ->assertDispatched('confirm-dialog')
            ->assertSet('showFeedback', true)
            ->assertSet('message', 'Half written')
            ->call('discardChanges', 'showFeedback')
            ->assertSet('showFeedback', false)
            ->assertSet('message', '')
            ->assertNoRedirect();

        $this->assertSame(0, InAppFeedback::query()->count());
    }

    public function test_nobody_without_an_it_contract_can_read_feedback(): void
    {
        InAppFeedback::query()->insert([
            'user_id' => null, 'message' => 'secret note', 'locale' => 'en', 'created_at' => now(),
        ]);

        // Roles and permissions do not count: not super-admin, not HR-SYR
        // (an administrator role), not even a feedback.view grant...
        $cases = [
            ['super-admin', []],
            ['HR-SYR', ['feedback.view']],
            ['HR-ALP', ['feedback.view', 'activity-log.view']],
            ['general-manager', []],
            ['manager', []],
            ['employee', []],
        ];

        foreach ($cases as [$role, $permissions]) {
            $user = $this->makeUser($this->city, $role, $permissions);
            $this->assertRefused($user, $role);
        }

        // ...nor a contract in another department, nor an IT contract that is
        // not in force today (ended, not started yet, or deactivated).
        $this->assertRefused($this->contractHolder('HR'), 'HR department contract');
        $this->assertRefused($this->contractHolder('IT', ['start_date' => now()->subYears(2), 'end_date' => now()->subDay()]), 'ended IT contract');
        $this->assertRefused($this->contractHolder('IT', ['start_date' => now()->addDay()]), 'future IT contract');
        $this->assertRefused($this->contractHolder('IT', ['is_active' => false]), 'inactive IT contract');
    }

    public function test_a_reader_whose_it_contract_ends_is_refused_on_the_next_update(): void
    {
        $user = $this->contractHolder('IT');

        $component = Livewire::actingAs($user)->test(FeedbackIndex::class)->assertOk();

        Contract::query()->where('employee_id', $user->employee_id)->update(['end_date' => now()->subDay()]);

        $component->set('category', 'idea')->assertForbidden();
    }

    public function test_an_it_contract_holder_reads_newest_first_whatever_the_active_role(): void
    {
        $author = $this->makeUser($this->city, 'employee');
        InAppFeedback::query()->insert([
            ['user_id' => $author->id, 'category' => 'problem', 'message' => 'Older note', 'route_name' => 'dashboard', 'locale' => 'en', 'role_name' => 'employee', 'created_at' => now()->subDays(3)],
            ['user_id' => $author->id, 'category' => 'idea', 'message' => 'Newer note', 'route_name' => 'dashboard', 'locale' => 'ar', 'role_name' => 'HR-ALP', 'created_at' => now()->subDay()],
        ]);

        foreach (['employee', 'HR-ALP'] as $role) {
            $developer = $this->contractHolder('IT', [], $role);

            $this->assertTrue($developer->canReadFeedback(), $role);
            $this->actingAs($developer)->get(route('feedback.index'))
                ->assertOk()
                ->assertSeeInOrder(['Newer note', 'Older note']);
        }

        Livewire::actingAs($developer)->test(FeedbackIndex::class)
            ->assertSeeInOrder(['Newer note', 'Older note'])
            ->set('category', 'problem')
            ->assertSee('Older note')
            ->assertDontSee('Newer note');
    }

    public function test_feedback_older_than_one_year_is_pruned_and_the_prune_is_scheduled(): void
    {
        InAppFeedback::query()->insert([
            ['message' => 'expired', 'locale' => 'en', 'created_at' => now()->subDays(366)],
            ['message' => 'recent', 'locale' => 'en', 'created_at' => now()->subDays(364)],
        ]);

        $this->artisan('model:prune', ['--model' => [InAppFeedback::class]])->assertSuccessful();

        $this->assertSame(['recent'], InAppFeedback::query()->pluck('message')->all());

        $scheduled = collect(app(Schedule::class)->events())
            ->contains(fn ($event) => str_contains($event->command ?? '', 'model:prune')
                && str_contains($event->command ?? '', 'InAppFeedback'));
        $this->assertTrue($scheduled, 'model:prune for InAppFeedback is not scheduled.');
    }

    public function test_deleting_the_author_keeps_the_note_without_the_link(): void
    {
        $user = $this->makeUser($this->city, 'employee');
        $this->send($user)->assertHasNoErrors();

        $user->delete();

        $this->assertNull(InAppFeedback::query()->sole()->user_id);
    }

    private function assertRefused(User $user, string $case): void
    {
        $this->assertFalse($user->canReadFeedback(), $case);
        $this->actingAs($user)->get(route('feedback.index'))->assertForbidden();
        Livewire::actingAs($user)->test(FeedbackIndex::class)->assertForbidden();
    }

    /** A user whose employee holds a contract on a position of the given department. */
    private function contractHolder(string $departmentCode, array $contract = [], string $role = 'employee'): User
    {
        $user = $this->makeUser($this->city, $role);

        $department = Department::query()->firstOrCreate(['code' => $departmentCode], ['name' => $departmentCode]);
        $center = Center::query()->create([
            'name_en' => 'Center '.uniqid(), 'name_ar' => 'مركز', 'city_id' => $this->city->id,
            'is_active' => true, 'weekends' => 'friday', 'open_at' => '08:00:00', 'close_at' => '16:00:00',
        ]);
        $position = Position::query()->forceCreate([
            'center_id' => $center->id,
            'city_id' => $this->city->id,
            'department_id' => $department->id,
            'job_level_id' => JobLevel::query()->forceCreate(['name_en' => 'Officer', 'name_ar' => 'مسؤول', 'grade' => 2])->id,
            'program_id' => Program::query()->forceCreate(['name' => 'Core', 'slug' => 'core-'.uniqid(), 'code' => 'C'.uniqid(), 'is_active' => true])->id,
            'title_en' => $departmentCode.' officer',
            'title_ar' => $departmentCode,
            'is_active' => true,
        ]);

        Contract::query()->forceCreate(array_merge([
            'employee_id' => $user->employee_id,
            'position_id' => $position->id,
            'contract_type_id' => ContractType::query()->forceCreate(['name_en' => 'Annual', 'name_ar' => 'سنوي'])->id,
            'start_date' => now()->subMonth(),
            'end_date' => now()->addYear(),
            'currency' => 2,
            'weekly_hours' => 40,
            'is_active' => true,
        ], $contract));

        return $user->fresh();
    }

    private function send(User $user, string $message = 'The leave form froze after I picked a date.', string $category = 'problem', ?string $route = 'dashboard')
    {
        return Livewire::actingAs($user)
            ->test(FeedbackForm::class, ['pageRoute' => $route])
            ->call('open')
            ->set('message', $message)
            ->set('category', $category)
            ->call('submit');
    }
}
