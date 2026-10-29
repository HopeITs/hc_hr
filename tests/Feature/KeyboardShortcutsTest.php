<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Role;
use App\Models\User;
use App\Support\KeyboardShortcuts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role as BaseRole;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * Prompt 10: "g" then a letter opens a page of the active role's menu, and
 * "?" lists the keys in a modal dialog. Which key presses count at all (not
 * while typing, not with Ctrl/Cmd/Alt, not inside dialogs and menus) is
 * resources/js/shortcuts-guard.js, unit-tested by tests/js (npm run test:js).
 */
class KeyboardShortcutsTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    private City $aleppo;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->aleppo = $this->makeCity('ALP', '001');
    }

    public function test_each_letter_opens_the_page_the_active_roles_menu_has(): void
    {
        $this->assertSame(
            ['d' => 'dashboard', 'l' => 'nav.my-space.leaves'],
            $this->pages($this->userWithRole('employee')),
        );
        $this->assertSame(
            ['d' => 'dashboard', 'l' => 'nav.my-team.leaves', 'c' => 'nav.my-team.contracts', 'i' => 'nav.inbox'],
            $this->pages($this->userWithRole('manager')),
        );
        $this->assertSame(
            ['d' => 'dashboard', 'e' => 'nav.hr.employees', 'l' => 'nav.hr.leaves', 'c' => 'nav.hr.contracts', 'i' => 'nav.inbox'],
            $this->pages($this->userWithRole('HR-ALP')),
        );
        // No recruitment page in this menu: "g r" is offered to nobody.
        $this->assertArrayNotHasKey('r', $this->pages($this->userWithRole('super-admin')));
    }

    public function test_a_letter_goes_with_its_permission_and_comes_back_with_it(): void
    {
        $hr = $this->makeUser($this->aleppo, 'HR-ALP', ['employees.view', 'leaves.view-own']);
        $this->assertArrayHasKey('e', KeyboardShortcuts::goTo($hr));

        BaseRole::findByName('HR-ALP', 'web')->revokePermissionTo('employees.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertArrayNotHasKey('e', KeyboardShortcuts::goTo($hr->fresh()));

        BaseRole::findByName('HR-ALP', 'web')->givePermissionTo('employees.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertArrayHasKey('e', KeyboardShortcuts::goTo($hr->fresh()));
    }

    public function test_every_key_opens_its_page_for_the_role(): void
    {
        foreach (['employee', 'manager', 'HR-ALP'] as $roleName) {
            $user = $this->userWithRole($roleName);

            foreach (KeyboardShortcuts::goTo($user) as $letter => $key) {
                $status = $this->actingAs($user)->get($key['href'])->getStatusCode();
                $this->assertNotContains($status, [403, 404, 500], "{$roleName}: g {$letter} → {$key['href']}");
            }
        }
    }

    public function test_the_list_is_a_modal_dialog_and_the_browser_gets_only_the_roles_keys(): void
    {
        $employee = $this->userWithRole('employee');
        $html = $this->actingAs($employee)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('data-testid="keyboard-shortcuts"', $html);
        $this->assertMatchesRegularExpression('/role="dialog"\s+aria-modal="true"\s+aria-labelledby="keyboard-shortcuts-title"/', $html);
        $this->assertStringContainsString('<h2 id="keyboard-shortcuts-title"', $html);
        // Focus trapped and returned; Escape belongs to the dialog, not to the window.
        $this->assertStringContainsString('x-trap.inert.noscroll="open"', $html);
        $this->assertStringContainsString('x-on:keydown.escape.prevent.stop="close()"', $html);
        $this->assertStringContainsString('aria-label="'.e(__('navigation.shortcuts.close')).'"', $html);

        // Listed: the employee's two letters, nothing else.
        $this->assertStringContainsString('data-go-to="d"', $html);
        $this->assertStringContainsString('data-go-to="l"', $html);
        foreach (['e', 'c', 'i', 'r'] as $letter) {
            $this->assertStringNotContainsString('data-go-to="'.$letter.'"', $html);
        }

        // Handed to the browser: the same two, and where they lead.
        $this->assertSame(
            ['d' => route('dashboard'), 'l' => route('leaves.index')],
            $this->keysHandedToTheBrowser($html),
        );
    }

    public function test_the_list_reads_in_both_languages(): void
    {
        $hr = $this->userWithRole('HR-ALP');

        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            $html = $this->blade('<x-keyboard-shortcuts />')->__toString();

            $this->assertStringContainsString(e(__('navigation.shortcuts.title')), $html);
            $this->assertStringContainsString(e(__('navigation.shortcuts.then')), $html);
            // Each page under the menu's own name in this language.
            foreach (KeyboardShortcuts::goTo($hr) as $key) {
                $this->assertStringContainsString(e($key['label']), $html);
            }
        }
    }

    /** @return array<string, string> letter => menu item id */
    private function pages(User $user): array
    {
        return array_map(fn (array $key) => $key['id'], KeyboardShortcuts::goTo($user));
    }

    /** The letter => URL map in x-data="keyboardShortcuts(@js(...))". */
    private function keysHandedToTheBrowser(string $html): array
    {
        $this->assertSame(1, preg_match("/keyboardShortcuts\\(JSON\\.parse\\('(.*?)'\\)\\)/", $html, $match));

        return json_decode(json_decode('"'.$match[1].'"'), true);
    }

    /** A user whose active role carries its real baseline permissions (App\Models\Role). */
    private function userWithRole(string $roleName): User
    {
        $employee = $this->makeEmployee($this->aleppo, uniqid('keys.', true));
        $user = User::query()->create(['employee_id' => $employee->id, 'email' => uniqid('keys-', true).'@example.test', 'password' => 'password']);
        $role = Role::findOrCreate($roleName, 'web');
        $user->assignRole($role);
        $user->setActiveRole($role->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($user->fresh());

        return $user->fresh();
    }
}
