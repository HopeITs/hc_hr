<?php

namespace Tests\Feature\Chat;

use App\Events\ChatConversationUpdated;
use App\Livewire\Chat\Dock;
use App\Livewire\Chat\HeaderButton;
use App\Livewire\Chat\Window;
use App\Models\ChatMessage;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\Models\Role;

class ChatDockTest extends ChatTestCase
{
    public function test_nothing_of_the_chat_shows_while_it_is_switched_off(): void
    {
        config(['chat.enabled' => false]);

        $this->actingAs($this->person())
            ->get(route('dashboard'))
            ->assertDontSee('data-testid="chat-dock"', false)
            ->assertDontSee('data-testid="chat-header-button"', false);
    }

    public function test_the_dock_and_header_icon_show_when_switched_on(): void
    {
        config(['chat.enabled' => true]);

        $this->actingAs($this->person())
            ->get(route('dashboard'))
            ->assertSee('data-testid="chat-dock"', false)
            ->assertSee('data-testid="chat-header-button"', false);
    }

    public function test_the_list_shows_only_the_viewers_own_chats(): void
    {
        [$mine, $me, $colleague] = $this->privateChat();
        [$theirs, $x, $y] = $this->privateChat();
        $this->chat()->send($colleague, $mine, 'hello there');
        $this->chat()->send($x, $theirs, 'secret plans');

        Livewire::actingAs($me)->test(Dock::class)
            ->call('toggleLauncher')
            ->assertSee($colleague->profile->name)
            ->assertSee('hello there')
            ->assertDontSee($y->profile->name)
            ->assertDontSee('secret plans');
    }

    public function test_tabs_handed_back_by_the_browser_are_checked_again(): void
    {
        [$mine, $me] = $this->privateChat();
        [$foreign] = $this->privateChat();

        Livewire::actingAs($me)->test(Dock::class)
            ->call('restore', [['id' => $foreign->id, 'open' => true], ['id' => $mine->id, 'open' => false], ['id' => 999999]])
            ->assertSet('tabs', [['id' => $mine->id, 'open' => false]])
            ->call('openConversation', $foreign->id)
            ->assertSet('tabs', [['id' => $mine->id, 'open' => false]]);
    }

    public function test_new_chat_opens_the_private_chat_as_an_open_tab(): void
    {
        $me = $this->person();
        $colleague = $this->person();

        $component = Livewire::actingAs($me)->test(Dock::class)->call('startWith', $colleague->id);

        $conversation = $this->chat()->openPrivate($me, $colleague);
        $component->assertSet('tabs', [['id' => $conversation->id, 'open' => true]])
            ->assertDispatched('chat-tabs-changed');
    }

    public function test_an_outsider_cannot_open_a_tab_on_someone_elses_chat_not_even_super_admin(): void
    {
        [$chat] = $this->privateChat();
        $boss = $this->person();
        $boss->assignRole(Role::findOrCreate('super-admin', 'web'));

        Livewire::actingAs($boss)->test(Window::class, ['conversationId' => $chat->id, 'open' => true])
            ->assertForbidden();
    }

    public function test_sending_from_a_tab_tells_both_members_with_ids_only(): void
    {
        Event::fake([ChatConversationUpdated::class]);
        [$chat, $me, $colleague] = $this->privateChat();

        Livewire::actingAs($me)->test(Window::class, ['conversationId' => $chat->id, 'open' => true])
            ->set('draft', 'the contract is ready')
            ->call('send')
            ->assertSet('draft', '')
            ->assertSee('the contract is ready');

        $message = ChatMessage::query()->sole();
        Event::assertDispatched(ChatConversationUpdated::class, function (ChatConversationUpdated $e) use ($chat, $me, $colleague, $message) {
            $payload = json_encode($e->broadcastWith());

            return $e->conversationId === $chat->id
                && $e->messageId === $message->id
                && collect($e->userIds)->sort()->values()->all() === collect([$me->id, $colleague->id])->sort()->values()->all()
                && ! str_contains($payload, 'contract');
        });
    }

    public function test_a_message_is_kept_when_the_realtime_server_is_down(): void
    {
        Event::listen(ChatConversationUpdated::class, fn () => throw new RuntimeException('Reverb unreachable'));
        [$chat, $me] = $this->privateChat();

        Livewire::actingAs($me)->test(Window::class, ['conversationId' => $chat->id, 'open' => true])
            ->set('draft', 'still saved')
            ->call('send')
            ->assertHasNoErrors();

        $this->assertSame('still saved', ChatMessage::query()->sole()->body);
    }

    public function test_an_open_tab_marks_the_chat_read_and_a_minimised_one_shows_the_count(): void
    {
        [$chat, $me, $colleague] = $this->privateChat();
        $this->chat()->send($colleague, $chat, 'first-unread-line');
        $this->chat()->send($colleague, $chat, 'second-unread-line');

        Livewire::actingAs($me)->test(HeaderButton::class)->assertSee('2', false);

        Livewire::actingAs($me)->test(Window::class, ['conversationId' => $chat->id, 'open' => false])
            ->assertSeeHtml('data-testid="chat-tab"')
            ->assertDontSee('first-unread-line');
        $this->assertSame(2, $this->chat()->unreadCount($me, $chat));

        // Opening alone doesn't read it; being on screen does (chat.js calls seen()).
        $open = Livewire::actingAs($me)->test(Window::class, ['conversationId' => $chat->id, 'open' => true])
            ->assertSee('first-unread-line')
            ->assertSee('second-unread-line');
        $this->assertSame(2, $this->chat()->unreadCount($me, $chat));

        $open->call('seen');
        $this->assertSame(0, $this->chat()->unreadCount($me, $chat));
    }

    public function test_one_cannot_write_to_a_deactivated_colleague(): void
    {
        [$chat, $me, $colleague] = $this->privateChat();
        $this->deactivate($colleague);

        Livewire::actingAs($me)->test(Window::class, ['conversationId' => $chat->id, 'open' => true])
            ->assertDontSeeHtml('data-testid="chat-composer"')
            ->assertSee(__('chat.ui.cannot_reply'));
    }
}
