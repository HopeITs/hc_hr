<?php

namespace Tests\Feature\Assistant;

use App\Livewire\Assistant\Chat;
use App\Models\City;
use App\Models\User;
use App\Services\Assistant\Assistant;
use App\Services\Assistant\AssistantReply;
use App\Services\Assistant\Drafts\DraftRegistry;
use App\Services\Assistant\EmployeeTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * Before any new record the assistant offers a choice: continue in the chat,
 * or open the page that adds it. The kinds and the links are the header New
 * menu's own (same permissions), never anything the model wrote.
 */
class CreateChoiceTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    private ?City $city = null;

    public function test_the_choice_lists_only_what_the_new_menu_offers_this_user(): void
    {
        $hr = $this->makeUser($this->city(), 'clerk', ['employees.create', 'news.create']);
        $plain = $this->makeUser($this->city(), 'employee');

        $this->assertSame(['employee', 'news'], array_keys(app(DraftRegistry::class)->pages($hr)));
        $this->assertSame(['leave'], array_keys(app(DraftRegistry::class)->pages($plain)));
        $this->assertSame([], app(DraftRegistry::class)->pages($this->makeUser($this->city(), 'viewer')));

        $choice = collect(app(EmployeeTools::class)->declarations($hr))->firstWhere('name', DraftRegistry::CHOICE_TOOL);
        $this->assertSame(['employee', 'news'], $choice['parameters']['properties']['kind']['enum']);
    }

    public function test_offering_a_choice_returns_the_new_menu_link(): void
    {
        $hr = $this->makeUser($this->city(), 'clerk', ['employees.create']);

        $result = app(EmployeeTools::class)->call($hr, DraftRegistry::CHOICE_TOOL, ['kind' => 'employee']);

        $this->assertSame('choice', $result['status']);
        $this->assertSame(route('employees.create'), $result['proposal']['url']);
        $this->assertTrue($result['proposal']['chat']);

        // A kind the user may not add gets no link.
        $this->assertArrayHasKey('error', app(EmployeeTools::class)->call($hr, DraftRegistry::CHOICE_TOOL, ['kind' => 'contract']));
    }

    public function test_the_choice_reaches_the_reply(): void
    {
        config(['assistant.enabled' => true, 'assistant.gemini.key' => 'gemini-key']);
        $hr = $this->makeUser($this->city(), 'clerk', ['news.create']);

        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push(['candidates' => [['content' => ['role' => 'model', 'parts' => [
                ['functionCall' => ['name' => DraftRegistry::CHOICE_TOOL, 'args' => ['kind' => 'news']]],
            ]]]]])
            ->push(['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'Here or on the page?']]]]]])]);

        $reply = app(Assistant::class)->reply($hr, [], 'I want to post news');

        $this->assertSame('choice', $reply->draftType);
        $this->assertSame(route('news.create'), $reply->draft['url']);
    }

    public function test_the_latest_answer_shows_both_buttons_and_confirm_ignores_them(): void
    {
        $user = $this->makeUser($this->city(), 'clerk', ['employees.create']);
        $this->mock(Assistant::class, function (MockInterface $mock) {
            $mock->shouldReceive('isAvailable')->andReturn(true);
            $mock->shouldReceive('reply')->andReturn(new AssistantReply('Here or on the page?', [
                'kind' => 'employee', 'label' => 'Employee', 'url' => route('employees.create'), 'chat' => true,
            ], 'choice'));
        });

        $component = Livewire::actingAs($user)->test(Chat::class)
            ->set('question', 'Add a new employee')->call('send')
            ->assertSeeHtml('data-testid="assistant-create-choice"')
            ->assertSeeHtml('data-testid="assistant-choice-chat"')
            ->assertSeeHtml('href="'.route('employees.create').'"')
            ->assertDontSeeHtml('data-testid="assistant-draft"');

        $id = Chat::currentMessages()[1]['proposal']['id'];
        $component->call('confirmDraft', $id)->call('cancelDraft', $id);
        $this->assertSame('pending', Chat::currentMessages()[1]['proposal']['state']);
        $this->assertCount(2, Chat::currentMessages());

        // Once the conversation moves on, the old buttons go.
        $component->set('question', 'Something else')->call('send');
        $this->assertSame(1, substr_count($component->html(), 'data-testid="assistant-create-choice"'));
    }

    private function city(): City
    {
        return $this->city ??= $this->makeCity('SYR', '000');
    }
}
