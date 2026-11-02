<?php

namespace Tests\Feature\Assistant;

use App\Enums\MaritalStatus;
use App\Enums\Nationality;
use App\Models\City;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Services\Assistant\Drafts\DraftRegistry;
use App\Services\Assistant\Drafts\EmployeeDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * A new employee asked for in the chat: the model only drafts it through the
 * create screen's own form, and only the user's Confirm creates it, exactly
 * as the screen's Save does.
 */
class EmployeeDraftTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    private City $city;

    private City $elsewhere;

    private EmployeeDraft $draft;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Notification::fake();
        Role::findOrCreate('employee', 'web');

        $this->makeCity('SYR', '000');
        $this->city = $this->makeCity('ALP', '001');
        $this->elsewhere = $this->makeCity('HMS', '002');
        $this->draft = app(EmployeeDraft::class);
    }

    public function test_no_tools_without_the_create_permission(): void
    {
        $user = $this->makeUser($this->city, 'HR-ALP', ['employees.view']);

        $this->assertSame([], $this->draft->declarations($user));
        $this->assertArrayHasKey('error', $this->draft->call($user, 'draft_employee', $this->valid()));
        $this->assertNull(app(DraftRegistry::class)->owner($user, 'draft_employee'));
    }

    public function test_an_inactive_role_with_the_permission_gives_no_tools(): void
    {
        $user = $this->makeUser($this->city, 'HR-ALP', ['employees.view']);
        $user = $this->assignInactiveRole($user, 'HR-HMS', ['employees.create']);

        $this->assertSame([], $this->draft->declarations($user));
    }

    public function test_the_tools_and_options_follow_the_active_role(): void
    {
        $hr = $this->hr();

        $this->assertSame(['get_employee_form_options', 'draft_employee'], array_column($this->draft->declarations($hr), 'name'));
        $this->assertInstanceOf(EmployeeDraft::class, app(DraftRegistry::class)->owner($hr, 'draft_employee'));

        $options = $this->draft->call($hr, 'get_employee_form_options', []);

        $this->assertSame([$this->city->id], array_column($options['cities'], 'id'), 'Only the cities the role may create in, never headquarters.');
        $this->assertSame([1, 2], array_column($options['genders'], 'id'));
        $this->assertContains(MaritalStatus::Married, array_column($options['marital_statuses'], 'id'));
        $this->assertContains(Nationality::Syrian, array_column($options['nationalities'], 'id'));
    }

    public function test_missing_required_fields_are_reported_and_nothing_is_written(): void
    {
        $hr = $this->hr();
        $before = Employee::query()->count();

        $result = $this->draft->call($hr, 'draft_employee', ['first_name_en' => 'Rami']);

        $this->assertSame('invalid', $result['status']);
        $this->assertNotEmpty($result['errors']);
        foreach (['first_name_ar', 'last_name_en', 'mother_name_ar', 'city_id', 'gender', 'marital_status', 'national_number', 'username'] as $field) {
            $this->assertContains($field, $result['fields']);
        }
        $this->assertSame($before, Employee::query()->count());
    }

    public function test_the_screen_rules_apply_national_id_format_and_duplicates(): void
    {
        $hr = $this->hr();

        $short = $this->draft->call($hr, 'draft_employee', ['national_number' => '123'] + $this->valid());
        $this->assertSame('invalid', $short['status']);
        $this->assertContains('national_number', $short['fields']);

        $this->makeEmployee($this->city, 'someone.else', ['national_number' => '12345678909']);
        $taken = $this->draft->call($hr, 'draft_employee', $this->valid());
        $this->assertSame('invalid', $taken['status']);
        $this->assertContains('national_number', $taken['fields']);

        $username = $this->draft->call($hr, 'draft_employee', ['username' => 'someone.else', 'national_number' => '22345678909'] + $this->valid());
        $this->assertContains('username', $username['fields']);
    }

    public function test_a_valid_draft_writes_nothing(): void
    {
        $hr = $this->hr();
        $before = Employee::query()->count();

        $result = $this->draft->call($hr, 'draft_employee', ['national_number' => '١٢٣٤٥٦٧٨٩٠٩'] + $this->valid());

        $this->assertSame('drafted', $result['status'], json_encode($result));
        $this->assertSame('12345678909', $result['proposal']['fields']['national_number'], 'Normalised as the screen does.');
        $this->assertSame('Rami Haddad', $result['proposal']['name_en']);
        $this->assertSame($this->city->name_en, $result['proposal']['city']);
        $this->assertSame($before, Employee::query()->count());

        $card = $this->draft->card($result['proposal']);
        $this->assertSame('user', $card['icon']);
        $this->assertSame(__('assistant-employee.title'), $card['title']);
        $this->assertContains('رامي حداد', $card['rows']);
    }

    public function test_confirm_creates_the_employee_as_the_screen_does(): void
    {
        $hr = $this->hr();
        $proposal = $this->draft->call($hr, 'draft_employee', $this->valid())['proposal'];

        $result = $this->draft->submit($hr, $proposal, 'token-1');

        $employee = Employee::query()->where('username', 'rami.haddad')->sole();
        $this->assertFalse($employee->isDraft());
        $this->assertFalse($employee->is_active, 'Ready, awaiting contract: not active.');
        $this->assertSame($this->city->id, (int) $employee->city_id);
        $this->assertSame('رامي', $employee->first_name_ar);
        $this->assertSame($hr->id, $employee->last_saved_by);
        $this->assertNull($employee->account);
        $this->assertSame(route('employees.show', 'rami.haddad'), $result['url']);
        $this->assertNotEmpty($result['message']);
    }

    public function test_the_same_token_twice_creates_one_employee(): void
    {
        $hr = $this->hr();
        $proposal = $this->draft->call($hr, 'draft_employee', $this->valid())['proposal'];

        $first = $this->draft->submit($hr, $proposal, 'token-1');
        $second = $this->draft->submit($hr, $proposal, 'token-1');

        $this->assertSame(1, Employee::query()->where('username', 'rami.haddad')->count());
        $this->assertSame($first['url'], $second['url']);
    }

    public function test_a_city_the_user_may_not_use_is_refused(): void
    {
        $hr = $this->hr();
        $args = ['city_id' => $this->elsewhere->id] + $this->valid();

        $result = $this->draft->call($hr, 'draft_employee', $args);
        $this->assertSame('invalid', $result['status']);
        $this->assertContains('city_id', $result['fields']);

        // A proposal altered after drafting is refused on confirm too.
        $proposal = $this->draft->call($hr, 'draft_employee', $this->valid())['proposal'];
        $proposal['fields']['city_id'] = $this->elsewhere->id;

        try {
            $this->draft->submit($hr, $proposal, 'token-1');
            $this->fail('A city outside the role was accepted.');
        } catch (ValidationException) {
            $this->assertSame(0, Employee::query()->where('username', 'rami.haddad')->count());
        }
    }

    public function test_confirm_is_refused_once_the_permission_is_gone(): void
    {
        $hr = $this->hr();
        $proposal = $this->draft->call($hr, 'draft_employee', $this->valid())['proposal'];

        \Spatie\Permission\Models\Role::findByName('HR-ALP', 'web')->revokePermissionTo('employees.create');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $hr = $hr->fresh();

        try {
            $this->draft->submit($hr, $proposal, 'token-1');
            $this->fail('Created without the permission.');
        } catch (ValidationException $e) {
            $this->assertContains(__('assistant.draft.forbidden'), $e->errors()['draft']);
        }

        $this->assertSame(0, Employee::query()->where('username', 'rami.haddad')->count());
    }

    public function test_confirm_validates_again(): void
    {
        $hr = $this->hr();
        $proposal = $this->draft->call($hr, 'draft_employee', $this->valid())['proposal'];

        // Someone else took the national ID in the meantime.
        $this->makeEmployee($this->city, 'someone.else', ['national_number' => '12345678909']);

        $this->expectException(ValidationException::class);
        $this->draft->submit($hr, $proposal, 'token-1');
    }

    private function hr(): User
    {
        return $this->makeUser($this->city, 'HR-ALP', ['employees.create']);
    }

    /** @return array<string, mixed> */
    private function valid(): array
    {
        return [
            'first_name_en' => 'Rami', 'first_name_ar' => 'رامي',
            'last_name_en' => 'Haddad', 'last_name_ar' => 'حداد',
            'father_name_en' => 'Sami', 'father_name_ar' => 'سامي',
            'mother_name_en' => 'Rima', 'mother_name_ar' => 'ريما',
            'city_id' => $this->city->id,
            'gender' => 1,
            'marital_status' => MaritalStatus::Single,
            'nationality' => Nationality::Syrian,
            'national_number' => '12345678909',
            'username' => 'rami.haddad',
            'dob' => '1995-04-12',
            'address_en' => 'Aleppo',
        ];
    }
}
