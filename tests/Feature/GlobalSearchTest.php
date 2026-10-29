<?php

namespace Tests\Feature;

use App\Livewire\Employee\Leave\MyLeaveRequestModule;
use App\Livewire\Layout\GlobalSearch;
use App\Models\City;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Support\Navigation;
use App\Support\SafeReturn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * C2: header search and the New button (interaction-contract.md §6).
 * Synthetic people in two cities; Directory-tier rules from access-policy.md.
 */
class GlobalSearchTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    private const SECRETS = ['NID-SEARCH-7777', '0944000111', 'private.sara@example.test', '424242'];

    private City $aleppo;

    private City $homs;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->aleppo = $this->makeCity('ALP', '001');
        $this->homs = $this->makeCity('HMS', '002');
    }

    /** AC-C2-01: an English name finds the person in the Arabic interface, and the reverse. */
    public function test_a_name_in_either_script_finds_the_person_in_either_interface(): void
    {
        $sara = $this->person($this->homs, 'Sara', 'Haddad', 'سارة', 'حداد');
        $viewer = $this->makeUser($this->aleppo, 'employee');

        app()->setLocale('ar');
        $this->search($viewer, 'Sara')->assertSee('سارة حداد')->assertSee('Sara Haddad');

        app()->setLocale('en');
        $this->search($viewer, 'سارة')->assertSee('Sara Haddad');

        // Both words must match, across first and last name, in either script.
        $this->search($viewer, 'سارة حداد')->assertSeeHtml('data-testid="search-person"');
        $this->search($viewer, 'Sara Khoury')->assertDontSeeHtml('data-testid="search-person"');

        $this->assertNotNull($sara);
    }

    /** Arabic letters typed interchangeably (أ/إ/ا, ة/ه, ى/ي) find the same names and pages. */
    public function test_arabic_spelling_variants_find_the_same_person_and_page(): void
    {
        $this->person($this->homs, 'Ahmad', 'Mustafa', 'أحمد', 'مصطفى');
        $this->person($this->homs, 'Sara', 'Haddad', 'سارة', 'حداد');
        $viewer = $this->makeUser($this->aleppo, 'employee');
        app()->setLocale('ar');

        $this->search($viewer, 'احمد مصطفي')->assertSee('أحمد مصطفى');
        $this->search($viewer, 'ساره')->assertSee('سارة حداد');

        // The page is «اجازاتي» (no hamza); a search typed with one still finds it.
        $this->search($this->userWithRole('employee'), 'إجاز')->assertSeeHtml('data-testid="search-page"');
    }

    /** Two people with the same name are told apart by position and city. */
    public function test_namesakes_show_their_city(): void
    {
        $this->person($this->aleppo, 'Rami', 'Saliba', 'رامي', 'صليبا');
        $this->person($this->homs, 'Rami', 'Saliba', 'رامي', 'صليبا');
        $viewer = $this->makeUser($this->aleppo, 'employee');

        app()->setLocale('en');
        $html = $this->search($viewer, 'Rami')->html();

        $this->assertSame(2, substr_count($html, 'data-testid="search-person"'));
        $this->assertStringContainsString('· <bdi>ALP</bdi>', $html);
        $this->assertStringContainsString('· <bdi>HMS</bdi>', $html);
    }

    /** AC-C2-02: the directory's row set, Directory columns only, nothing private. */
    public function test_results_never_include_former_employees_out_of_scope_or_private_fields(): void
    {
        $this->person($this->homs, 'Sara', 'Haddad', 'سارة', 'حداد', [
            'national_number' => 'NID-SEARCH-7777',
            'personal_phone' => '0944000111',
            'personal_email' => 'private.sara@example.test',
        ], salary: 424242);
        $this->person($this->aleppo, 'Ziad', 'Former', 'زياد', 'سابق', ['is_active' => false]);
        $this->person($this->homs, 'Nadim', 'Former', 'نديم', 'سابق', ['is_active' => false]);

        app()->setLocale('en');

        // An employee never learns that a former employee existed.
        $employee = $this->makeUser($this->aleppo, 'employee');
        $this->search($employee, 'Former')->assertDontSeeHtml('data-testid="search-person"');

        // Regional HR sees its own city's former employee, not another city's.
        $hr = $this->makeUser($this->aleppo, 'HR-ALP');
        $this->search($hr, 'Former')->assertSee('Ziad Former')->assertDontSee('Nadim');

        // Nationwide HR sees both.
        $this->search($this->makeUser($this->aleppo, 'HR-SYR'), 'Former')->assertSee('Ziad Former')->assertSee('Nadim Former');

        // No private or pay value in the HTML or the Livewire payload, for anyone.
        foreach ([$employee, $hr] as $viewer) {
            $component = $this->search($viewer, 'Sara');
            $payload = $component->html().json_encode($component->snapshot).json_encode($component->effects);
            foreach (self::SECRETS as $secret) {
                $this->assertStringNotContainsString($secret, $payload);
            }
        }
    }

    /** G-C-13 (F15): a volunteer awaiting a first contract is not "former"; someone who held one is. */
    public function test_only_someone_who_was_employed_is_labelled_former(): void
    {
        $this->person($this->aleppo, 'Ziad', 'Former', 'زياد', 'سابق', ['is_active' => false]);
        $waiting = $this->person($this->aleppo, 'Wael', 'Former', 'وائل', 'سابق', ['is_active' => false]);
        $waiting->contracts()->update(['type' => \App\Enums\ContractType::Draft]);

        app()->setLocale('en');
        $html = $this->search($this->makeUser($this->aleppo, 'HR-ALP'), 'Former')
            ->assertSee(['Ziad Former', 'Wael Former'])
            ->html();

        $label = '('.__('general.global-search.former').')';
        $this->assertSame(1, substr_count($html, $label));
        // Livewire's block markers aside, the label sits right after Ziad's name.
        $this->assertMatchesRegularExpression('/Ziad Former<\/bdi>\s*<span[^>]*>'.preg_quote(e($label), '/').'/', preg_replace('/<!--.*?-->/s', '', $html));
    }

    /** AC-C2-02: results follow the active role; a role switch shows the new role's results. */
    public function test_results_follow_the_active_role_after_a_switch(): void
    {
        $this->person($this->aleppo, 'Ziad', 'Former', 'زياد', 'سابق', ['is_active' => false]);
        $user = $this->makeUser($this->aleppo, 'employee');
        $hrRole = Role::findOrCreate('HR-ALP', 'web');
        $user->assignRole($hrRole);

        app()->setLocale('en');
        $component = Livewire::actingAs($user->fresh())->test(GlobalSearch::class)->set('q', 'Ziad');
        $component->assertDontSeeHtml('data-testid="search-person"');

        $user->fresh()->setActiveRole($hrRole->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // The same open search box, re-rendered after the switch.
        Livewire::actingAs($user->fresh())->test(GlobalSearch::class)->set('q', 'Ziad')->assertSee('Ziad Former');
    }

    /** Pages: only the active role's menu, by either language's name. */
    public function test_pages_come_from_the_active_roles_menu_in_both_languages(): void
    {
        $employee = $this->userWithRole('employee');

        app()->setLocale('ar');
        $this->search($employee, 'My leaves')->assertSeeHtml('data-testid="search-page"');

        app()->setLocale('en');
        $this->search($employee, 'Roles')->assertDontSeeHtml('data-testid="search-page"');
        $this->search($this->userWithRole('HR-SYR'), 'Roles')->assertSeeHtml('data-testid="search-page"');

        // C0-Q4 (answer 2): pages left out of a role's menu are not offered either.
        $this->search($employee, 'Positions')->assertDontSeeHtml('data-testid="search-page"');
        $this->search($this->userWithRole('manager'), 'Centers')->assertSeeHtml('data-testid="search-page"');
        $this->search($this->userWithRole('manager'), 'Positions')->assertDontSeeHtml('data-testid="search-page"');
    }

    /** Safe outcomes: too short, nothing found, wildcards, and the per-user limit. */
    public function test_short_empty_wildcard_and_throttled_searches_have_safe_outcomes(): void
    {
        $this->person($this->homs, 'Sara', 'Haddad', 'سارة', 'حداد');
        $viewer = $this->makeUser($this->aleppo, 'employee');
        app()->setLocale('en');

        $this->search($viewer, 'S')->assertSee(__('general.global-search.too-short', ['count' => 2]));
        $this->search($viewer, 'Qwerty')->assertSee('No people or pages found');
        // LIKE wildcards are not wildcards: "%%" lists nobody.
        $this->search($viewer, '%%')->assertDontSeeHtml('data-testid="search-person"');

        for ($i = 0; $i < GlobalSearch::PER_MINUTE; $i++) {
            RateLimiter::hit('global-search:'.$viewer->id);
        }
        $this->search($viewer, 'Sara')
            ->assertSet('throttled', true)
            ->assertSee(__('general.global-search.throttled'))
            ->assertDontSeeHtml('data-testid="search-person"');
    }

    /** New: only what the role may create; a contract needs pay visibility. */
    public function test_new_lists_only_what_the_active_role_may_start(): void
    {
        $ids = fn (User $user) => array_column(Navigation::newActions($user), 'id');

        $this->assertSame(['new.leave'], $ids($this->userWithRole('employee')));
        $this->assertSame(['new.leave', 'new.news'], $ids($this->userWithRole('manager')));
        $this->assertSame(['new.leave', 'new.leave-for-employee', 'new.employee', 'new.contract', 'new.news'], $ids($this->userWithRole('HR-ALP')));
        // super-admin is salary-blind: no contract.
        $this->assertNotContains('new.contract', $ids($this->userWithRole('super-admin')));

        // The header renders exactly those, and each opens for the role.
        $hr = $this->userWithRole('HR-ALP');
        $page = $this->actingAs($hr)->get(route('dashboard'))->assertOk();
        foreach (Navigation::newActions($hr) as $action) {
            $page->assertSee('data-new="'.$action['id'].'"', false);
            $this->actingAs($hr)->get(route($action['route'], $action['params']))->assertOk();
        }
    }

    /** AC-C2-03: New → Leave request opens the dialog; closing it unsent stays on the leaves page. */
    public function test_new_leave_request_opens_the_dialog_and_cancel_stays_on_the_page(): void
    {
        $user = $this->userWithRole('employee');

        $closed = Livewire::actingAs($user)
            ->withQueryParams(['new' => 1, 'from' => '/en/employees'])
            ->test(MyLeaveRequestModule::class)
            ->assertSet('showModal', true)
            ->assertSet('startNew', false)
            ->call('requestClose')
            ->assertSet('showModal', false);
        $this->assertArrayNotHasKey('redirect', $closed->effects);
    }

    /** Return destinations are internal paths of allowlisted routes only. */
    public function test_safe_return_accepts_only_internal_allowlisted_paths(): void
    {
        $employee = $this->userWithRole('employee');
        $path = fn (string $name) => parse_url(route($name), PHP_URL_PATH);

        $this->assertSame(url($path('leaves.index')), SafeReturn::resolve($path('leaves.index'), $employee));
        $this->assertSame(url($path('leaves.index').'?status=1'), SafeReturn::resolve($path('leaves.index').'?status=1', $employee));

        foreach ([
            '//evil.example/x', 'https://evil.example/', '\\\\evil', 'javascript:alert(1)', '', null, ['x'],
            '/does-not-exist', $path('access-management.index'), // not in an employee's menu
        ] as $bad) {
            $this->assertNull(SafeReturn::resolve($bad, $employee), 'accepted '.json_encode($bad));
        }

        $this->assertSame(url($path('access-management.index')), SafeReturn::resolve($path('access-management.index'), $this->userWithRole('HR-SYR')));
    }

    private function search(User $viewer, string $term)
    {
        return Livewire::actingAs($viewer)->test(GlobalSearch::class)->set('q', $term);
    }

    /** An employee holding a visible position (the directory's row set). */
    private function person(City $city, string $firstEn, string $lastEn, string $firstAr, string $lastAr, array $attributes = [], int $salary = 1500): Employee
    {
        $employee = $this->makeEmployee($city, strtolower($firstEn).'.'.uniqid(), array_merge([
            'first_name_en' => $firstEn, 'last_name_en' => $lastEn,
            'first_name_ar' => $firstAr, 'last_name_ar' => $lastAr,
        ], $attributes));

        $this->makeContractFor($employee, [
            'start_date' => today()->subMonth()->toDateString(),
            'end_date' => today()->addYear()->toDateString(),
            'salary' => $salary,
        ]);

        return $employee->fresh();
    }

    /** A user whose active role carries its real baseline permissions (App\Models\Role). */
    private function userWithRole(string $roleName): User
    {
        $employee = $this->makeEmployee($this->aleppo, uniqid('c2.', true));
        $user = User::query()->create(['employee_id' => $employee->id, 'email' => uniqid('c2-', true).'@example.test', 'password' => 'password']);
        $role = Role::findOrCreate($roleName, 'web');
        $user->assignRole($role);
        $user->setActiveRole($role->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}
