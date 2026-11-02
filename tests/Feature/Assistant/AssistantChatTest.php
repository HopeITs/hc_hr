<?php

namespace Tests\Feature\Assistant;

use App\Livewire\Assistant\Chat;
use App\Models\City;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Assistant\Assistant;
use App\Services\Assistant\AssistantReply;
use App\Services\Assistant\GeminiException;
use App\Services\Assistant\LeaveRequestSubmitter;
use App\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * The AI assistant: the full page and the floating panel on every page, one
 * conversation kept in the session for both. Open to every signed-in user
 * while the assistant is available, rate limited per user, never showing
 * exception text or raw HTML from an answer. Leave drafts are held on the
 * server and submitted once, only on the employee's click.
 */
class AssistantChatTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    private const PROPOSAL = [
        'leave_balance_id' => 7,
        'leave_type' => 'Annual leave',
        'type' => 'day',
        'start' => '2026-11-10',
        'end' => '2026-11-12',
        'duration' => '3 days',
        'reason' => 'Family visit',
        'alternative_employee_id' => 5,
        'alternative_employee' => 'Sami Haddad',
    ];

    private ?City $city = null;

    public function test_the_page_is_not_found_while_the_assistant_is_unavailable(): void
    {
        $this->available(false);

        $this->actingAs($this->employee())->get(route('assistant'))->assertNotFound();
    }

    public function test_the_page_opens_for_any_signed_in_user_with_suggestions_and_the_disclaimer(): void
    {
        $this->available();

        $this->actingAs($this->employee())->get(route('assistant'))
            ->assertOk()
            ->assertSee(__('assistant.disclaimer'))
            ->assertSee(__('assistant.suggestions')[0])
            ->assertSee('data-assistant-page', false);
    }

    public function test_the_page_requires_sign_in(): void
    {
        $this->available();

        $this->get(route('assistant'))->assertRedirect();
    }

    public function test_the_floating_button_is_on_authenticated_pages_while_the_assistant_is_available(): void
    {
        $user = $this->employee();

        $this->available();
        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-testid="assistant-launcher"', false)
            ->assertSee('aria-label="'.e(__('assistant.widget.open')).'"', false)
            ->assertSee('role="dialog"', false)
            ->assertSee('x-persist="assistant-widget"', false);
    }

    public function test_the_floating_button_is_absent_while_the_assistant_is_unavailable(): void
    {
        $this->available(false);

        $this->actingAs($this->employee())->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('data-testid="assistant-launcher"', false);
    }

    public function test_the_assistant_is_no_longer_in_the_menu(): void
    {
        $this->available();
        $user = $this->employee();

        $this->assertArrayNotHasKey('nav.my-space.assistant', Navigation::shortcuts($user));

        $ids = collect(Navigation::for($user)['areas'])->flatMap(fn ($area) => $area['items'])->pluck('id');
        $this->assertNotContains('nav.my-space.assistant', $ids);
    }

    public function test_without_a_key_the_assistant_is_hidden(): void
    {
        config(['assistant.gemini.key' => null]);

        $this->assertFalse(app(Assistant::class)->isAvailable());
        $this->actingAs($this->employee())->get(route('dashboard'))
            ->assertDontSee('data-testid="assistant-launcher"', false);
    }

    public function test_a_question_is_answered(): void
    {
        $user = $this->employee();
        $this->available()->shouldReceive('reply')
            ->once()
            ->withArgs(fn ($asker, $history, $question) => $asker->is($user) && $history === [] && $question === 'How much leave do I have?')
            ->andReturn(new AssistantReply('You have **12** days left.'));

        Livewire::actingAs($user)->test(Chat::class)
            ->set('question', '  How much leave do I have?  ')
            ->call('send')
            ->assertHasNoErrors()
            ->assertSet('question', '')
            ->assertSeeHtml('<strong>12</strong>');

        $this->assertSame([
            ['role' => 'user', 'text' => 'How much leave do I have?'],
            ['role' => 'assistant', 'text' => 'You have **12** days left.'],
        ], $this->conversation());
    }

    public function test_the_earlier_turns_go_with_the_next_question(): void
    {
        $first = [
            ['role' => 'user', 'text' => 'First?'],
            ['role' => 'assistant', 'text' => 'One.'],
        ];

        $mock = $this->available();
        $mock->shouldReceive('reply')->once()->withArgs(fn ($u, $history, $q) => $history === [] && $q === 'First?')->andReturn(new AssistantReply('One.'));
        $mock->shouldReceive('reply')->once()->withArgs(fn ($u, $history, $q) => $history === $first && $q === 'Second?')->andReturn(new AssistantReply('Two.'));

        $component = Livewire::actingAs($this->employee())->test(Chat::class)
            ->set('question', 'First?')->call('send')
            ->set('question', 'Second?')->call('send');

        $this->assertCount(4, $this->conversation());

        $component->call('clear');
        $this->assertSame([], $this->conversation());
    }

    public function test_the_panel_and_the_page_share_one_conversation(): void
    {
        $user = $this->employee();
        $mock = $this->available();
        $mock->shouldReceive('reply')->once()->withArgs(fn ($u, $history, $q) => $history === [] && $q === 'From the panel?')->andReturn(new AssistantReply('Panel answer.'));
        $mock->shouldReceive('reply')->once()->withArgs(fn ($u, $history, $q) => count($history) === 2 && $q === 'From the page?')->andReturn(new AssistantReply('Page answer.'));

        Livewire::actingAs($user)->test(Chat::class, ['mode' => 'panel'])
            ->assertSet('mode', 'panel')
            ->set('question', 'From the panel?')->call('send');

        // Expanding: the page continues the same conversation.
        $this->actingAs($user)->get(route('assistant'))->assertOk()->assertSee('Panel answer.');

        Livewire::actingAs($user)->test(Chat::class)
            ->assertSee('Panel answer.')
            ->set('question', 'From the page?')->call('send');

        // And back: the panel shows what was asked on the page.
        Livewire::actingAs($user)->test(Chat::class, ['mode' => 'panel'])
            ->assertSee('Panel answer.')
            ->assertSee('Page answer.');
    }

    public function test_the_mode_cannot_be_changed_from_the_browser(): void
    {
        $this->available();

        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);

        Livewire::actingAs($this->employee())->test(Chat::class, ['mode' => 'panel'])->set('mode', 'page');
    }

    public function test_a_question_is_required_and_at_most_1000_characters(): void
    {
        $this->available()->shouldNotReceive('reply');

        Livewire::actingAs($this->employee())->test(Chat::class)
            ->set('question', '   ')->call('send')->assertHasErrors(['question' => 'required'])
            ->set('question', str_repeat('a', 1001))->call('send')->assertHasErrors(['question' => 'max']);
    }

    public function test_each_user_has_an_hourly_limit(): void
    {
        config(['assistant.hourly_limit' => 2]);
        $user = $this->employee();
        RateLimiter::clear('assistant:'.$user->id);

        $this->available()->shouldReceive('reply')->twice()->andReturn(new AssistantReply('Answer.'));

        $component = Livewire::actingAs($user)->test(Chat::class);
        $component->set('question', 'One?')->call('send')->assertSet('notice', null);
        $component->set('question', 'Two?')->call('send')->assertSet('notice', null);
        $component->set('question', 'Three?')->call('send')
            ->assertSee(__('assistant.errors.limit', ['minutes' => 60]))
            ->assertSet('question', 'Three?');

        $this->assertCount(4, $this->conversation());
    }

    public function test_a_failure_shows_a_friendly_line_and_never_the_exception_text(): void
    {
        $this->available()->shouldReceive('reply')->andThrow(new GeminiException('secret upstream detail: key=abc123', 500));

        Livewire::actingAs($this->employee())->test(Chat::class)
            ->set('question', 'Hello?')
            ->call('send')
            ->assertSee(__('assistant.errors.failed'))
            ->assertDontSee('secret upstream detail')
            ->assertDontSee('abc123')
            ->assertSet('question', 'Hello?');

        $this->assertSame([], $this->conversation());
    }

    public function test_gemini_rate_limiting_says_the_assistant_is_busy(): void
    {
        $this->available()->shouldReceive('reply')->andThrow(new GeminiException('Too many requests', 429));

        Livewire::actingAs($this->employee())->test(Chat::class)
            ->set('question', 'Hello?')
            ->call('send')
            ->assertSee(__('assistant.errors.busy'))
            ->assertDontSee('Too many requests');
    }

    public function test_html_in_an_answer_is_stripped(): void
    {
        $this->available()->shouldReceive('reply')->andReturn(new AssistantReply("Hi <script>alert('x')</script><img src=x onerror=alert(1)> [link](javascript:alert(1)) *ok*"));

        Livewire::actingAs($this->employee())->test(Chat::class)
            ->set('question', 'Hello?')
            ->call('send')
            ->assertDontSeeHtml('<script>')
            ->assertDontSeeHtml('onerror')
            ->assertDontSeeHtml('javascript:')
            ->assertSeeHtml('<em>ok</em>');
    }

    public function test_a_leave_draft_is_shown_under_the_answer_with_confirm_and_cancel(): void
    {
        $this->withProposal();

        $this->askForLeave()
            ->assertSee(__('assistant.leave.title'))
            ->assertSee('Annual leave')
            ->assertSee('2026-11-10')
            ->assertSee('2026-11-12')
            ->assertSee('3 days')
            ->assertSee('Family visit')
            ->assertSee('Sami Haddad')
            ->assertSee(__('assistant.leave.confirm'))
            ->assertSee(__('assistant.leave.cancel'));

        // Only the turns go back to the model, not the draft.
        $this->assertArrayHasKey('proposal', $this->conversation()[1]);
    }

    public function test_confirming_submits_the_server_held_draft_once(): void
    {
        $user = $this->employee();
        $this->withProposal();

        $this->mock(LeaveRequestSubmitter::class, function (MockInterface $mock) use ($user) {
            $mock->shouldReceive('submit')
                ->once()
                ->withArgs(fn ($asker, $proposal) => $asker->is($user) && $proposal === self::PROPOSAL)
                ->andReturn(new LeaveRequest);
        });

        $component = $this->askForLeave($user);
        $id = $this->proposalId();

        $component->call('confirmLeave', $id)
            ->assertSee(__('assistant.leave.submitted'))
            ->assertSee(__('assistant.leave.open-my-leaves'))
            ->assertSeeHtml('href="'.route('leaves.index').'"')
            ->assertDispatched('toast')
            ->assertDontSee(__('assistant.leave.confirm'));

        // A second click, or the other copy of the chat, cannot send it again.
        $component->call('confirmLeave', $id);
        Livewire::actingAs($user)->test(Chat::class, ['mode' => 'panel'])->call('confirmLeave', $id);

        $this->assertSame('submitted', $this->conversation()[1]['proposal']['state']);
    }

    public function test_only_an_id_held_on_the_server_can_be_confirmed(): void
    {
        $user = $this->employee();
        $this->withProposal();
        $this->mock(LeaveRequestSubmitter::class)->shouldNotReceive('submit');

        $component = $this->askForLeave($user);

        // The draft never travels to the browser as component state.
        $this->assertSame(['mode', 'notice', 'question'], collect($component->snapshot['data'])->keys()->sort()->values()->all());

        // Made-up ids, or values instead of an id, are ignored.
        $component->call('confirmLeave', 'not-a-real-id');
        $component->call('confirmLeave', json_encode(['leave_balance_id' => 1] + self::PROPOSAL));

        // Another user's session draft is not reachable either.
        $id = $this->proposalId();
        Livewire::actingAs($this->employee())->test(Chat::class)->call('confirmLeave', $id);
    }

    public function test_cancel_discards_the_draft(): void
    {
        $user = $this->employee();
        $this->withProposal();
        $this->mock(LeaveRequestSubmitter::class)->shouldNotReceive('submit');

        $component = $this->askForLeave($user);
        $id = $this->proposalId();

        $component->call('cancelLeave', $id)
            ->assertSee(__('assistant.leave.cancelled'))
            ->assertDontSee(__('assistant.leave.confirm'));

        $component->call('confirmLeave', $id);

        $this->assertSame('cancelled', $this->conversation()[1]['proposal']['state']);
    }

    public function test_a_refused_draft_shows_the_validation_messages_in_the_chat(): void
    {
        $user = $this->employee();
        $this->withProposal();

        $this->mock(LeaveRequestSubmitter::class, function (MockInterface $mock) {
            $mock->shouldReceive('submit')->once()->andThrow(ValidationException::withMessages([
                'start' => 'These dates overlap another request.',
            ]));
        });

        $component = $this->askForLeave($user);
        $id = $this->proposalId();

        $component->call('confirmLeave', $id)
            ->assertSee(__('assistant.leave.failed'))
            ->assertSee('These dates overlap another request.')
            ->assertNotDispatched('toast')
            ->assertDontSee(__('assistant.leave.confirm'));

        $component->call('confirmLeave', $id);

        $this->assertSame('failed', $this->conversation()[1]['proposal']['state']);
    }

    private function employee(): User
    {
        $this->city ??= $this->makeCity('SYR', '000');

        return $this->makeUser($this->city, 'employee');
    }

    private function available(bool $available = true): MockInterface
    {
        return $this->mock(Assistant::class, function (MockInterface $mock) use ($available) {
            $mock->shouldReceive('isAvailable')->andReturn($available);
            $mock->shouldReceive('hasServerModel')->andReturn(true)->byDefault();
            $mock->shouldReceive('usesBrowser')->andReturn(false)->byDefault();
        });
    }

    private function withProposal(): void
    {
        $this->available()->shouldReceive('reply')->andReturn(new AssistantReply('Here is your draft.', self::PROPOSAL));
    }

    private function askForLeave(?User $user = null): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::actingAs($user ?? $this->employee())->test(Chat::class)
            ->set('question', 'Book me three days off next month')
            ->call('send');
    }

    /** @return list<array<string, mixed>> */
    private function conversation(): array
    {
        return Chat::currentMessages();
    }

    private function proposalId(): string
    {
        return $this->conversation()[1]['proposal']['id'];
    }
}
