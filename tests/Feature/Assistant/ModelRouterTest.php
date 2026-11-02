<?php

namespace Tests\Feature\Assistant;

use App\Models\User;
use App\Services\Assistant\Assistant;
use App\Services\Assistant\ChatModel;
use App\Services\Assistant\GeminiClient;
use App\Services\Assistant\GeminiException;
use App\Services\Assistant\ModelRouter;
use App\Services\Assistant\OpenAiCompatibleClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * Several AI providers behind one ChatModel: the first that answers is used,
 * a failing one is skipped by the next questions, and tool calls survive the
 * trip through the OpenAI chat-completions format.
 */
class ModelRouterTest extends TestCase
{
    use CreatesContractFixtures;
    use RefreshDatabase;

    private const GEMINI = 'generativelanguage.googleapis.com/*';

    private const GROQ = 'api.groq.com/*';

    private const OPENROUTER = 'openrouter.ai/*';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'assistant.enabled' => true,
            'assistant.providers_order' => ['gemini', 'groq', 'openrouter', 'cerebras', 'mistral'],
            'assistant.gemini.key' => 'gemini-key',
            'assistant.providers.groq.key' => 'groq-key',
            'assistant.providers.openrouter.key' => null,
            'assistant.providers.cerebras.key' => null,
            'assistant.providers.mistral.key' => null,
        ]);
    }

    public function test_chat_model_resolves_to_the_router(): void
    {
        $this->assertInstanceOf(ModelRouter::class, app(ChatModel::class));
    }

    public function test_a_location_refusal_from_gemini_falls_through_to_groq(): void
    {
        Http::fake([
            self::GEMINI => Http::response(['error' => ['code' => 400, 'message' => 'User location is not supported for the API use.']], 400),
            self::GROQ => Http::response($this->groqText('Hello from Groq.')),
        ]);

        $turn = app(ModelRouter::class)->generate([['role' => 'user', 'parts' => [['text' => 'Hi']]]], 'Be brief.');

        $this->assertSame('Hello from Groq.', GeminiClient::text($turn));
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.groq.com/openai/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer groq-key')
            && $request['model'] === 'llama-3.3-70b-versatile'
            && $request['messages'] === [
                ['role' => 'system', 'content' => 'Be brief.'],
                ['role' => 'user', 'content' => 'Hi'],
            ]);
    }

    public function test_a_provider_on_cooldown_is_skipped_without_a_request(): void
    {
        Http::fake([
            self::GEMINI => Http::response(['error' => ['message' => 'User location is not supported for the API use.']], 400),
            self::GROQ => Http::response($this->groqText('Answer.')),
        ]);

        app(ModelRouter::class)->generate($this->question());
        app(ModelRouter::class)->generate($this->question());

        $this->assertSame(1, $this->sentTo('generativelanguage.googleapis.com'));
        $this->assertSame(2, $this->sentTo('api.groq.com'));
    }

    public function test_a_busy_provider_is_also_put_on_cooldown(): void
    {
        Http::fake([
            self::GEMINI => Http::response(['error' => ['message' => 'The model is overloaded.']], 503),
            self::GROQ => Http::response($this->groqText('Answer.')),
        ]);

        app(ModelRouter::class)->generate($this->question());
        $geminiCalls = $this->sentTo('generativelanguage.googleapis.com');
        app(ModelRouter::class)->generate($this->question());

        $this->assertSame($geminiCalls, $this->sentTo('generativelanguage.googleapis.com'));
    }

    public function test_the_provider_that_answered_keeps_answering_within_one_reply(): void
    {
        // A plain 400 puts no cooldown on Gemini: only stickiness keeps it out.
        Http::fake([
            self::GEMINI => Http::response(['error' => ['message' => 'Invalid argument.']], 400),
            self::GROQ => Http::response($this->groqText('Answer.')),
        ]);

        $conversation = app(ModelRouter::class)->conversation();
        $conversation->generate($this->question());
        $conversation->generate($this->question());

        $this->assertSame('groq', $conversation->current());
        $this->assertSame(1, $this->sentTo('generativelanguage.googleapis.com'));
        $this->assertSame(2, $this->sentTo('api.groq.com'));

        // The next reply starts from the top of the order again.
        app(ModelRouter::class)->conversation()->generate($this->question());
        $this->assertSame(2, $this->sentTo('generativelanguage.googleapis.com'));
    }

    public function test_a_tool_call_round_trips_through_the_openai_format(): void
    {
        config(['assistant.gemini.key' => null]);
        $user = $this->employeeUser(['leaves.view-own']);

        Http::fake([self::GROQ => Http::sequence()
            ->push(['choices' => [['message' => [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [
                    ['id' => 'provider-id-1', 'type' => 'function', 'function' => ['name' => 'get_my_profile', 'arguments' => '{}']],
                    ['id' => 'provider-id-2', 'type' => 'function', 'function' => ['name' => 'get_my_leave_requests', 'arguments' => '{"status":"pending"}']],
                ],
            ]]]])
            ->push($this->groqText('You are Test Person.'))]);

        $answer = app(Assistant::class)->reply($user, [], 'Who am I?');

        $this->assertSame('You are Test Person.', $answer->text);
        Http::assertSentCount(2);

        $first = Http::recorded()[0][0];
        $profile = collect($first['tools'])->firstWhere('function.name', 'get_my_profile');
        $this->assertSame('function', $profile['type']);
        $this->assertSame('object', $profile['function']['parameters']['type']);
        $this->assertStringContainsString('"properties":{}', $first->body());

        $messages = Http::recorded()[1][0]['messages'];
        $this->assertSame('system', $messages[0]['role']);
        $this->assertSame(['role' => 'user', 'content' => 'Who am I?'], $messages[1]);

        $assistant = $messages[2];
        $this->assertSame('assistant', $assistant['role']);
        $this->assertSame(['call00000', 'call00001'], array_column($assistant['tool_calls'], 'id'));
        $this->assertSame('{}', $assistant['tool_calls'][0]['function']['arguments']);
        $this->assertSame(['status' => 'pending'], json_decode($assistant['tool_calls'][1]['function']['arguments'], true));

        $this->assertSame(['tool', 'call00000'], [$messages[3]['role'], $messages[3]['tool_call_id']]);
        $this->assertStringContainsString('Test Person', $messages[3]['content']);
        $this->assertSame(['tool', 'call00001'], [$messages[4]['role'], $messages[4]['tool_call_id']]);
        $this->assertCount(5, $messages);
    }

    public function test_openai_tool_calls_become_gemini_function_calls_with_decoded_args(): void
    {
        $turn = OpenAiCompatibleClient::turn([
            'content' => 'Checking.',
            'tool_calls' => [
                ['id' => 'x', 'function' => ['name' => 'draft_leave_request', 'arguments' => '{"leave_balance_id":5,"reason":"سفر"}']],
                ['id' => 'y', 'function' => ['name' => 'get_my_profile', 'arguments' => '']],
            ],
        ]);

        $this->assertSame('Checking.', GeminiClient::text($turn));
        $this->assertSame([
            ['name' => 'draft_leave_request', 'args' => ['leave_balance_id' => 5, 'reason' => 'سفر']],
            ['name' => 'get_my_profile', 'args' => []],
        ], GeminiClient::functionCalls($turn));
    }

    public function test_gemini_only_fields_are_dropped_and_ids_match_responses_by_name(): void
    {
        $messages = OpenAiCompatibleClient::messages([
            ['role' => 'user', 'parts' => [['text' => 'Q']]],
            ['role' => 'model', 'parts' => [
                ['text' => 'thinking...', 'thought' => true],
                ['functionCall' => ['name' => 'a', 'args' => []], 'thoughtSignature' => 'sig'],
                ['functionCall' => ['name' => 'b', 'args' => ['n' => 1]]],
            ]],
            ['role' => 'user', 'parts' => [
                ['functionResponse' => ['name' => 'b', 'response' => ['ok' => true]]],
                ['functionResponse' => ['name' => 'a', 'response' => []]],
            ]],
        ]);

        $this->assertSame(['role' => 'assistant', 'content' => null, 'tool_calls' => [
            ['id' => 'call00000', 'type' => 'function', 'function' => ['name' => 'a', 'arguments' => '{}']],
            ['id' => 'call00001', 'type' => 'function', 'function' => ['name' => 'b', 'arguments' => '{"n":1}']],
        ]], $messages[1]);
        $this->assertSame(['role' => 'tool', 'tool_call_id' => 'call00001', 'content' => '{"ok":true}'], $messages[2]);
        $this->assertSame(['role' => 'tool', 'tool_call_id' => 'call00000', 'content' => '{}'], $messages[3]);
    }

    public function test_gemini_receives_empty_args_and_responses_as_json_objects(): void
    {
        Http::fake([self::GEMINI => Http::response(['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'Done.']]]]]])]);

        app(GeminiClient::class)->generate([
            ['role' => 'user', 'parts' => [['text' => 'Q']]],
            ['role' => 'model', 'parts' => [['functionCall' => ['name' => 'get_my_profile', 'args' => []]]]],
            ['role' => 'user', 'parts' => [['functionResponse' => ['name' => 'get_my_profile', 'response' => []]]]],
        ]);

        Http::assertSent(fn (Request $request) => str_contains($request->body(), '"args":{}')
            && str_contains($request->body(), '"response":{}')
            && ! str_contains($request->body(), '"args":[]'));
    }

    public function test_when_every_provider_fails_the_error_is_temporary(): void
    {
        config(['assistant.providers.openrouter.key' => 'or-key']);

        Http::fake([
            self::GEMINI => Http::response(['error' => ['message' => 'User location is not supported for the API use.']], 400),
            self::GROQ => Http::response(['error' => ['message' => 'Rate limit reached.']], 429),
            self::OPENROUTER => Http::response(['error' => ['message' => 'Upstream error.']], 502),
        ]);

        try {
            app(ModelRouter::class)->generate($this->question());
            $this->fail('Expected the router to give up.');
        } catch (GeminiException $e) {
            $this->assertTrue($e->isTemporary());
        }

        $this->assertSame(1, $this->sentTo('openrouter.ai'));
    }

    public function test_when_every_provider_is_cooling_down_the_busy_ones_are_tried_anyway(): void
    {
        config(['assistant.gemini.key' => null]);

        Http::fake([self::GROQ => Http::sequence()
            ->push(['error' => ['message' => 'Rate limit reached.']], 429)
            ->push($this->groqText('Later answer.'))]);

        try {
            app(ModelRouter::class)->generate($this->question());
        } catch (GeminiException) {
        }

        $turn = app(ModelRouter::class)->generate($this->question());

        $this->assertSame('Later answer.', GeminiClient::text($turn));
    }

    public function test_unconfigured_providers_are_skipped(): void
    {
        config(['assistant.gemini.key' => null]);

        Http::fake([
            self::GEMINI => Http::response([], 500),
            self::GROQ => Http::response($this->groqText('Only Groq.')),
            '*' => Http::response([], 500),
        ]);

        $this->assertSame('Only Groq.', GeminiClient::text(app(ModelRouter::class)->generate($this->question())));
        Http::assertSentCount(1);
    }

    public function test_the_assistant_is_available_with_only_a_groq_key(): void
    {
        config(['assistant.gemini.key' => null]);
        $this->assertTrue(app(Assistant::class)->isAvailable());

        config(['assistant.providers.groq.key' => null]);
        $this->assertFalse(app(Assistant::class)->isAvailable());
    }

    private function question(): array
    {
        return [['role' => 'user', 'parts' => [['text' => 'Hi']]]];
    }

    private function groqText(string $text): array
    {
        return ['choices' => [['message' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => 'stop']]];
    }

    private function sentTo(string $host): int
    {
        return Http::recorded(fn (Request $request) => str_contains($request->url(), $host))->count();
    }

    private function employeeUser(array $permissions = []): User
    {
        $city = $this->makeCity('ALP', '001');
        $employee = $this->makeEmployee($city, 'me');

        return $this->makeUser($city, 'router-test-staff', $permissions, $employee);
    }
}
