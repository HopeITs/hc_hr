<?php

namespace Tests\Feature;

use App\Enums\LeaveTypeCases;
use App\Livewire\Contract\ContractCreate;
use App\Livewire\Employee\Leave\MyLeaveRequestModule;
use App\Models\City;
use App\Models\User;
use App\Support\HelpTopics;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * D3: page-context help.
 *
 * AC-D3-01 asks that help answers a field's question without losing form
 * state. The browser evidence (docs/ux/d3-help-notes.md) shows it on screen;
 * what is pinned here is what makes it true and what a refactor could
 * silently break:
 *   - every topic a page offers exists, in both languages, with no pay figure;
 *   - the panel is rendered outside every Livewire component, carries all its
 *     topics with the page and has no wire:* binding, so opening, searching
 *     and reading cannot cause a round trip;
 *   - every "?" inside a form is a plain button (type="button", no wire:*),
 *     so it can neither submit the form nor reach its component, and the form
 *     keeps its values through the round trips the form itself makes.
 */
class HelpPanelTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    private City $city;

    protected function setUp(): void
    {
        parent::setUp();

        $this->city = $this->makeCity('ALP', '001');
    }

    // ── Registry and copy ─────────────────────────────────────────────────

    public function test_every_mapped_route_exists_and_maps_to_known_topics(): void
    {
        foreach (HelpTopics::ROUTES as $route => $topics) {
            $this->assertTrue(Route::has($route), "Help route [{$route}] is not defined");
            $this->assertNotEmpty($topics, "Help route [{$route}] lists no topic");

            foreach ($topics as $topic) {
                $this->assertArrayHasKey($topic, HelpTopics::TOPICS, "Route [{$route}] maps to unknown topic [{$topic}]");
            }
        }
    }

    public function test_the_required_topics_exist(): void
    {
        foreach (['leave-units', 'contract-types', 'auto-renew', 'position-dependencies'] as $topic) {
            $this->assertArrayHasKey($topic, HelpTopics::TOPICS);
        }
    }

    public function test_every_topic_has_the_same_copy_keys_in_english_and_arabic(): void
    {
        foreach (array_keys(HelpTopics::TOPICS) as $topic) {
            foreach (['en', 'ar'] as $locale) {
                foreach (HelpTopics::translationKeys($topic) as $key) {
                    $this->assertTrue(Lang::hasForLocale($key, $locale), "Missing [{$key}] for [{$locale}]");
                    $this->assertNotSame('', trim((string) trans($key, [], $locale)), "Empty [{$key}] for [{$locale}]");
                }

                // No stray extra point that one language shows and the other does not.
                $points = trans("help.topics.{$topic}.points", [], $locale);
                $this->assertCount(HelpTopics::TOPICS[$topic]['points'], $points, "[{$topic}] point count in [{$locale}]");
            }
        }

        foreach (array_keys(trans('help.panel', [], 'en')) as $key) {
            $this->assertTrue(Lang::hasForLocale("help.panel.{$key}", 'ar'), "Missing help.panel.{$key} for [ar]");
        }
        $this->assertSame(array_keys(trans('help.topics', [], 'en')), array_keys(trans('help.topics', [], 'ar')));
    }

    public function test_help_copy_holds_no_pay_amount(): void
    {
        foreach (['en', 'ar'] as $locale) {
            $text = json_encode(trans('help', [], $locale), JSON_UNESCAPED_UNICODE);

            $this->assertDoesNotMatchRegularExpression('/SYP|USD|\$|ل\.س|ليرة|دولار|salary|راتب/iu', $text, "[{$locale}]");
        }
    }

    public function test_a_page_defaults_to_its_own_topics_and_hides_contract_help_from_other_roles(): void
    {
        $hr = $this->makeUser($this->city, 'HR-ALP', ['contracts.view', 'contracts.create']);
        $employee = $this->makeUser($this->city, 'employee', ['leaves.create']);

        $forHr = HelpTopics::forRequest($this->requestFor('contracts.create'), $hr);
        $this->assertSame(['contract-types', 'position-dependencies', 'auto-renew'], $forHr['current']);
        $this->assertSame(array_keys(HelpTopics::TOPICS), array_column($forHr['topics'], 'key'));

        $forEmployee = HelpTopics::forRequest($this->requestFor('leaves.index'), $employee);
        $this->assertSame(['leave-units', 'leave-balance'], $forEmployee['current']);
        $this->assertSame(['leave-units', 'leave-balance'], array_column($forEmployee['topics'], 'key'));

        // A page with no topics of its own still offers the full list.
        $elsewhere = HelpTopics::forRequest($this->requestFor('settings.password'), $employee);
        $this->assertSame([], $elsewhere['current']);
        $this->assertNotEmpty($elsewhere['topics']);
    }

    #[DataProvider('locales')]
    public function test_topics_are_resolved_in_the_page_language(string $locale): void
    {
        app()->setLocale($locale);

        $topic = HelpTopics::topic('leave-units');

        $this->assertSame(trans('help.topics.leave-units.title', [], $locale), $topic['title']);
        $this->assertCount(HelpTopics::TOPICS['leave-units']['points'], $topic['points']);
    }

    // ── Shell ─────────────────────────────────────────────────────────────

    public function test_the_shell_renders_the_panel_and_header_button_for_a_signed_in_user(): void
    {
        $this->actingAs($this->makeUser($this->city, 'employee', ['leaves.create']));

        $html = $this->get(route('dashboard'))->assertOk()->getContent();
        $xpath = $this->xpath($html);

        $panels = $xpath->query('//*[@data-help-panel]');
        $this->assertSame(1, $panels->length, 'Exactly one help panel per page');

        /** @var DOMElement $panel */
        $panel = $panels->item(0);
        $this->assertStringContainsString('helpPanel(', $panel->getAttribute('x-data'));
        $this->assertSame(1, $xpath->query('.//*[@role="dialog" and @aria-modal="true" and @aria-labelledby="help-panel-title"]', $panel)->length);
        $this->assertSame(1, $xpath->query('//*[@data-help-button]')->length);

        // This page's own topics travel with it, so opening needs no request.
        $this->assertStringContainsString(__('help.topics.leave-balance.title'), html_entity_decode($panel->getAttribute('x-data')));
    }

    public function test_the_panel_sits_outside_every_livewire_component_and_binds_nothing_to_one(): void
    {
        $this->actingAs($this->makeUser($this->city, 'HR-ALP', ['contracts.view', 'contracts.create']));

        $html = $this->get(route('contracts.create'))->assertOk()->getContent();
        $xpath = $this->xpath($html);

        /** @var DOMElement $panel */
        $panel = $xpath->query('//*[@data-help-panel]')->item(0);
        $this->assertNotNull($panel);

        // No ancestor is a Livewire component, so nothing the panel does is morphed or sent.
        // (The page does have components — the composer, the header — so the query can match.)
        $this->assertGreaterThan(1, $xpath->query('//*[@*[name()="wire:id"]]')->length);
        $this->assertSame(0, $xpath->query('ancestor::*[@*[name()="wire:id"]]', $panel)->length);
        $this->assertSame([], $this->wireAttributes($panel), 'The help panel must not bind to Livewire');
        $this->assertStringNotContainsString('$wire', $panel->ownerDocument->saveHTML($panel));
    }

    public function test_guests_get_no_help_panel(): void
    {
        $this->get(route('login'))->assertDontSee('data-help-panel', false);
    }

    // ── Triggers inside forms ─────────────────────────────────────────────

    public function test_the_composer_help_triggers_cannot_submit_or_call_the_component(): void
    {
        app()->setLocale('en');
        $hr = $this->makeUser($this->city, 'HR-ALP', ['contracts.view', 'contracts.create']);
        $this->makePosition($this->city);

        $component = Livewire::actingAs($hr)
            ->test(ContractCreate::class)
            ->set('form.start_date', '2027-02-01')
            ->set('form.end_date', '2027-12-31')
            ->set('form.weekly_hours', 30);

        $triggers = $this->triggers($component->html());
        $this->assertSame(['position-dependencies', 'contract-types', 'auto-renew'], array_keys($triggers));

        foreach ($triggers as $trigger) {
            $this->assertPlainTrigger($trigger);
        }

        // Rendering with the triggers in place left every entered value alone.
        $component->assertSet('form.start_date', '2027-02-01')
            ->assertSet('form.end_date', '2027-12-31')
            ->assertSet('form.weekly_hours', 30);
    }

    public function test_the_leave_form_keeps_its_values_and_its_help_through_its_own_round_trips(): void
    {
        app()->setLocale('en');
        [$user, $annualId] = $this->leaveFixture();
        $date = $this->workingDay();

        $component = Livewire::actingAs($user)
            ->test(MyLeaveRequestModule::class)
            ->call('create')
            ->set('form.leave_balance_id', (string) $annualId)
            ->set('form.reason', 'Half-filled request')
            ->set('form.type', 'hour')
            ->set('form.leave_date', $date)
            ->set('form.start_hour', '10:00');

        $triggers = $this->triggers($component->html(), inside: '//form[@*[name()="wire:submit.prevent"]="confirmSave"]');

        // Beside the leave type, and beside the day/hour choice that annual leave adds.
        $this->assertSame(['leave-units'], array_keys($triggers));
        $this->assertSame(2, $this->countTriggers($component->html(), 'leave-units'));

        foreach ($this->allTriggers($component->html()) as $trigger) {
            $this->assertPlainTrigger($trigger);
        }

        // The live round trips the form makes itself (the unit radio is wire:model.live)
        // keep both the entries and the help beside them.
        $component->set('form.type', 'day')->set('form.type', 'hour')
            ->assertSet('form.leave_balance_id', (string) $annualId)
            ->assertSet('form.reason', 'Half-filled request')
            ->assertSet('form.leave_date', $date)
            ->assertSet('form.start_hour', '10:00')
            ->assertSet('showModal', true);
        $this->assertSame(2, $this->countTriggers($component->html(), 'leave-units'));
    }

    public function test_a_radio_group_without_help_renders_no_trigger(): void
    {
        $plain = Blade::render('<x-forms.radio-group legend="Unit"><span>x</span></x-forms.radio-group>');
        $withHelp = Blade::render('<x-forms.radio-group legend="Unit" help="leave-units"><span>x</span></x-forms.radio-group>');

        $this->assertStringNotContainsString('data-help-trigger', $plain);
        $this->assertStringContainsString('data-help-topic="leave-units"', $withHelp);

        // The "?" is outside the legend, so the group's name stays "Unit".
        $legend = $this->xpath($withHelp)->query('//legend')->item(0);
        $this->assertSame(0, $this->xpath($withHelp)->query('//legend//button')->length);
        $this->assertSame('Unit', trim($legend->textContent));
    }

    public static function locales(): array
    {
        return ['english' => ['en'], 'arabic' => ['ar']];
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function requestFor(string $route): Request
    {
        $request = Request::create('/');
        $request->setRouteResolver(fn () => Route::getRoutes()->getByName($route));

        return $request;
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }

    /** @return array<string, DOMElement> topic => first trigger, in document order */
    private function triggers(string $html, string $inside = ''): array
    {
        $found = [];

        foreach ($this->xpath($html)->query($inside.'//*[@data-help-trigger]') as $node) {
            $found[$node->getAttribute('data-help-topic')] ??= $node;
        }

        return $found;
    }

    /** @return list<DOMElement> */
    private function allTriggers(string $html): array
    {
        return iterator_to_array($this->xpath($html)->query('//*[@data-help-trigger]'), false);
    }

    private function countTriggers(string $html, string $topic): int
    {
        return $this->xpath($html)->query('//*[@data-help-trigger and @data-help-topic="'.$topic.'"]')->length;
    }

    private function assertPlainTrigger(DOMElement $trigger): void
    {
        $topic = $trigger->getAttribute('data-help-topic');

        $this->assertArrayHasKey($topic, HelpTopics::TOPICS);
        $this->assertSame('button', $trigger->tagName);
        $this->assertSame('button', $trigger->getAttribute('type'), "[{$topic}] would submit its form");
        $this->assertSame([], $this->wireAttributes($trigger), "[{$topic}] is bound to Livewire");
        $this->assertStringNotContainsString('$wire', $this->attr($trigger, 'x-on:click'));
        $this->assertStringContainsString('help-open', $this->attr($trigger, 'x-on:click'));
        $this->assertNotSame('', $trigger->getAttribute('aria-label'));

        // Never inside a label or legend: its name would join the field's name.
        for ($node = $trigger->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
            $this->assertNotContains($node->tagName, ['label', 'legend'], "[{$topic}] sits inside a {$node->tagName}");
        }
    }

    private function attr(DOMElement $element, string $name): string
    {
        foreach ($element->attributes as $attribute) {
            if ($attribute->nodeName === $name) {
                return $attribute->nodeValue;
            }
        }

        return '';
    }

    /** @return list<string> */
    private function wireAttributes(DOMElement $root): array
    {
        $found = [];

        foreach ([$root, ...iterator_to_array($root->getElementsByTagName('*'), false)] as $element) {
            foreach ($element->attributes as $attribute) {
                if (str_starts_with($attribute->nodeName, 'wire:')) {
                    $found[] = $element->tagName.'['.$attribute->nodeName.']';
                }
            }
        }

        return $found;
    }

    private function workingDay(): string
    {
        $day = now()->addMonth()->next(Carbon::TUESDAY);

        while ($day->month === 12) {
            $day->addWeek();
        }

        return $day->toDateString();
    }

    /** @return array{0: User, 1: int} */
    private function leaveFixture(): array
    {
        $employee = $this->makeEmployee($this->city, 'help-leave-subject');
        $position = $this->makePosition($this->city);
        $contract = $this->makeContractFor($employee, ['position_id' => $position->id, 'weekly_hours' => 40]);

        DB::table('leave_types')->insert([
            'id' => LeaveTypeCases::Annual,
            'name_en' => 'Annual Leave',
            'name_ar' => 'إجازة سنوية',
            'base_days' => 20,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $annualId = DB::table('leave_balances')->insertGetId([
            'contract_id' => $contract->id,
            'type_id' => LeaveTypeCases::Annual,
            'deserved' => 160,
            'used' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = $this->makeUser($this->city, 'employee', ['leaves.create', 'leaves.view-own'], $employee);

        return [$user, $annualId];
    }
}
