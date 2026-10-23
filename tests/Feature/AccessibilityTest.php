<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Guards the accessibility wiring that lives in the shared Blade components.
 *
 * These are cheap render-level assertions on purpose: the components are used
 * by hundreds of views, so a regression here degrades the whole application at
 * once and is invisible to every other test in the suite.
 */
class AccessibilityTest extends TestCase
{
    public function test_input_links_to_its_error_message_when_validation_fails(): void
    {
        $html = $this->renderWithErrors(
            '<x-forms.input id="first_name" model="form.first_name" />',
            ['form.first_name' => ['The first name field is required.']],
        );

        $this->assertStringContainsString('aria-invalid="true"', $html);
        $this->assertStringContainsString('aria-describedby="first_name-error"', $html);
    }

    public function test_input_is_not_marked_invalid_when_there_is_no_error(): void
    {
        $html = $this->renderWithErrors('<x-forms.input id="first_name" model="form.first_name" />');

        $this->assertStringNotContainsString('aria-invalid', $html);
        $this->assertStringNotContainsString('aria-describedby', $html);
    }

    public function test_error_message_id_matches_the_input_it_describes(): void
    {
        $html = $this->renderWithErrors(
            '<x-forms.input id="first_name" model="form.first_name" />'
            .'<x-forms.input-error field="form.first_name" input-id="first_name" />',
            ['form.first_name' => ['The first name field is required.']],
        );

        // The describedby target must actually exist, or the link is dead.
        $this->assertStringContainsString('aria-describedby="first_name-error"', $html);
        $this->assertStringContainsString('id="first_name-error"', $html);
        $this->assertStringContainsString('role="alert"', $html);
    }

    public function test_required_input_exposes_its_required_state(): void
    {
        $html = $this->renderWithErrors('<x-forms.input id="email" model="form.email" required />');

        $this->assertStringContainsString('aria-required="true"', $html);
        $this->assertStringContainsString('required', $html);
    }

    public function test_required_label_has_a_text_alternative_for_the_asterisk(): void
    {
        $html = $this->renderWithErrors('<x-forms.input-label for="email" required>Email</x-forms.input-label>');

        // The asterisk itself must be hidden, with a readable word in its place.
        $this->assertStringContainsString('aria-hidden="true"', $html);
        $this->assertMatchesRegularExpression('/sr-only[^>]*>\(\s*required\s*\)/i', $html);
    }

    public function test_field_caption_is_not_a_label_element(): void
    {
        $html = $this->renderWithErrors('<x-field-caption>Employee</x-field-caption>');

        // A <label> with no control is announced as an orphaned form field.
        $this->assertStringNotContainsString('<label', $html);
        $this->assertStringContainsString('Employee', $html);
    }

    public function test_every_image_in_the_view_layer_declares_an_alt_attribute(): void
    {
        $offenders = [];

        foreach ($this->bladeFiles() as $path) {
            $contents = file_get_contents($path);

            // Neutralise Blade and Alpine expressions first: an unescaped "->"
            // inside {{ }} would otherwise terminate the tag match early and
            // hide a real alt attribute.
            $contents = preg_replace('/\{\{.*?\}\}/s', 'EXPR', $contents);
            $contents = preg_replace('/\{!!.*?!!\}/s', 'EXPR', $contents);

            preg_match_all('/<img\b[^>]*>/s', $contents, $matches);

            foreach ($matches[0] as $tag) {
                if (! preg_match('/\balt\s*=/', $tag)) {
                    $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
                }
            }
        }

        $this->assertSame([], array_values(array_unique($offenders)),
            'Every <img> needs an alt attribute (use alt="" for decorative images).');
    }

    public function test_the_app_layout_exposes_a_skip_link_to_the_main_landmark(): void
    {
        $layout = file_get_contents(resource_path('views/components/layouts/app.blade.php'));

        $this->assertStringContainsString('href="#main-content"', $layout);
        $this->assertStringContainsString('id="main-content"', $layout);

        // A7: no full-screen preloader covers ready content (it used to hold
        // every page for at least 550 ms, hidden from assistive technology
        // but not from anyone else).
        $this->assertStringNotContainsString('page-gif-overlay', $layout);
        $this->assertStringNotContainsString('<video', $layout);
    }

    public function test_accessibility_strings_are_translated_in_every_locale(): void
    {
        $keys = ['skip-to-content', 'main-content', 'required-field', 'error-prefix', 'close-dialog'];

        foreach (['en', 'ar'] as $locale) {
            $strings = require resource_path("lang/{$locale}/general.php");

            foreach ($keys as $key) {
                $this->assertArrayHasKey($key, $strings['a11y'] ?? [],
                    "Missing a11y.{$key} translation for [{$locale}].");
                $this->assertNotSame('', trim($strings['a11y'][$key]));
            }
        }
    }

    public function test_form_modal_is_a_modal_dialog_named_by_its_heading(): void
    {
        $html = $this->renderModal('<x-modal.dialog id="leaveForm" title="Request leave" wire:model="showModal">Body</x-modal.dialog>');

        $this->assertStringContainsString('role="dialog"', $html);
        $this->assertStringContainsString('aria-modal="true"', $html);
        $this->assertStringContainsString('aria-labelledby="leaveForm-title"', $html);
        $this->assertMatchesRegularExpression('/<h2 id="leaveForm-title"[^>]*>\s*Request leave/', $html);
    }

    public function test_modal_traps_focus_hides_the_page_and_locks_scroll(): void
    {
        $html = $this->renderModal('<x-modal id="plain" label="Users" wire:model="open">Body</x-modal>');

        // The Alpine focus plugin bundled with Livewire: trap, inert background,
        // scroll lock; initial focus is placed by the component itself.
        $this->assertStringContainsString('x-trap.inert.noscroll.noautofocus="show"', $html);
        $this->assertStringContainsString('aria-label="Users"', $html);

        // Escape belongs to the dialog that has focus. A window listener also
        // closed a form sitting underneath the confirm dialog.
        $this->assertStringContainsString('x-on:keydown.escape="if ($event.defaultPrevented || $store.confirm?.open) return;', $html);
        $this->assertStringNotContainsString('keydown.escape.window', $html);
    }

    public function test_modal_close_button_has_a_translated_name_in_every_locale(): void
    {
        foreach (['en' => 'Close dialog', 'ar' => 'إغلاق النافذة'] as $locale => $name) {
            app()->setLocale($locale);

            $html = $this->renderModal('<x-modal.dialog id="m" title="T" wire:model="show">Body</x-modal.dialog>');

            $this->assertMatchesRegularExpression(
                '/<button type="button"\s+x-on:click="close\(\)"\s+aria-label="'.preg_quote($name, '/').'"/u',
                $html,
                "Close button is unnamed in [{$locale}].",
            );
        }
    }

    public function test_radios_of_one_group_get_unique_stable_ids_and_a_shared_name(): void
    {
        $template = '<x-forms.radio-input wire:model.live="form.type" value="day">Day</x-forms.radio-input>'
            .'<x-forms.radio-input wire:model.live="form.type" value="hour">Hour</x-forms.radio-input>';

        $html = $this->renderWithErrors($template);

        preg_match_all('/<input[^>]*\bid="([^"]+)"/', $html, $ids);
        preg_match_all('/<label for="([^"]+)"[^>]*>(Day|Hour)</', $html, $labels);

        $this->assertSame(['radio-formtype-day', 'radio-formtype-hour'], $ids[1]);
        // Each label names its own radio, not the first one on the page.
        $this->assertSame($ids[1], $labels[1]);
        $this->assertSame(2, substr_count($html, 'name="form.type"'));

        // Stable: Livewire morphing must see the same ids on every render.
        $this->assertSame($html, $this->renderWithErrors($template));
    }

    public function test_an_explicit_radio_id_and_name_are_respected(): void
    {
        $html = $this->renderWithErrors('<x-forms.radio-input id="accept" name="decision" value="1">Accept</x-forms.radio-input>');

        $this->assertStringContainsString('id="accept"', $html);
        $this->assertStringContainsString('name="decision"', $html);
        $this->assertStringContainsString('<label for="accept"', $html);
        $this->assertStringNotContainsString('id="btn"', $html);
    }

    public function test_radio_group_is_a_fieldset_whose_error_describes_it(): void
    {
        $template = '<x-forms.radio-group legend="Action" error="form.status" required>'
            .'<x-forms.radio-input wire:model="form.status" value="2">Accept</x-forms.radio-input>'
            .'</x-forms.radio-group>';

        $html = $this->renderWithErrors($template, ['form.status' => ['Choose an action.']]);

        $this->assertMatchesRegularExpression('/<fieldset[^>]*aria-describedby="radio-group-formstatus-error"/', $html);
        $this->assertMatchesRegularExpression('/<legend[^>]*>\s*Action/', $html);
        $this->assertMatchesRegularExpression('/id="radio-group-formstatus-error"[^>]*>Choose an action\./', $html);

        $clean = $this->renderWithErrors($template);
        $this->assertStringNotContainsString('aria-describedby', $clean);
    }

    public function test_no_view_nests_a_radio_input_inside_another_label(): void
    {
        $offenders = [];

        foreach ($this->bladeFiles() as $path) {
            // radio-input renders its own <label>; a wrapping one is invalid
            // HTML and gives the radio two competing names.
            if (preg_match('/<label\b[^>]*>\s*<x-forms\.radio-input\b/', file_get_contents($path))) {
                $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
            }
        }

        $this->assertSame([], $offenders);
    }

    /** Render a modal; @entangle needs the surrounding Livewire component's id. */
    private function renderModal(string $template): string
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

    /** Render a Blade string with an error bag bound, the way a failed form does. */
    private function renderWithErrors(string $template, array $errors = []): string
    {
        $bag = new ViewErrorBag;

        if ($errors !== []) {
            $bag->put('default', new \Illuminate\Support\MessageBag($errors));
        }

        View::share('errors', $bag);

        return Blade::render($template);
    }

    /** @return iterable<string> */
    private function bladeFiles(): iterable
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(resource_path('views')),
        );

        foreach ($iterator as $file) {
            if (str_ends_with($file->getPathname(), '.blade.php')) {
                yield $file->getPathname();
            }
        }
    }
}
