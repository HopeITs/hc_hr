<?php

namespace Tests\Feature;

use App\Livewire\Layout\BottomNav;
use App\Models\City;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Services\WorkInbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * D2 phone bottom navigation: permission-aware, every link opens for the role
 * that sees it, the inbox count is the menu's, and the bar steps aside so it
 * never covers a focused field or a sticky save row (AC-D2-01, AC-D2-02).
 */
class BottomNavTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_role_gets_the_destinations_its_permissions_open(): void
    {
        $this->assertSame(['home', 'leaves', 'calendar', 'profile'], $this->keys($this->userWithRole('employee')));
        $this->assertSame(['home', 'leaves', 'inbox', 'calendar', 'profile'], $this->keys($this->userWithRole('manager')));
        $this->assertSame(['home', 'leaves', 'inbox', 'calendar', 'profile'], $this->keys($this->userWithRole('HR-ALP')));

        // No leave permission, no employee record: only Home.
        $bare = User::query()->create(['email' => 'bare@example.test', 'password' => 'password']);
        $role = Role::findOrCreate('staff', 'web');
        $bare->assignRole($role);
        $bare->setActiveRole($role->id);
        $this->assertSame(['home'], $this->keys($bare->fresh()));
    }

    public function test_every_link_opens_for_the_role_that_sees_it_in_both_languages(): void
    {
        foreach (['employee', 'manager', 'HR-ALP'] as $roleName) {
            $user = $this->userWithRole($roleName);

            foreach (['en', 'ar'] as $locale) {
                app()->setLocale($locale);
                $html = Livewire::actingAs($user)->test(BottomNav::class)->html();
                preg_match_all('/href="([^"]+)"/', $html, $links);

                $this->assertNotEmpty($links[1]);

                foreach ($links[1] as $href) {
                    $status = $this->actingAs($user)->get($href)->getStatusCode();
                    $this->assertNotContains($status, [403, 404, 500], "{$roleName} {$locale} → {$href}");
                }
            }
        }
    }

    public function test_the_inbox_count_is_the_menus_count_and_the_current_page_is_marked(): void
    {
        $manager = $this->userWithRole('manager');

        $component = Livewire::actingAs($manager)->test(BottomNav::class)->set('currentRoute', 'inbox');
        $items = collect($component->viewData('items'))->keyBy('key');

        $this->assertSame(app(WorkInbox::class)->count($manager), $items['inbox']['count']);
        $component->assertSeeHtml('data-testid="bottom-nav-inbox"')
            ->assertSeeHtml('aria-current="page"');
    }

    public function test_the_shell_carries_the_bar_the_safe_areas_and_the_connection_notice(): void
    {
        $html = $this->actingAs($this->userWithRole('employee'))->get(route('dashboard'))->getContent();

        $this->assertStringContainsString('data-testid="bottom-nav"', $html);
        $this->assertStringContainsString('viewport-fit=cover', $html);
        $this->assertStringContainsString('data-testid="offline-notice"', $html);
        // D2-PWA registers a worker that stores no private response (PwaInstallTest checks it).
    }

    /** AC-D2-02: the stylesheet makes the bar step aside for focused fields and sticky save rows. */
    public function test_the_bar_steps_aside_for_focused_fields_and_save_rows(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('html:has(.m1-action-bar) .app-bottom-nav', $css);
        $this->assertStringContainsString('html:has([data-tour="contract.save"]) .app-bottom-nav', $css);
        $this->assertMatchesRegularExpression('/body:has\(:is\(input[^)]*\), textarea, select[^)]*\):focus\) \.app-bottom-nav/', $css);
    }

    private function keys(User $user): array
    {
        return array_column(Livewire::actingAs($user)->test(BottomNav::class)->viewData('items'), 'key');
    }

    private function userWithRole(string $roleName): User
    {
        $city = City::query()->where('slug', 'ALP')->first()
            ?? City::query()->forceCreate(['slug' => 'ALP', 'name_en' => 'Aleppo', 'name_ar' => 'حلب', 'code' => '001', 'is_active' => true]);
        $employee = Employee::query()->create([
            'city_id' => $city->id, 'first_name_en' => 'Bar', 'first_name_ar' => 'شريط',
            'last_name_en' => 'Tester', 'last_name_ar' => 'اختبار', 'username' => uniqid('bar.', true), 'is_active' => true,
        ]);
        $user = User::query()->create(['employee_id' => $employee->id, 'email' => uniqid('bar-', true).'@example.test', 'password' => 'password']);
        $role = Role::findOrCreate($roleName, 'web');
        $user->assignRole($role);
        $user->setActiveRole($role->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}
