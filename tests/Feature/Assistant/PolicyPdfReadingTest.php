<?php

namespace Tests\Feature\Assistant;

use App\Livewire\Onboarding\RequiredPolicies;
use App\Models\PolicyDocument;
use App\Services\Assistant\GeminiClient;
use App\Services\Assistant\GeminiException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * A policy PDF is read through Gemini, and is either read whole or refused
 * with a reason: never kept cut short.
 */
class PolicyPdfReadingTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    private const GEMINI = 'generativelanguage.googleapis.com/*';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'assistant.gemini.key' => 'gemini-key',
            'assistant.gemini.model' => 'main-model',
            'assistant.gemini.fallback_models' => ['spare-model'],
            'assistant.gemini.retry_wait' => 0,
        ]);
    }

    private function turn(string $text, string $finish = 'STOP'): array
    {
        return ['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => $text]]], 'finishReason' => $finish]]];
    }

    public function test_a_long_pdf_is_read_on_from_where_the_answer_stopped(): void
    {
        Http::fake([self::GEMINI => Http::sequence()
            ->push($this->turn("المادة 1: الإجازة السنوية\nواحد وعشر", 'MAX_TOKENS'))
            ->push($this->turn("ون يوماً.\nالمادة 2: المرضية", 'MAX_TOKENS'))
            ->push($this->turn(' عشرة أيام.'))]);

        $text = app(GeminiClient::class)->transcribe('%PDF', 'application/pdf');

        $this->assertSame("المادة 1: الإجازة السنوية\nواحد وعشرون يوماً.\nالمادة 2: المرضية عشرة أيام.", $text);

        // Each follow-up carries what came before and asks to go on.
        $last = Http::recorded()->last()[0]->data();
        $this->assertCount(5, $last['contents']);
        $this->assertStringContainsString('Continue', $last['contents'][4]['parts'][0]['text']);
        $this->assertSame(65536, $last['generationConfig']['maxOutputTokens']);
    }

    public function test_any_other_stop_is_a_failure_not_half_a_text(): void
    {
        Http::fake([self::GEMINI => Http::response($this->turn('المادة 1', 'RECITATION'))]);

        $this->expectException(GeminiException::class);

        app(GeminiClient::class)->transcribe('%PDF', 'application/pdf');
    }

    public function test_a_document_that_never_ends_is_refused(): void
    {
        config(['assistant.gemini.transcribe_rounds' => 2]);
        Http::fake([self::GEMINI => Http::response($this->turn('...', 'MAX_TOKENS'))]);

        try {
            app(GeminiClient::class)->transcribe('%PDF', 'application/pdf');
            $this->fail('A transcription that never ends was kept.');
        } catch (GeminiException $e) {
            $this->assertSame(413, $e->getCode());
        }

        Http::assertSentCount(2);
    }

    public function test_a_busy_model_is_tried_again_before_the_spare_one(): void
    {
        Http::fake([self::GEMINI => Http::sequence()
            ->push(['error' => ['message' => 'high demand']], 503)
            ->push(['error' => ['message' => 'high demand']], 503)
            ->push($this->turn('النص'))]);

        $this->assertSame('النص', app(GeminiClient::class)->transcribe('%PDF', 'application/pdf'));

        $models = collect(Http::recorded())->map(fn (array $pair) => str_contains($pair[0]->url(), 'main-model') ? 'main' : 'spare')->all();
        $this->assertSame(['main', 'main', 'main'], $models);
    }

    public function test_when_every_model_stays_busy_the_spare_one_gets_its_tries_too(): void
    {
        Http::fake([self::GEMINI => Http::response(['error' => ['message' => 'high demand']], 503)]);

        try {
            app(GeminiClient::class)->transcribe('%PDF', 'application/pdf');
            $this->fail('No error for a model that never answered.');
        } catch (GeminiException $e) {
            $this->assertTrue($e->isTemporary());
        }

        Http::assertSentCount(6);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'spare-model'));
    }

    public function test_the_chat_still_tries_each_model_once(): void
    {
        Http::fake([self::GEMINI => Http::response(['error' => ['message' => 'high demand']], 503)]);

        try {
            app(GeminiClient::class)->generate([['role' => 'user', 'parts' => [['text' => 'Hi']]]]);
        } catch (GeminiException) {
        }

        Http::assertSentCount(2);
    }

    public function test_hr_is_told_why_a_pdf_was_not_taken(): void
    {
        $hr = $this->makeUser($this->makeCity('SYR', '000'), 'HR-SYR', ['employees.update']);
        $pdf = fn () => UploadedFile::fake()->createWithContent('Leave policy.pdf', '%PDF-1.7');

        Http::fake([self::GEMINI => Http::response(['error' => ['message' => 'high demand']], 503)]);
        Livewire::actingAs($hr)->test(RequiredPolicies::class)
            ->set('upload', $pdf())->call('save')
            ->assertHasErrors('upload')
            ->assertSee(__('onboarding.required-policies.pdf-busy'));

        config(['assistant.gemini.key' => null]);
        Livewire::actingAs($hr)->test(RequiredPolicies::class)
            ->set('upload', $pdf())->call('save')
            ->assertSee(__('onboarding.required-policies.pdf-unavailable'));

        $this->assertSame(0, PolicyDocument::query()->count());
    }

    public function test_hr_uploads_a_pdf_and_its_whole_text_is_kept(): void
    {
        $hr = $this->makeUser($this->makeCity('SYR', '000'), 'HR-SYR', ['employees.update']);

        Http::fake([self::GEMINI => Http::sequence()
            ->push($this->turn('الإجازة السنوية واحد', 'MAX_TOKENS'))
            ->push($this->turn(' وعشرون يوماً.'))]);

        Livewire::actingAs($hr)->test(RequiredPolicies::class)
            ->set('upload', UploadedFile::fake()->createWithContent('Leave policy.pdf', '%PDF-1.7'))
            ->call('save')
            ->assertHasNoErrors();

        $document = PolicyDocument::query()->sole();
        $this->assertSame('Leave policy', $document->title);
        $this->assertSame('application/pdf', $document->mime_type);
        $this->assertSame('الإجازة السنوية واحد وعشرون يوماً.', $document->content);
    }

    public function test_the_check_command_reads_a_test_pdf(): void
    {
        Http::fake(function (Request $request) {
            preg_match('/HCHR-CHECK-\d{4}/', base64_decode($request->data()['contents'][0]['parts'][0]['inlineData']['data']), $token);

            return Http::response($this->turn($token[0] ?? 'nothing'));
        });

        $this->artisan('policies:pdf-check')->expectsOutputToContain('read the test PDF correctly')->assertSuccessful();
    }

    public function test_the_check_command_fails_when_gemini_refuses_this_host(): void
    {
        Http::fake([self::GEMINI => Http::response(['error' => ['message' => 'User location is not supported for the API use.']], 400)]);

        $this->artisan('policies:pdf-check')->expectsOutputToContain('refuses this host')->assertFailed();
    }

    public function test_the_check_command_fails_when_gemini_answers_something_else(): void
    {
        Http::fake([self::GEMINI => Http::response($this->turn('I cannot see a document.'))]);

        $this->artisan('policies:pdf-check')->expectsOutputToContain('not with the test PDF')->assertFailed();
    }

    public function test_the_check_command_fails_without_a_key(): void
    {
        config(['assistant.gemini.key' => null]);
        Http::fake();

        $this->artisan('policies:pdf-check')->expectsOutputToContain('GEMINI_API_KEY is not set')->assertFailed();
        Http::assertNothingSent();
    }
}
