<?php

namespace Tests\Feature;

use App\Livewire\Layout\Header;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * M1: the shared shell on phones (header, notification panel, dialogs as full-screen sheets).
 *
 * Geometry itself (no page-level horizontal scroll, bar flush with the bottom edge, panel inside the
 * viewport) was measured in a real browser, see docs/ux/m1-shell-leave-notes.md. What is pinned here is
 * what a render can prove and a refactor can silently break: which controls exist at which size, where
 * the phone-only links went, and that the sheet/dialog breakpoints stay exact complements.
 */
class PhoneShellTest extends TestCase
{
    use RefreshDatabase;

    public function test_secondary_header_links_are_hidden_on_phones_but_reachable_from_the_account_menu(): void
    {
        $user = User::factory()->create();

        $html = Livewire::actingAs($user)->test(Header::class)->html();

        // The three icon links leave the top bar below `sm` (it could not hold them at 375px)...
        $this->assertSame(3, substr_count($html, 'max-sm:hidden inline-flex'));

        // ...and each of them is still one tap away, with a 44px target, in the account menu.
        $menu = substr($html, strpos($html, 'sm:hidden">'));
        foreach (['docs.google.com/document', 'hcsyria.org/home', 'docs.google.com/forms'] as $href) {
            $this->assertStringContainsString($href, $menu);
        }
        $this->assertSame(3, substr_count($menu, 'role="menuitem"'));
        $this->assertStringContainsString('min-h-11', $menu);
    }

    public function test_header_controls_that_stay_on_phones_are_44px_targets(): void
    {
        $html = Livewire::actingAs(User::factory()->create())->test(Header::class)->html();

        // Hamburger, language switch and account button.
        $this->assertStringContainsString('size-11 place-items-center', $html);
        $this->assertSame(2, substr_count($html, 'h-11 sm:h-9'));
        $this->assertStringContainsString('size-11 sm:size-10', $html);

        // The bell and the tour button are separate components with the same rule.
        $this->assertStringContainsString('size-11 sm:size-9', $html);
        $this->assertStringContainsString('size-11 sm:size-9', Blade::render('<x-tour.button/>'));
    }

    public function test_the_notification_panel_is_pinned_inside_the_viewport_on_phones(): void
    {
        $html = Livewire::actingAs(User::factory()->create())->test(Header::class)->html();

        // Anchored to the bell it ran 1px off the edge at 375px (and 25px at 320px); on phones it
        // is fixed between 12px gutters instead, and keeps the old anchoring from `sm`.
        $this->assertStringContainsString('max-sm:fixed max-sm:inset-x-3', $html);
        $this->assertStringContainsString('sm:absolute sm:end-0', $html);
        $this->assertStringContainsString('calc(100dvh-10rem)', $html);
    }

    public function test_a_dialog_is_a_full_screen_sheet_on_phones_and_unchanged_otherwise(): void
    {
        $html = $this->render('<x-modal.dialog id="leaveForm" title="Request leave" wire:model="open">Body</x-modal.dialog>');

        // Phone: no gutter, dvh height (not vh: the browser bars and keyboard move it), square corners,
        // title bar that stays while the form scrolls.
        foreach (['p-0', 'sheet:min-h-dvh', 'sheet:rounded-none', 'sheet:sticky', 'sheet:top-0', 'sheet:scroll-pb-28'] as $class) {
            $this->assertStringContainsString($class, $html);
        }

        // Desktop and tablet keep the capped, centred, rounded dialog, now under the complementary variant.
        foreach (['dialog:max-w-2xl', 'dialog:items-center', 'dialog:p-6', 'dialog:rounded-surface', 'dialog:overflow-hidden'] as $class) {
            $this->assertStringContainsString($class, $html);
        }

        // Editing guard and close behaviour are untouched by the layout change.
        $this->assertStringContainsString('role="dialog"', $html);
        $this->assertStringContainsString('aria-modal="true"', $html);
        $this->assertStringContainsString('x-on:click="guard || close()"', $html);
    }

    public function test_sheet_and_dialog_breakpoints_are_exact_complements(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        // sheet = narrower than sm, or a short landscape phone; dialog = everything else. If one is
        // edited without the other a width exists where a modal is neither (or both).
        $normalized = preg_replace('/\s+/', ' ', $css);

        // Each variant is a block with one @slot per media query: a comma list inside a single
        // @custom-variant was compiled by Tailwind to its first clause only (landscape phones lost
        // the sheet), so the queries must stay separate blocks.
        foreach ([
            '@custom-variant sheet { @media (max-width: 639.98px) { @slot; } @media (max-height: 500px) and (max-width: 950px) { @slot; } }',
            '@custom-variant dialog { @media (min-width: 640px) and (min-height: 501px) { @slot; } @media (min-width: 951px) { @slot; } }',
        ] as $variant) {
            $this->assertStringContainsString($variant, $normalized);
        }
    }

    public function test_the_leave_request_sheet_has_no_nested_scroll_area_and_a_pinned_action_bar(): void
    {
        $view = file_get_contents(resource_path('views/livewire/employee/leave/index.blade.php'));

        // A viewport-height cap on a phone sheet hid the Submit button below a second scroller.
        $this->assertStringNotContainsString('max-h-[60vh]', $view);
        $this->assertStringContainsString('dialog:max-h-[60dvh]', $view);

        // Submit and Cancel live in one bar the sheet pins; Cancel is a plain button (never submits).
        $this->assertMatchesRegularExpression('/<x-modal\.actions data-testid="leave-form-actions">.*?wire:click="confirmSave".*?x-on:click="close\(\)"/s', $view);
    }

    public function test_every_editable_leave_dialog_uses_the_pinned_action_bar(): void
    {
        foreach (['index', 'all', 'balance'] as $view) {
            $source = file_get_contents(resource_path("views/livewire/employee/leave/{$view}.blade.php"));

            $this->assertStringContainsString('<x-modal.actions', $source, "{$view} lost its pinned action bar");
            $this->assertStringNotContainsString('space-x-2 justify-end', $source, "{$view} still lays its buttons out with a direction-blind space-x");
        }

        $bar = $this->render('<x-modal.actions><button>Save</button></x-modal.actions>');
        $this->assertStringContainsString('data-sheet-actions', $bar);
        $this->assertStringContainsString('sheet:sticky sheet:bottom-0', $bar);
        $this->assertStringContainsString('env(safe-area-inset-bottom)', $bar);
    }

    private function render(string $template): string
    {
        View::share('errors', new ViewErrorBag);
        View::share('__livewire', new class
        {
            public function getId(): string
            {
                return 'test-component';
            }
        });

        return Blade::render($template);
    }
}
