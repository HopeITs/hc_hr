<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\User;
use App\Services\ContractPdfRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * The export endpoint's contract with the UI (A6): who may fetch the printed
 * contract, what a failed render answers, and that retrying is harmless.
 *
 * The renderer is mocked here — these are HTTP-contract tests. Chrome itself is
 * exercised in production through browserless.io and diagnosed on a host with
 * `contracts:export-doctor`; the printed view is rendered for real (as HTML)
 * at the end of this file.
 */
class ContractExportTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    private const FAKE_PDF = "%PDF-1.7\n% synthetic\n%%EOF";

    private City $city;

    protected function setUp(): void
    {
        parent::setUp();

        $this->city = $this->makeCity('ALP', '001');

        // The printed layout is chosen by type id (config/contracts.php).
        ContractType::query()->forceCreate(['id' => 1, 'name_en' => 'Annual Contract', 'name_ar' => 'عقد سنوي']);
        ContractType::query()->forceCreate(['id' => 5, 'name_en' => 'Service Contract', 'name_ar' => 'عقد خدمة']);
    }

    // ── Authorization (AC-A6-02) ────────────────────────────────────────────

    public function test_city_hr_with_the_export_permission_gets_the_pdf(): void
    {
        $this->rendererReturns(self::FAKE_PDF);
        $contract = $this->contract(1);

        $response = $this->actingAs($this->hr())->get(route('contracts.export', $contract));

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame(self::FAKE_PDF, $response->getContent());
    }

    public function test_hr_of_another_city_is_refused(): void
    {
        $this->rendererMustNotRun();
        $contract = $this->contract(1);
        $otherCity = $this->makeCity('HMS', '002');

        $this->actingAs($this->makeUser($otherCity, 'HR-HMS', ['contracts.export']))
            ->get(route('contracts.export', $contract))
            ->assertForbidden();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function salaryBlindRoles(): array
    {
        return [
            // Holds every permission there is and is still refused by name.
            'super-admin' => ['super-admin'],
            // Not HR, so never shown pay figures, whatever it is granted.
            'general-manager' => ['general-manager'],
        ];
    }

    #[DataProvider('salaryBlindRoles')]
    public function test_a_role_that_may_not_see_pay_is_refused_the_pdf(string $role): void
    {
        $this->rendererMustNotRun();
        $contract = $this->contract(1);
        $user = $this->makeUser($this->city, $role, ['contracts.export', 'contracts.view']);

        $this->assertFalse($user->canViewSalary());

        $this->actingAs($user)->get(route('contracts.export', $contract))->assertForbidden();
    }

    public function test_the_permission_alone_is_required(): void
    {
        $this->rendererMustNotRun();

        $this->actingAs($this->makeUser($this->city, 'HR-ALP', ['contracts.view']))
            ->get(route('contracts.export', $this->contract(1)))
            ->assertForbidden();
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->rendererMustNotRun();

        $this->get(route('contracts.export', $this->contract(1)))->assertRedirect();
    }

    // ── Failure and retry (AC-A6-03) ───────────────────────────────────────

    public function test_a_render_that_throws_answers_503_with_a_plain_text_reason(): void
    {
        Log::spy();
        $this->partialMock(ContractPdfRenderer::class, function (MockInterface $mock): void {
            $mock->shouldReceive('render')->andThrow(new RuntimeException('Chrome did not write a PDF.'));
        });

        $response = $this->actingAs($this->hr())->get(route('contracts.export', $this->contract(1)));

        $response->assertStatus(503)->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $this->assertSame(__('contracts.export-failed'), $response->getContent());

        Log::shouldHaveReceived('error')->once();
    }

    public function test_a_render_that_yields_no_pdf_answers_503_rather_than_an_empty_200(): void
    {
        $this->rendererReturns('');

        $response = $this->actingAs($this->hr())->get(route('contracts.export', $this->contract(1)));

        $response->assertStatus(503);
        $this->assertSame(__('contracts.export-failed'), $response->getContent());
    }

    public function test_the_failure_reason_is_translated_for_arabic(): void
    {
        $this->rendererReturns('');
        app()->setLocale('ar');

        $response = $this->actingAs($this->hr())->get(route('contracts.export', $this->contract(5)));

        $response->assertStatus(503);
        $this->assertSame(trans('contracts.export-failed', [], 'ar'), $response->getContent());
    }

    public function test_a_failed_export_can_be_retried_and_never_writes_a_contract(): void
    {
        $contract = $this->contract(1);
        $before = $contract->fresh()->getAttributes();
        $calls = 0;

        $this->partialMock(ContractPdfRenderer::class, function (MockInterface $mock) use (&$calls): void {
            $mock->shouldReceive('render')->twice()->andReturnUsing(function () use (&$calls): string {
                return ++$calls === 1 ? throw new RuntimeException('transient') : self::FAKE_PDF;
            });
        });

        $hr = $this->hr();

        $this->actingAs($hr)->get(route('contracts.export', $contract))->assertStatus(503);
        $this->actingAs($hr)->get(route('contracts.export', $contract))->assertOk();

        $this->assertSame(1, Contract::query()->count());
        $this->assertSame($before, $contract->fresh()->getAttributes());
    }

    public function test_an_authorization_refusal_is_never_reported_as_a_render_failure(): void
    {
        $this->rendererMustNotRun();

        $this->actingAs($this->makeUser($this->city, 'super-admin'))
            ->get(route('contracts.export', $this->contract(1)))
            ->assertForbidden();
    }

    // ── Annual and service, EN and AR ──────────────────────────────────────

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function typesAndLocales(): array
    {
        return [
            'annual en' => [1, 'en'],
            'annual ar' => [1, 'ar'],
            'service en' => [5, 'en'],
            'service ar' => [5, 'ar'],
        ];
    }

    #[DataProvider('typesAndLocales')]
    public function test_each_type_and_locale_exports_through_the_same_contract(int $typeId, string $locale): void
    {
        $this->rendererReturns(self::FAKE_PDF);
        app()->setLocale($locale);
        $contract = $this->contract($typeId);

        $response = $this->actingAs($this->hr())->get(route('contracts.export', $contract));

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $category = $typeId === 1 ? 'annual' : 'service';
        $this->assertStringContainsString("-{$category}-", (string) $response->headers->get('Content-Disposition'));
    }

    /**
     * The real printed view (what Chrome is handed), rendered without Chrome:
     * it must render in both languages for both types and carry the figure.
     */
    #[DataProvider('typesAndLocales')]
    public function test_the_printed_view_renders_and_carries_the_contract_figures(int $typeId, string $locale): void
    {
        app()->setLocale($locale);
        $contract = $this->contract($typeId, ['salary' => 1234]);

        $html = app(ContractPdfRenderer::class)->view($contract)->render();

        $this->assertStringContainsString('dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"', $html);
        $this->assertMatchesRegularExpression('/1,?234/', $html);
        $this->assertStringContainsString($contract->employee->first_name_en, $html);
    }

    // ── Local preview ──────────────────────────────────────────────────────

    public function test_the_preview_does_not_exist_outside_the_local_environment(): void
    {
        $this->actingAs($this->hr())
            ->get(route('contracts.export-preview', $this->contract(1)))
            ->assertNotFound();
    }

    public function test_the_local_preview_is_held_to_the_export_gates(): void
    {
        $this->app['env'] = 'local';
        $contract = $this->contract(1, ['salary' => 1234]);

        $this->actingAs($this->hr())
            ->get(route('contracts.export-preview', $contract))
            ->assertOk()
            ->assertSee('1,234');

        foreach (['super-admin', 'general-manager'] as $role) {
            $this->actingAs($this->makeUser($this->city, $role, ['contracts.export', 'contracts.view']))
                ->get(route('contracts.export-preview', $contract))
                ->assertForbidden();
        }

        $this->actingAs($this->makeUser($this->makeCity('HMS', '002'), 'HR-HMS', ['contracts.export']))
            ->get(route('contracts.export-preview', $contract))
            ->assertForbidden();
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function hr(): User
    {
        return $this->makeUser($this->city, 'HR-ALP', ['contracts.export', 'contracts.view']);
    }

    private function contract(int $typeId, array $overrides = []): Contract
    {
        return $this->makeContractFor(
            $this->makeEmployee($this->city, 'export-'.uniqid(), ['first_name_en' => 'Synthetic']),
            array_merge(['contract_type_id' => $typeId], $overrides),
        );
    }

    private function rendererReturns(string $output): void
    {
        $this->partialMock(ContractPdfRenderer::class, function (MockInterface $mock) use ($output): void {
            $mock->shouldReceive('render')->andReturn($output);
        });
    }

    private function rendererMustNotRun(): void
    {
        $this->partialMock(ContractPdfRenderer::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('render');
        });
    }
}
