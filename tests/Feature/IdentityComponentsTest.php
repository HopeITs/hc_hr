<?php

namespace Tests\Feature;

use App\Enums\ContractStatus;
use App\Enums\EmployeeStatus;
use App\Enums\LeaveRequestStatus;
use App\Models\City;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Support\Status\HasStatusTone;
use App\Support\Status\StatusTone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Prompt 2 (visual identity — components): one status component, statuses
 * told apart without colour, no stray hue in any view, and the component
 * sheet that is the visual regression reference.
 */
class IdentityComponentsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Views that may still carry a forbidden hue for a reason outside this phase.
     * Each entry needs its reason; remove it as soon as the reason is gone.
     *
     * @var array<string, string>
     */
    private const HUE_ALLOWLIST = [];

    public function test_no_view_uses_a_hue_outside_the_identity(): void
    {
        $offenders = [];

        $finder = Finder::create()->files()->in(resource_path('views'))->name('*.php');

        foreach ($finder as $file) {
            $relative = str_replace('\\', '/', $file->getRelativePathname());

            if (array_key_exists($relative, self::HUE_ALLOWLIST)) {
                continue;
            }

            if (preg_match_all('/[\w:!\[\]-]*-(?:emerald|amber|green|yellow|blue)-\d{2,3}\b/', $file->getContents(), $m)) {
                $offenders[$relative] = array_values(array_unique($m[0]));
            }
        }

        $this->assertSame([], $offenders, 'Status and state are icon + words + fill shape: use <x-status> / <x-tag> and the identity tokens.');
    }

    public function test_every_status_enum_names_a_tone_an_icon_and_words(): void
    {
        foreach ($this->statusCases() as $enum => $cases) {
            $seen = [];

            foreach ($cases as $case) {
                $this->assertInstanceOf(HasStatusTone::class, $case);
                $this->assertInstanceOf(StatusTone::class, $case->tone());
                $this->assertNotSame('', $case->icon());

                foreach (['en', 'ar'] as $locale) {
                    app()->setLocale($locale);
                    $label = $case->label();
                    $this->assertNotSame('', $label);
                    $this->assertStringNotContainsString('.', $label, "{$enum}: untranslated key {$label}");
                }

                // Distinguishable in greyscale: no two states of one enum share tone AND icon.
                $shape = $case->tone()->value.'/'.$case->icon();
                $this->assertNotContains($shape, $seen, "{$enum}: two states look the same ({$shape})");
                $seen[] = $shape;
            }
        }
    }

    public function test_the_leave_status_mapping(): void
    {
        $this->assertSame(StatusTone::Wait, LeaveRequestStatus::of(LeaveRequestStatus::Pending)->tone());
        $this->assertSame(StatusTone::Done, LeaveRequestStatus::of(LeaveRequestStatus::Accepted)->tone());
        $this->assertSame(StatusTone::Stop, LeaveRequestStatus::of(LeaveRequestStatus::Rejected)->tone());
        // An unknown stored value reads as pending, as getLocalizedName() always did.
        $this->assertSame(StatusTone::Wait, LeaveRequestStatus::of(9)->tone());
        $this->assertSame(['value' => __('leaves.status.accepted')], LeaveRequestStatus::getLocalizedName(LeaveRequestStatus::Accepted));
    }

    public function test_the_status_component_renders_icon_words_and_shape(): void
    {
        $html = Blade::render('<x-status :status="$s" />', ['s' => LeaveRequestStatus::of(LeaveRequestStatus::Rejected)]);

        $this->assertStringContainsString('hc-chip--stop', $html);
        $this->assertStringContainsString('data-icon="x"', $html);
        $this->assertStringContainsString(__('leaves.status.rejected'), $html);

        // Explicit props, and an unknown tone falls back to waiting.
        $html = Blade::render('<x-status tone="nonsense" label="Hello" />');
        $this->assertStringContainsString('hc-chip--wait', $html);
        $this->assertStringContainsString('data-icon="clock"', $html);
        $this->assertStringContainsString('Hello', $html);

        $tag = Blade::render('<x-tag variant="late">Late</x-tag>');
        $this->assertStringContainsString('hc-tag--late', $tag);
        $this->assertStringContainsString('data-icon="flame"', $tag);

        // The legacy badge keeps its API and drops the colour.
        $badge = Blade::render('<x-badge color="green">Yes</x-badge>');
        $this->assertStringContainsString('hc-tag--soft', $badge);
        $this->assertStringNotContainsString('green', $badge);
    }

    public function test_the_button_component_watches_its_own_action(): void
    {
        $html = Blade::render('<x-button variant="outline" size="sm" wire:click="save(3)">Save</x-button>');

        $this->assertStringContainsString('hc-btn hc-btn--outline hc-btn--sm', $html);
        $this->assertStringContainsString('type="button"', $html);
        $this->assertStringContainsString('wire:loading.attr="disabled"', $html);
        $this->assertStringContainsString('wire:loading.delay.none.attr="aria-busy"', $html);
        $this->assertStringContainsString('wire:target="save"', $html);

        $link = Blade::render('<x-button href="/x">Go</x-button>');
        $this->assertStringContainsString('<a href="/x"', $link);
        $this->assertStringContainsString('wire:navigate', $link);
        $this->assertStringNotContainsString('wire:loading', $link);
    }

    public function test_the_component_sheet_needs_a_session(): void
    {
        $this->get(route('ui-sheet'))->assertRedirect();
    }

    public function test_the_component_sheet_renders_in_both_languages_under_testing(): void
    {
        $user = $this->userWithRole('employee');

        $this->actingAs($user)->get(route('ui-sheet'))
            ->assertOk()
            ->assertSee('data-testid="ui-sheet"', false)
            ->assertSee(__('ui.sheet.title'))
            ->assertSee('hc-chip--work', false)
            ->assertSee('hc-chip--stop', false);

        app()->setLocale('ar');
        $html = view('ui-sheet')->render();
        $this->assertStringContainsString('ورقة المكوّنات', $html);
        $this->assertStringContainsString(__('leaves.status.pending'), $html);
    }

    public function test_the_component_sheet_is_a_404_in_production(): void
    {
        $user = $this->userWithRole('super-admin');
        $this->app->detectEnvironment(fn () => 'production');

        $this->actingAs($user)->get(route('ui-sheet'))->assertNotFound();
    }

    /** @return array<string, list<HasStatusTone>> */
    private function statusCases(): array
    {
        return [
            LeaveRequestStatus::class => array_values(LeaveRequestStatus::getInstances()),
            EmployeeStatus::class => EmployeeStatus::cases(),
            ContractStatus::class => ContractStatus::cases(),
        ];
    }

    private function userWithRole(string $roleName): User
    {
        $city = City::query()->forceCreate([
            'slug' => 'TST',
            'name_en' => 'Test',
            'name_ar' => 'اختبار',
            'code' => '999',
            'is_active' => true,
        ]);
        $employee = Employee::query()->create([
            'city_id' => $city->id,
            'first_name_en' => 'Sheet',
            'first_name_ar' => 'ورقة',
            'last_name_en' => 'Tester',
            'last_name_ar' => 'اختبار',
            'username' => uniqid('sheet.', true),
            'is_active' => true,
        ]);
        $user = User::query()->create([
            'employee_id' => $employee->id,
            'email' => uniqid('sheet-', true).'@example.test',
            'password' => 'password',
        ]);
        $role = Role::findOrCreate($roleName, 'web');
        $user->assignRole($role);
        $user->roles()->updateExistingPivot($role->id, ['is_active' => true]);

        return $user->fresh();
    }
}
