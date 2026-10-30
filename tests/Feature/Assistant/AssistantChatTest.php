<?php

namespace Tests\Feature\Assistant;

use App\Livewire\Assistant\Chat;
use App\Models\User;
use App\Services\Assistant\Assistant;
use App\Services\Assistant\GeminiException;
use App\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * The AI assistant page: open to every signed-in user while the assistant is
 * available, rate limited per user, and never showing exception text or raw
 * HTML from an answer.
 */
class AssistantChatTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

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
            ->assertSee(__('assistant.suggestions')[0]);
    }

    public function test_the_page_requires_sign_in(): void
    {
        $this->available();

        $this->get(route('assistant'))->assertRedirect();
    }

    public function test_a_question_is_answered(): void
    {
        $user = $this->employee();
        $this->available()->shouldReceive('reply')
            ->once()
            ->withArgs(fn ($asker, $history, $question) => $asker->is($user) && $history === [] && $question === 'How much leave do I have?')
            ->andReturn('You have **12** days left.');

        Livewire::actingAs($user)->test(Chat::class)
            ->set('question', '  How much leave do I have?  ')
            ->call('send')
            ->assertHasNoErrors()
            ->assertSet('question', '')
            ->assertSet('messages', [
                ['role' => 'user', 'text' => 'How much leave do I have?'],
                ['role' => 'assistant', 'text' => 'You have **12** days left.'],
            ])
            ->assertSeeHtml('<strong>12</strong>');
    }

    public function test_the_earlier_turns_go_with_the_next_question(): void
    {
        $first = [
            ['role' => 'user', 'text' => 'First?'],
            ['role' => 'assistant', 'text' => 'One.'],
        ];

        $mock = $this->available();
        $mock->shouldReceive('reply')->once()->withArgs(fn ($u, $history, $q) => $history === [] && $q === 'First?')->andReturn('One.');
        $mock->shouldReceive('reply')->once()->withArgs(fn ($u, $history, $q) => $history === $first && $q === 'Second?')->andReturn('Two.');

        Livewire::actingAs($this->employee())->test(Chat::class)
            ->set('question', 'First?')->call('send')
            ->set('question', 'Second?')->call('send')
            ->assertCount('messages', 4)
            ->call('clear')
            ->assertSet('messages', []);
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

        $this->available()->shouldReceive('reply')->twice()->andReturn('Answer.');

        $component = Livewire::actingAs($user)->test(Chat::class);
        $component->set('question', 'One?')->call('send')->assertSet('notice', null);
        $component->set('question', 'Two?')->call('send')->assertSet('notice', null);
        $component->set('question', 'Three?')->call('send')
            ->assertSee(__('assistant.errors.limit', ['minutes' => 60]))
            ->assertSet('question', 'Three?')
            ->assertCount('messages', 4);
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
            ->assertSet('messages', [])
            ->assertSet('question', 'Hello?');
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
        $this->available()->shouldReceive('reply')->andReturn("Hi <script>alert('x')</script><img src=x onerror=alert(1)> [link](javascript:alert(1)) *ok*");

        Livewire::actingAs($this->employee())->test(Chat::class)
            ->set('question', 'Hello?')
            ->call('send')
            ->assertDontSeeHtml('<script>')
            ->assertDontSeeHtml('onerror')
            ->assertDontSeeHtml('javascript:')
            ->assertSeeHtml('<em>ok</em>');
    }

    public function test_the_menu_shows_the_assistant_only_while_it_is_available(): void
    {
        $user = $this->employee();

        $this->available(false);
        $this->assertArrayNotHasKey('nav.my-space.assistant', Navigation::shortcuts($user));

        $this->available(true);
        $shortcuts = Navigation::shortcuts($user);
        $this->assertArrayHasKey('nav.my-space.assistant', $shortcuts);
        $this->assertSame('assistant', $shortcuts['nav.my-space.assistant']['route']);
    }

    public function test_without_a_key_the_assistant_is_hidden(): void
    {
        config(['assistant.gemini.key' => null]);

        $this->assertFalse(app(Assistant::class)->isAvailable());
        $this->assertArrayNotHasKey('nav.my-space.assistant', Navigation::shortcuts($this->employee()));
    }

    private function employee(): User
    {
        return $this->makeUser($this->makeCity('SYR', '000'), 'employee');
    }

    private function available(bool $available = true): MockInterface
    {
        return $this->mock(Assistant::class, function (MockInterface $mock) use ($available) {
            $mock->shouldReceive('isAvailable')->andReturn($available);
        });
    }
}
