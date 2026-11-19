<?php

namespace Tests\Feature\Chat;

use App\Models\ChatConversation;
use App\Models\User;
use App\Services\Chat\ChatGroupService;
use App\Services\Chat\ChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

abstract class ChatTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('chat-files');
    }

    protected function chat(): ChatService
    {
        return app(ChatService::class);
    }

    protected function groupService(): ChatGroupService
    {
        return app(ChatGroupService::class);
    }

    protected function person(): User
    {
        return User::factory()->create();
    }

    protected function deactivate(User $user): void
    {
        $user->profile->forceFill(['is_active' => false])->save();
        $user->refresh();
    }

    /** @return array{0: ChatConversation, 1: User, 2: User} */
    protected function privateChat(): array
    {
        $a = $this->person();
        $b = $this->person();

        return [$this->chat()->openPrivate($a, $b), $a, $b];
    }
}
