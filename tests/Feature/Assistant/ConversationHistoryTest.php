<?php

namespace Tests\Feature\Assistant;

use App\Livewire\Assistant\Chat;
use App\Models\AssistantConversation;
use App\Models\City;
use App\Models\User;
use App\Services\Assistant\Assistant;
use App\Services\Assistant\AssistantReply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * Saved conversations: "New chat" starts another and keeps the earlier ones,
 * which can be opened again or deleted, only by their owner. The messages
 * are encrypted at rest; only the most recent ones are kept.
 */
class ConversationHistoryTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    private ?City $city = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(Assistant::class, function (MockInterface $mock) {
            $mock->shouldReceive('isAvailable')->andReturn(true);
            $mock->shouldReceive('hasServerModel')->andReturn(true);
            $mock->shouldReceive('usesBrowser')->andReturn(false);
            $mock->shouldReceive('reply')->andReturnUsing(fn ($user, $history, $question) => new AssistantReply('Answer to '.$question));
        });
    }

    public function test_the_first_question_starts_a_saved_conversation_titled_with_it(): void
    {
        $user = $this->employee();

        Livewire::actingAs($user)->test(Chat::class)->set('question', 'How much leave do I have?')->call('send');

        $conversation = AssistantConversation::sole();
        $this->assertTrue($conversation->user->is($user));
        $this->assertSame('How much leave do I have?', $conversation->title);
        $this->assertSame('Answer to How much leave do I have?', $conversation->messages[1]['text']);
    }

    public function test_messages_are_encrypted_at_rest(): void
    {
        Livewire::actingAs($this->employee())->test(Chat::class)->set('question', 'What is my salary?')->call('send');

        $raw = DB::table('assistant_conversations')->value('messages');
        $this->assertStringNotContainsString('salary', $raw);
    }

    public function test_a_new_chat_keeps_the_earlier_one_in_the_history(): void
    {
        $component = Livewire::actingAs($this->employee())->test(Chat::class)
            ->set('question', 'First question')->call('send')
            ->call('clear')
            ->assertSet('question', '');

        $this->assertSame([], Chat::currentMessages());

        $component->set('question', 'Second question')->call('send');

        $this->assertSame(['Second question', 'First question'], AssistantConversation::latest('id')->pluck('title')->all());
        $component->assertSeeHtml('data-testid="assistant-history"')
            ->assertSee('First question')
            ->assertSee('Second question');
    }

    public function test_an_earlier_chat_can_be_opened_and_continued(): void
    {
        $component = Livewire::actingAs($this->employee())->test(Chat::class)
            ->set('question', 'First question')->call('send')
            ->call('clear')
            ->set('question', 'Second question')->call('send');

        $first = AssistantConversation::where('title', 'First question')->sole();

        $component->call('openConversation', $first->id)
            ->set('question', 'Follow-up')->call('send');

        $this->assertCount(4, $first->fresh()->messages);
        $this->assertSame('Follow-up', Chat::currentMessages()[2]['text']);
        $this->assertSame(2, AssistantConversation::count());
    }

    public function test_another_users_chat_cannot_be_opened_or_deleted(): void
    {
        $owner = $this->employee();
        Livewire::actingAs($owner)->test(Chat::class)->set('question', 'Private question')->call('send');
        $theirs = AssistantConversation::sole();

        $component = Livewire::actingAs($this->employee())->test(Chat::class)
            ->assertDontSee('Private question')
            ->call('openConversation', $theirs->id)
            ->assertDontSee('Private question')
            ->call('deleteConversation', $theirs->id);

        $this->assertSame([], Chat::currentMessages());
        $this->assertTrue($theirs->fresh()->exists);
    }

    public function test_a_chat_can_be_deleted(): void
    {
        $component = Livewire::actingAs($this->employee())->test(Chat::class)
            ->set('question', 'Delete me')->call('send');

        $component->call('deleteConversation', AssistantConversation::sole()->id);

        $this->assertSame(0, AssistantConversation::count());
        $this->assertSame([], Chat::currentMessages());
    }

    public function test_only_the_most_recent_chats_are_kept(): void
    {
        $user = $this->employee();

        foreach (range(1, AssistantConversation::KEEP) as $i) {
            AssistantConversation::create(['user_id' => $user->id, 'title' => 'Old '.$i, 'messages' => []]);
        }

        Livewire::actingAs($user)->test(Chat::class)->set('question', 'Newest')->call('send');

        $this->assertSame(AssistantConversation::KEEP, AssistantConversation::count());
        $this->assertTrue(AssistantConversation::where('title', 'Newest')->exists());
    }

    private function employee(): User
    {
        $this->city ??= $this->makeCity('SYR', '000');

        return $this->makeUser($this->city, 'employee');
    }
}
