<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/**
 * Quiet hints (<x-forms.hint>) and typed examples (data-typewriter, read by
 * resources/js/typewriter.js) on the shared input and textarea components.
 */
class FormHintsTest extends TestCase
{
    public function test_input_without_the_new_props_renders_as_before(): void
    {
        $html = $this->render('<x-forms.input id="reason" wire:model="form.reason" placeholder="Why" />');

        $this->assertStringNotContainsString('data-typewriter', $html);
        $this->assertStringNotContainsString('aria-describedby', $html);
        $this->assertStringNotContainsString('data-hint', $html);
        $this->assertStringNotContainsString('group/field', $html);
        $this->assertStringContainsString('placeholder="Why"', $html);
    }

    public function test_input_with_examples_carries_them_and_starts_on_the_first(): void
    {
        $html = $this->render('<x-forms.input id="reason" wire:model="form.reason" :examples="[\'Family visit\', \'Exam\']" />');

        $this->assertSame(['Family visit', 'Exam'], $this->typewriterList($html));
        $this->assertStringContainsString('placeholder="Family visit"', $html);
    }

    public function test_an_explicit_placeholder_wins_over_the_first_example(): void
    {
        $html = $this->render('<x-forms.input id="reason" placeholder="Why" :examples="[\'Family visit\']" />');

        $this->assertStringContainsString('placeholder="Why"', $html);
        $this->assertSame(['Family visit'], $this->typewriterList($html));
    }

    public function test_examples_resolve_from_a_lang_key_in_both_languages(): void
    {
        app()->setLocale('en');
        $en = $this->typewriterList($this->render('<x-forms.input id="reason" examples="examples.leave_reason" />'));

        app()->setLocale('ar');
        $ar = $this->typewriterList($this->render('<x-forms.input id="reason" examples="examples.leave_reason" />'));

        $this->assertNotEmpty($en);
        $this->assertSame(__('examples.leave_reason', [], 'en'), $en);
        $this->assertSame(__('examples.leave_reason', [], 'ar'), $ar);
        $this->assertNotSame($en, $ar);
    }

    public function test_an_unknown_lang_key_adds_no_examples(): void
    {
        $html = $this->render('<x-forms.input id="reason" examples="examples.nothing_here" />');

        $this->assertStringNotContainsString('data-typewriter', $html);
    }

    public function test_input_hint_is_linked_with_aria_describedby(): void
    {
        $html = $this->render('<x-forms.input id="work_phone" hint="10 digits, starting with 09." />');

        $this->assertStringContainsString('aria-describedby="work_phone-hint"', $html);
        $this->assertMatchesRegularExpression('/<p id="work_phone-hint"[^>]*data-hint-mode="always"/', $html);
        $this->assertStringContainsString('text-small text-ink-2', $html);
        $this->assertStringContainsString('10 digits, starting with 09.', $html);
    }

    public function test_focus_only_hint_is_hidden_until_the_field_has_focus(): void
    {
        $html = $this->render('<x-forms.input id="reason" hint="A few words." :hint-always="false" />');

        $this->assertStringContainsString('group/field', $html);
        $this->assertStringContainsString('data-hint-mode="focus"', $html);
        $this->assertStringContainsString('hidden group-focus-within/field:block', $html);
    }

    public function test_hint_and_error_are_both_described_hint_first(): void
    {
        $html = $this->render(
            '<x-forms.input id="first_name" model="form.first_name" hint="As on the ID card." />',
            ['form.first_name' => ['Required.']],
        );

        $this->assertStringContainsString('aria-describedby="first_name-hint first_name-error"', $html);
        $this->assertStringContainsString('aria-invalid="true"', $html);
    }

    public function test_a_callers_own_aria_describedby_is_kept(): void
    {
        $html = $this->render('<x-forms.input id="x" aria-describedby="x-note" hint="Hint." />');

        $this->assertStringContainsString('aria-describedby="x-note x-hint"', $html);
        $this->assertSame(1, substr_count($html, 'aria-describedby='));
    }

    public function test_textarea_without_the_new_props_renders_as_before(): void
    {
        $html = $this->render('<x-forms.textarea id="description_en" wire:model="form.description_en" />');

        $this->assertStringContainsString('class="hc-field"', $html);
        $this->assertStringContainsString('rows="3"', $html);
        $this->assertStringNotContainsString('data-typewriter', $html);
        $this->assertStringNotContainsString('aria-describedby', $html);
    }

    public function test_textarea_with_examples_and_a_hint(): void
    {
        $html = $this->render(
            '<x-forms.textarea id="description_ar" dir="rtl" :examples="__(\'examples.news.description\', [], \'ar\')"'
            .' hint="Say what, when and where." :hint-always="false" />',
        );

        $this->assertSame(__('examples.news.description', [], 'ar'), $this->typewriterList($html));
        $this->assertStringContainsString('aria-describedby="description_ar-hint"', $html);
        $this->assertStringContainsString('id="description_ar-hint"', $html);
        $this->assertStringContainsString('dir="rtl"', $html);
    }

    public function test_every_examples_list_exists_in_both_languages(): void
    {
        $en = require resource_path('lang/en/examples.php');
        $ar = require resource_path('lang/ar/examples.php');

        $this->assertSame($this->shape($en), $this->shape($ar));
    }

    public function test_the_wired_views_ask_for_examples_and_hints(): void
    {
        $views = [
            'livewire/employee/leave/form.blade.php' => ['examples.leave_reason', 'examples.hints.leave_reason'],
            // The staff dialog (/leaves?new=1) and HR's form: the ones people actually open.
            'livewire/employee/leave/index.blade.php' => ['examples.leave_reason', 'examples.hints.leave_reason'],
            'livewire/employee/leave/all.blade.php' => ['examples.leave_reason', 'examples.hints.leave_reason'],
            'livewire/employee/tabs/personal-info-tab.blade.php' => ['examples.first_name', 'examples.hints.name'],
            'livewire/employee/tabs/contact-info-fields.blade.php' => ['examples.phone', 'examples.hints.phone'],
            'livewire/news/_form-fields.blade.php' => ['examples.news.title', 'examples.news.description'],
            'livewire/layout/global-search.blade.php' => ['data-typewriter', 'examples.global_search'],
        ];

        foreach ($views as $view => $needles) {
            $source = file_get_contents(resource_path('views/'.$view));

            foreach ($needles as $needle) {
                $this->assertStringContainsString($needle, $source, "$view should use $needle");
            }
        }
    }

    public function test_the_typewriter_script_is_loaded_and_respects_reduced_motion(): void
    {
        $this->assertStringContainsString("import './typewriter.js';", file_get_contents(resource_path('js/app.js')));

        $script = file_get_contents(resource_path('js/typewriter.js'));
        $this->assertStringContainsString('prefers-reduced-motion', $script);
        $this->assertStringContainsString('document.hidden', $script);
        $this->assertStringNotContainsString('.value =', $script);
    }

    /** @return list<string> */
    private function typewriterList(string $html): array
    {
        $this->assertMatchesRegularExpression('/data-typewriter="([^"]*)"/', $html);
        preg_match('/data-typewriter="([^"]*)"/', $html, $match);

        return json_decode(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5), true);
    }

    /** @return array<int|string, mixed> */
    private function shape(array $lang): array
    {
        $shape = [];

        foreach ($lang as $key => $value) {
            $shape[$key] = is_array($value) && array_is_list($value) ? 'list' : (is_array($value) ? $this->shape($value) : 'string');
        }

        ksort($shape);

        return $shape;
    }

    private function render(string $template, array $errors = []): string
    {
        $bag = new ViewErrorBag;

        if ($errors !== []) {
            $bag->put('default', new MessageBag($errors));
        }

        View::share('errors', $bag);

        return Blade::render($template);
    }
}
