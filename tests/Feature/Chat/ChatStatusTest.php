<?php

namespace Tests\Feature\Chat;

use App\Events\ChatConversationUpdated;
use App\Livewire\Chat\Dock;
use App\Livewire\Chat\Window;
use App\Services\Chat\ChatSettings;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

/** Stage 3: read ticks, typing/online channels, the notice and the sound setting. */
class ChatStatusTest extends ChatTestCase
{
    public function test_reading_tells_the_others_once_and_sending_does_not_count_as_a_read_signal(): void
    {
        [$chat, $me, $colleague] = $this->privateChat();
        Event::fake([ChatConversationUpdated::class]);

        $message = $this->chat()->send($colleague, $chat, 'hello');

        Event::assertNotDispatched(ChatConversationUpdated::class, fn ($e) => $e->kind === ChatConversationUpdated::KIND_READ);

        $this->assertTrue($this->chat()->markRead($me, $chat, $message->id));
        $this->assertFalse($this->chat()->markRead($me, $chat, $message->id));

        Event::assertDispatchedTimes(ChatConversationUpdated::class, 2); // the message, then one read
        Event::assertDispatched(ChatConversationUpdated::class, fn ($e) => $e->kind === ChatConversationUpdated::KIND_READ
            && $e->byUserId === $me->id
            && ! str_contains(json_encode($e->broadcastWith()), 'hello'));
    }

    public function test_one_tick_until_the_other_person_reads_then_two(): void
    {
        [$chat, $me, $colleague] = $this->privateChat();
        $this->chat()->send($me, $chat, 'are you there');

        Livewire::actingAs($me)->test(Window::class, ['conversationId' => $chat->id, 'open' => true])
            ->assertSeeHtml('data-testid="chat-sent"')
            ->assertDontSeeHtml('data-testid="chat-read"');

        Livewire::actingAs($colleague)->test(Window::class, ['conversationId' => $chat->id, 'open' => true])->call('seen');

        Livewire::actingAs($me)->test(Window::class, ['conversationId' => $chat->id, 'open' => true])
            ->assertSeeHtml('data-testid="chat-read"');
    }

    public function test_a_minimised_tab_never_marks_anything_read(): void
    {
        [$chat, $me, $colleague] = $this->privateChat();
        $this->chat()->send($colleague, $chat, 'unseen');

        Livewire::actingAs($me)->test(Window::class, ['conversationId' => $chat->id, 'open' => false])->call('seen');

        $this->assertSame(1, $this->chat()->unreadCount($me, $chat));
    }

    public function test_only_members_may_listen_to_a_conversations_typing_channel(): void
    {
        $this->useRealtimeAuth();
        [$chat, $me] = $this->privateChat();

        $this->actingAs($me)
            ->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-chat.conversation.'.$chat->id])
            ->assertOk();

        $this->actingAs($this->person())
            ->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-chat.conversation.'.$chat->id])
            ->assertForbidden();
    }

    public function test_the_online_channel_shares_only_the_user_id(): void
    {
        $this->useRealtimeAuth();
        config(['chat.enabled' => true]);
        $me = $this->person();

        $response = $this->actingAs($me)
            ->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'presence-chat.online'])
            ->assertOk();

        // The Pusher protocol sends user_id as a string.
        $this->assertSame(['user_id' => (string) $me->id, 'user_info' => ['id' => $me->id]], json_decode($response->json('channel_data'), true));
    }

    public function test_the_sound_setting_is_on_by_default_and_saved_per_person(): void
    {
        $me = $this->person();
        $other = $this->person();
        $settings = app(ChatSettings::class);

        $this->assertTrue($settings->sound($me));

        Livewire::actingAs($me)->test(Dock::class)
            ->call('toggleLauncher')
            ->call('showSettings')
            ->assertSeeHtml('aria-checked="true"')
            ->call('toggleSound')
            ->assertSeeHtml('aria-checked="false"');

        $this->assertFalse($settings->sound($me));
        $this->assertTrue($settings->sound($other));
    }

    public function test_a_message_from_someone_else_asks_for_a_notice_without_its_text(): void
    {
        [$chat, $me, $colleague] = $this->privateChat();
        $message = $this->chat()->send($colleague, $chat, 'secret salary figures');
        app(ChatSettings::class)->setSound($me, false);

        Livewire::actingAs($me)->test(Dock::class)
            ->dispatch('chat-updated', conversationId: $chat->id, kind: 'message', by: $colleague->id)
            ->assertDispatched('chat-notify', function (string $name, array $params) use ($chat, $colleague) {
                return $params['conversationId'] === $chat->id
                    && str_contains($params['title'], $colleague->profile->name)
                    && ! str_contains($params['title'], 'salary')
                    && $params['sound'] === false;
            });
    }

    public function test_no_notice_for_ones_own_message_or_a_muted_chat(): void
    {
        [$chat, $me, $colleague] = $this->privateChat();

        Livewire::actingAs($me)->test(Dock::class)
            ->dispatch('chat-updated', conversationId: $chat->id, kind: 'message', by: $me->id)
            ->assertNotDispatched('chat-notify');

        $chat->participantFor($me)->forceFill(['muted_until' => now()->addDay()])->save();

        Livewire::actingAs($me)->test(Dock::class)
            ->dispatch('chat-updated', conversationId: $chat->id, kind: 'message', by: $colleague->id)
            ->assertNotDispatched('chat-notify');
    }

    /** Channel authorisation through the real Reverb (Pusher protocol) broadcaster; no network involved. */
    private function useRealtimeAuth(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => '1',
            'broadcasting.connections.reverb.options.host' => '127.0.0.1',
        ]);
        app('Illuminate\Broadcasting\BroadcastManager')->forgetDrivers();
        require base_path('routes/channels.php');
    }
}
