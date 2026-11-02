<?php

namespace Tests\Feature\Assistant;

use App\Livewire\Assistant\Chat;
use App\Models\City;
use App\Models\User;
use App\Services\Assistant\Assistant;
use App\Services\Assistant\GeminiException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * Puter.js: when no provider on the server can answer, the browser asks the
 * model round by round. The state stays in the session, the browser only
 * returns the model's message for the relay id it was given, and the tools
 * the model calls still run on the server, as the signed-in user.
 */
class PuterRelayTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    private ?City $city = null;

    protected function setUp(): void
    {
        parent::setUp();

        config(['assistant.puter.enabled' => true, 'assistant.puter.model' => 'gpt-5.4-nano']);
    }

    public function test_puter_alone_makes_the_assistant_available(): void
    {
        $this->assertTrue(app(Assistant::class)->isAvailable());
        $this->assertFalse(app(Assistant::class)->hasServerModel());

        config(['assistant.puter.enabled' => false]);

        $this->assertFalse(app(Assistant::class)->isAvailable());
    }

    public function test_without_a_server_provider_the_question_is_handed_to_the_browser(): void
    {
        $component = $this->ask();

        $relay = $this->returned($component)['relay'];

        $this->assertSame('gpt-5.4-nano', $relay['model']);
        $this->assertSame('system', $relay['messages'][0]['role']);
        $this->assertSame(['role' => 'user', 'content' => 'Who am I?'], end($relay['messages']));
        $this->assertContains('get_my_profile', array_column(array_column($relay['tools'], 'function'), 'name'));
        $this->assertSame($relay['id'], session(Chat::relayKey())['id']);

        // Nothing is answered yet: the question stays until the browser reports back.
        $component->assertSet('question', 'Who am I?');
        $this->assertSame([], Chat::currentMessages());
    }

    public function test_the_browsers_answer_is_recorded(): void
    {
        $component = $this->ask();
        $id = $this->returned($component)['relay']['id'];

        $component->call('relayStep', $id, ['role' => 'assistant', 'content' => 'You are **Sami**.'])
            ->assertReturned(null)
            ->assertSet('question', '')
            ->assertSeeHtml('<strong>Sami</strong>');

        $this->assertSame([
            ['role' => 'user', 'text' => 'Who am I?'],
            ['role' => 'assistant', 'text' => 'You are **Sami**.'],
        ], Chat::currentMessages());
        $this->assertNull(session(Chat::relayKey()));
    }

    public function test_a_tool_call_runs_on_the_server_and_the_next_round_is_returned(): void
    {
        $component = $this->ask();
        $id = $this->returned($component)['relay']['id'];

        $component->call('relayStep', $id, [
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => [['id' => 'x1', 'type' => 'function', 'function' => ['name' => 'get_my_profile', 'arguments' => '{}']]],
        ]);

        $next = $this->returned($component)['relay'];
        $tool = end($next['messages']);

        $this->assertSame($id, $next['id']);
        $this->assertSame('tool', $tool['role']);
        $this->assertSame($tool['tool_call_id'], $next['messages'][count($next['messages']) - 2]['tool_calls'][0]['id']);
        $this->assertStringContainsString('"name"', $tool['content']);

        $component->call('relayStep', $id, ['role' => 'assistant', 'content' => 'Done.'])->assertReturned(null);
        $this->assertSame('Done.', Chat::currentMessages()[1]['text']);
    }

    public function test_only_the_relay_id_held_in_the_session_is_accepted(): void
    {
        $component = $this->ask();

        $component->call('relayStep', 'forged', ['role' => 'assistant', 'content' => 'Injected'])
            ->assertReturned(null);

        $this->assertSame([], Chat::currentMessages());
        $this->assertNotNull(session(Chat::relayKey()));
    }

    public function test_a_browser_failure_says_why_and_ends_the_relay(): void
    {
        $component = $this->ask();
        $id = $this->returned($component)['relay']['id'];

        $component->call('relayFailed', $id, 'funds')
            ->assertSet('notice', __('assistant.errors.puter-funds'));

        $this->assertNull(session(Chat::relayKey()));

        $component->call('relayStep', $id, ['role' => 'assistant', 'content' => 'Late'])->assertReturned(null);
        $this->assertSame([], Chat::currentMessages());
    }

    public function test_when_the_server_providers_fail_puter_takes_over(): void
    {
        $this->mock(Assistant::class, function (MockInterface $mock) {
            $mock->shouldReceive('isAvailable')->andReturn(true);
            $mock->shouldReceive('hasServerModel')->andReturn(true);
            $mock->shouldReceive('usesBrowser')->andReturn(true);
            $mock->shouldReceive('reply')->once()->andThrow(new GeminiException('No AI provider could answer', 503));
            $mock->shouldReceive('begin')->once()->andReturn([
                ['question' => 'Hi', 'contents' => [], 'proposal' => null, 'round' => 0],
                ['messages' => [['role' => 'user', 'content' => 'Hi']], 'tools' => []],
            ]);
        });

        $component = Livewire::actingAs($this->employee())->test(Chat::class)
            ->set('question', 'Hi')
            ->call('send')
            ->assertSet('notice', null);

        $this->assertSame([['role' => 'user', 'content' => 'Hi']], $this->returned($component)['relay']['messages']);
    }

    public function test_the_page_credits_puter(): void
    {
        $this->actingAs($this->employee())->get(route('assistant'))
            ->assertOk()
            ->assertSee('https://developer.puter.com', false);
    }

    private function ask(): Testable
    {
        return Livewire::actingAs($this->employee())->test(Chat::class)
            ->set('question', 'Who am I?')
            ->call('send')
            ->assertHasNoErrors();
    }

    private function returned(Testable $component): mixed
    {
        $value = null;

        $component->assertReturned(function ($returned) use (&$value) {
            $value = $returned;

            return true;
        });

        return $value;
    }

    private function employee(): User
    {
        $this->city ??= $this->makeCity('SYR', '000');

        return $this->makeUser($this->city, 'employee');
    }
}
