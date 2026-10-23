<?php

namespace Tests\Feature;

use App\Livewire\Contract\ContractCreate;
use App\Livewire\Contract\ContractEdit;
use App\Models\City;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * A6: the composer keeps its three sections, completion counter and summary,
 * explains its dependencies in both languages, and never hands pay figures
 * to a role that may not see them.
 */
class ContractComposerGuidanceTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    private City $city;

    protected function setUp(): void
    {
        parent::setUp();

        $this->city = $this->makeCity('ALP', '001');
    }

    public function test_the_create_screen_keeps_its_three_sections_counter_and_summary(): void
    {
        app()->setLocale('en');

        Livewire::actingAs($this->hr())
            ->test(ContractCreate::class)
            ->assertOk()
            ->assertSeeInOrder([
                __('general.composer.assignment'),
                __('general.composer.schedule'),
                __('general.composer.pay'),
            ])
            // The termination step belongs to editing only.
            ->assertDontSee(__('general.composer.termination-hint'))
            // Counter wording and the summary card that sits beside the form.
            ->assertSee(__('general.composer.create'))
            ->assertSeeHtml('data-tour="contract.card"');
    }

    #[DataProvider('locales')]
    public function test_the_composer_explains_its_dependencies_in_each_language(string $locale): void
    {
        app()->setLocale($locale);

        Livewire::actingAs($this->hr())
            ->test(ContractCreate::class)
            ->assertSee(__('contracts.composer-help.location'))
            ->assertSee(__('contracts.composer-help.capacity'))
            ->assertSee(__('contracts.composer-help.status'))
            ->assertSee(__('contracts.composer-help.draft'));
    }

    #[DataProvider('locales')]
    public function test_the_edit_screen_explains_auto_renewal_to_hr(string $locale): void
    {
        app()->setLocale($locale);
        $contract = $this->makeContractFor($this->makeEmployee($this->city, 'renew-subject'));

        Livewire::actingAs($this->hr(['contracts.view', 'contracts.update']))
            ->test(ContractEdit::class, ['contract' => $contract])
            ->assertOk()
            ->assertSee(__('general.composer.termination'))
            ->assertSee(__('contracts.composer-help.auto-renew'));
    }

    public function test_every_guidance_text_exists_in_english_and_arabic(): void
    {
        $en = trans('contracts.composer-help', [], 'en');
        $ar = trans('contracts.composer-help', [], 'ar');

        $this->assertIsArray($en);
        $this->assertIsArray($ar);
        $this->assertSame(array_keys($en), array_keys($ar));

        foreach ($en as $key => $text) {
            $this->assertNotSame($text, $ar[$key], "contracts.composer-help.{$key} is not translated");
        }
    }

    public function test_hidden_pay_roles_receive_no_figure_when_editing(): void
    {
        $contract = $this->contractWithFigures();

        $component = Livewire::actingAs($this->makeUser($this->city, 'super-admin'))
            ->test(ContractEdit::class, ['contract' => $contract])
            ->assertSet('form.salary', null)
            ->assertSet('form.pay_tiers.0.amount', null);

        // html() carries the composer seed and the wire:snapshot payload.
        $this->assertNoFigures($component->html());
    }

    public function test_hidden_pay_roles_receive_no_figure_when_renewing(): void
    {
        $source = $this->contractWithFigures([
            'start_date' => now()->subMonths(3)->toDateString(),
            'end_date' => now()->addMonths(3)->toDateString(),
        ]);

        $component = Livewire::actingAs($this->makeUser($this->city, 'super-admin'))
            ->test(ContractCreate::class, ['contract' => $source])
            ->assertSet('form.salary', null);

        $this->assertNoFigures($component->html());
    }

    public function test_hr_does_receive_the_figures_it_may_see(): void
    {
        // The control for the two tests above: the same render does carry
        // the figures when the role is allowed them.
        $contract = $this->contractWithFigures();

        $html = Livewire::actingAs($this->hr(['contracts.view', 'contracts.update']))
            ->test(ContractEdit::class, ['contract' => $contract])
            ->html();

        $this->assertStringContainsString('98765', $html);
        $this->assertStringContainsString('43210', $html);
    }

    public static function locales(): array
    {
        return ['English' => ['en'], 'Arabic' => ['ar']];
    }

    private function hr(array $permissions = ['contracts.view', 'contracts.create']): User
    {
        return $this->makeUser($this->city, 'HR-ALP', $permissions);
    }

    private function contractWithFigures(array $overrides = []): Contract
    {
        $contract = $this->makeContractFor(
            $this->makeEmployee($this->city, 'pay-subject-'.uniqid()),
            array_merge(['salary' => 98765], $overrides),
        );

        $contract->payTiers()->create([
            'condition_en' => '60h/month',
            'condition_ar' => 'ستون ساعة شهريا',
            'amount' => 43210,
            'sort_order' => 0,
        ]);

        return $contract;
    }

    private function assertNoFigures(string $html): void
    {
        foreach (['98765', '98,765', '43210', '43,210'] as $figure) {
            $this->assertStringNotContainsString($figure, $html);
        }
    }
}
