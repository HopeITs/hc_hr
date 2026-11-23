<?php

namespace App\Livewire\Chat;

use App\Models\User;
use App\Services\Chat\ChatDirectory;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The chat icon in the header, beside the bell: how many chats have unread
 * messages, and a way to open the chats list (on a phone, the only one).
 */
class HeaderButton extends Component
{
    #[On('chat-updated')]
    public function onUpdated(): void {}

    public function render(ChatDirectory $directory): View
    {
        /** @var User $viewer */
        $viewer = Auth::user();

        return view('livewire.chat.header-button', [
            'unread' => $directory->unreadChats($viewer),
        ]);
    }
}
