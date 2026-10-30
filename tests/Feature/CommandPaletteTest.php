<?php

namespace Tests\Feature;

use App\Livewire\Layout\GlobalSearch;
use App\Models\City;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Support\CommandPalette;
use App\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * Prompt 10: the Commands group of the Ctrl+K box. New commands are exactly
 * the role's New menu; matching works on both languages' names whatever the
 * interface language, with Arabic spellings folded (SearchText).
 */
class CommandPaletteTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    private City $aleppo;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->aleppo = $this->makeCity('ALP', '001');
    }

    public function test_new_commands_are_exactly_what_the_roles_new_menu_may_start(): void
    {
        foreach (['employee', 'manager', 'HR-ALP', 'super-admin'] as $roleName) {
            $user = $this->userWithRole($roleName);
            $new = array_values(array_filter(array_column(CommandPalette::for($user), 'id'), fn (string $id) => str_starts_with($id, 'new.')));

            $this->assertSame(array_column(Navigation::newActions($user), 'id'), $new, $roleName);
        }

        // Through the box, in either language: an employee is never offered to create an employee or a contract.
        $employee = $this->userWithRole('employee');
        foreach (['new employee', 'موظف جديد', 'new contract', 'عقد جديد'] as $term) {
            $html = $this->search($employee, $term)->html();
            $this->assertStringNotContainsString('data-command="new.employee"', $html, $term);
            $this->assertStringNotContainsString('data-command="new.contract"', $html, $term);
        }

        $hr = $this->userWithRole('HR-ALP');
        $this->search($hr, 'new employee')->assertSeeHtml('data-command="new.employee"');
        $this->search($hr, 'عقد جديد')->assertSeeHtml('data-command="new.contract"');
        // super-admin is salary-blind, and a contract is its pay clause.
        $this->search($this->userWithRole('super-admin'), 'new contract')->assertDontSeeHtml('data-command="new.contract"');
    }

    public function test_leave_and_its_arabic_spellings_find_the_same_command_in_both_interfaces(): void
    {
        $employee = $this->userWithRole('employee');

        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);

            foreach (['leave', 'LEAVE', 'اجازة', 'إجازة', 'اجازه', 'إجـازة', 'إِجَازَة', 'طلب اجازه', 'new leave', 'leave new'] as $term) {
                $this->search($employee, $term)->assertSeeHtml('data-command="new.leave"');
            }
        }

        // Shown under the interface language's name.
        app()->setLocale('ar');
        $this->search($employee, 'leave')->assertSee('طلب إجازة جديد')->assertSee(__('navigation.commands.title'));
        app()->setLocale('en');
        $this->search($employee, 'اجازة')->assertSee('New leave request');
    }

    public function test_the_page_commands_are_everyones_and_carry_what_they_do(): void
    {
        $employee = $this->userWithRole('employee');
        app()->setLocale('en');

        $this->search($employee, 'dark')->assertSeeHtml('data-command="theme"')->assertSeeHtml('data-action="theme"');
        $this->search($employee, 'الوضع الداكن')->assertSeeHtml('data-command="theme"');

        // Switch language: this page in the other language (kept from the page request), named in that language.
        $component = $this->search($employee, 'language');
        $this->assertStringContainsString('/ar', $component->get('otherLocaleUrl'));
        $component->assertSeeHtml('data-command="locale"')
            ->assertSeeHtml('data-action="locale"')
            ->assertSeeHtml('data-href="'.e($component->get('otherLocaleUrl')).'"')
            ->assertSeeHtml('lang="ar"')
            ->assertSee('العربية');
        $this->search($employee, 'عربي')->assertSeeHtml('data-command="locale"');

        // Customise: the dashboard, opened in its customise mode (the dashboard reads ?customise=1).
        $href = route('dashboard', ['customise' => 1]);
        $this->search($employee, 'customise')->assertSeeHtml('data-command="customise"')->assertSeeHtml('data-href="'.e($href).'"');
        $this->search($employee, 'customize dashboard')->assertSeeHtml('data-command="customise"');
        $this->search($employee, 'تخصيص')->assertSeeHtml('data-command="customise"');
        $this->actingAs($employee)->get($href)->assertOk();

        $this->search($employee, 'keyboard')->assertSeeHtml('data-command="shortcuts"')->assertSeeHtml('data-action="shortcuts"');
        $this->search($employee, 'اختصارات')->assertSeeHtml('data-command="shortcuts"');

        // A New action that returns to this page says so; the leave dialog closes in place and does not.
        $this->search($this->userWithRole('HR-ALP'), 'new employee')->assertSeeHtml('data-returns');
        $this->search($employee, 'new leave')->assertDontSeeHtml('data-returns');
    }

    public function test_commands_follow_pages_and_people_with_one_running_index(): void
    {
        $this->person($this->aleppo, 'Newman', 'Saliba', 'نيومان', 'صليبا');
        $manager = $this->userWithRole('manager');
        app()->setLocale('en');

        $html = $this->search($manager, 'new')->html();
        preg_match_all('/id="global-search-option-(\d+)"[^>]*?data-testid="([a-z-]+)"/', $html, $options);

        $types = $options[2];
        $this->assertSame(range(0, count($types) - 1), array_map('intval', $options[1]));
        $this->assertContains('search-page', $types);    // News
        $this->assertContains('search-person', $types);  // Newman
        $this->assertContains('search-command', $types); // New leave request, New news item
        // Pages, then people, then commands.
        $order = ['search-page' => 0, 'search-person' => 1, 'search-command' => 2];
        $ranks = array_map(fn (string $type) => $order[$type], $types);
        $sorted = $ranks;
        sort($sorted);
        $this->assertSame($sorted, $ranks);

        // The status line counts the commands too.
        $this->assertStringContainsString(trans_choice('general.global-search.count', count($types), ['count' => count($types)]), $html);
    }

    public function test_no_command_before_two_letters_or_among_the_recents(): void
    {
        $employee = $this->userWithRole('employee');

        $this->search($employee, 'n')->assertDontSeeHtml('data-testid="search-command"');
        Livewire::actingAs($employee)->test(GlobalSearch::class)->call('showRecents')->assertDontSeeHtml('data-testid="search-command"');
    }

    public function test_every_command_that_opens_a_page_opens_for_the_role(): void
    {
        foreach (['employee', 'manager', 'HR-ALP'] as $roleName) {
            $user = $this->userWithRole($roleName);

            foreach (CommandPalette::for($user) as $command) {
                if ($command['action'] === 'open') {
                    $this->actingAs($user)->get($command['href'])->assertOk();
                }
            }
        }
    }

    /** The people search folds stored names in SQL with the same letters: hamza on waw and on ya too. */
    public function test_people_are_found_with_hamza_on_waw_and_ya_typed_either_way(): void
    {
        $this->person($this->aleppo, 'Moamen', 'Raef', 'مؤمن', 'رئيف');
        $viewer = $this->makeUser($this->aleppo, 'employee');
        app()->setLocale('ar');

        foreach (['مؤمن', 'مومن', 'رئيف', 'رييف', 'مومن رييف'] as $term) {
            $this->search($viewer, $term)->assertSee('مؤمن رئيف');
        }
    }

    private function search(User $viewer, string $term)
    {
        return Livewire::actingAs($viewer)->test(GlobalSearch::class)->set('q', $term);
    }

    /** An employee holding a visible position (the directory's row set). */
    private function person(City $city, string $firstEn, string $lastEn, string $firstAr, string $lastAr): Employee
    {
        $employee = $this->makeEmployee($city, strtolower($firstEn).'.'.uniqid(), [
            'first_name_en' => $firstEn, 'last_name_en' => $lastEn,
            'first_name_ar' => $firstAr, 'last_name_ar' => $lastAr,
        ]);

        $this->makeContractFor($employee, [
            'start_date' => today()->subMonth()->toDateString(),
            'end_date' => today()->addYear()->toDateString(),
        ]);

        return $employee->fresh();
    }

    /** A user whose active role carries its real baseline permissions (App\Models\Role). */
    private function userWithRole(string $roleName): User
    {
        $employee = $this->makeEmployee($this->aleppo, uniqid('p10.', true));
        $user = User::query()->create(['employee_id' => $employee->id, 'email' => uniqid('p10-', true).'@example.test', 'password' => 'password']);
        $role = Role::findOrCreate($roleName, 'web');
        $user->assignRole($role);
        $user->setActiveRole($role->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}
