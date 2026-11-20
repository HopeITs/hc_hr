<?php

namespace Tests\Feature\Chat;

use App\Livewire\Chat\Dock;
use App\Livewire\Chat\Page;
use App\Services\Chat\ChatDirectory;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

class ChatPageAndSearchTest extends ChatTestCase
{
    public function test_a_chat_is_found_by_the_name_in_either_language(): void
    {
        [$chat, $me, $colleague] = $this->privateChat();
        $colleague->profile->forceFill([
            'first_name_en' => 'Lana', 'last_name_en' => 'Abdo',
            'first_name_ar' => 'لانا', 'last_name_ar' => 'عبدو',
        ])->save();
        $directory = app(ChatDirectory::class);

        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);

            $this->assertSame([$chat->id], $directory->conversations($me, 'lana')->pluck('id')->all(), $locale);
            $this->assertSame([$chat->id], $directory->conversations($me, 'لانا')->pluck('id')->all(), $locale);
        }
    }

    public function test_searching_the_list_also_offers_colleagues_without_a_chat_and_one_tap_starts_it(): void
    {
        $me = $this->person();
        $newcomer = $this->person();
        $newcomer->profile->forceFill(['first_name_en' => 'Georgette', 'last_name_en' => 'Khoury'])->save();

        $component = Livewire::actingAs($me)->test(Dock::class)
            ->call('toggleLauncher')
            ->set('search', 'georg')
            ->assertSeeHtml('data-testid="chat-colleague"')
            ->assertSee('Georgette');

        $component->call('startWith', $newcomer->id);

        $conversation = $this->chat()->openPrivate($me, $newcomer);
        $component->assertSet('tabs', [['id' => $conversation->id, 'open' => true]]);
    }

    public function test_a_colleague_already_in_the_list_is_not_offered_twice(): void
    {
        [$chat, $me, $colleague] = $this->privateChat();
        $colleague->profile->forceFill(['first_name_en' => 'Rami'])->save();

        Livewire::actingAs($me)->test(Dock::class)
            ->call('toggleLauncher')
            ->set('search', 'rami')
            ->assertSeeHtml('data-testid="chat-row"')
            ->assertDontSeeHtml('data-testid="chat-colleague"');
    }

    public function test_the_full_page_is_closed_while_the_chat_is_switched_off(): void
    {
        config(['chat.enabled' => false]);

        $this->actingAs($this->person())->get(route('chat'))->assertNotFound();
    }

    public function test_any_employee_can_open_the_full_page_on_their_own_chat(): void
    {
        config(['chat.enabled' => true]);
        [$chat, $me] = $this->privateChat();
        $this->chat()->send($me, $chat, 'shown on the full page');

        $this->actingAs($me)
            ->get(route('chat', ['c' => $chat->id]))
            ->assertOk()
            ->assertSee('data-chat-page', false)
            ->assertSee('shown on the full page');
    }

    public function test_the_full_page_never_opens_someone_elses_chat_not_even_for_super_admin(): void
    {
        config(['chat.enabled' => true]);
        [$chat, $a] = $this->privateChat();
        $this->chat()->send($a, $chat, 'not for the boss');
        $boss = $this->person();
        $boss->assignRole(Role::findOrCreate('super-admin', 'web'));

        Livewire::actingAs($boss)->withQueryParams(['c' => $chat->id])->test(Page::class)
            ->assertSet('conversationId', null)
            ->assertDontSee('not for the boss')
            ->call('select', $chat->id)
            ->assertSet('conversationId', null);
    }
}
