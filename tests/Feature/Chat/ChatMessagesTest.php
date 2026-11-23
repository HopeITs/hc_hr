<?php

namespace Tests\Feature\Chat;

use App\Models\ChatMessage;
use App\Services\Chat\ChatFileVault;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class ChatMessagesTest extends ChatTestCase
{
    public function test_a_reply_points_at_a_message_in_the_same_chat(): void
    {
        [$chat, $a, $b] = $this->privateChat();
        $question = $this->chat()->send($a, $chat, 'Is the contract ready?');

        $answer = $this->chat()->send($b, $chat, 'Yes', [], $question->id);
        $this->assertSame($question->id, $answer->reply_to_id);

        [$elsewhere, $c] = $this->privateChat();
        $foreign = $this->chat()->send($c, $elsewhere, 'private');

        $this->expectException(ValidationException::class);
        $this->chat()->send($a, $chat, 'peek', [], $foreign->id);
    }

    public function test_empty_messages_and_blocked_files_are_refused(): void
    {
        [$chat, $a] = $this->privateChat();

        try {
            $this->chat()->send($a, $chat, '   ');
            $this->fail('An empty message was sent.');
        } catch (ValidationException) {
        }

        $this->expectException(ValidationException::class);
        $this->chat()->send($a, $chat, null, [UploadedFile::fake()->createWithContent('setup.exe', 'MZ')]);
    }

    public function test_only_the_sender_can_edit_and_the_edit_is_marked(): void
    {
        [$chat, $a, $b] = $this->privateChat();
        $message = $this->chat()->send($a, $chat, 'typo');

        $edited = $this->chat()->edit($a, $message, 'fixed');
        $this->assertSame('fixed', $edited->fresh()->body);
        $this->assertNotNull($edited->fresh()->edited_at);

        $this->expectException(ValidationException::class);
        $this->chat()->edit($b, $message, 'not mine');
    }

    public function test_delete_for_me_hides_it_only_for_me(): void
    {
        [$chat, $a, $b] = $this->privateChat();
        $message = $this->chat()->send($a, $chat, 'hello');

        $this->chat()->deleteForMe($b, $message);

        $this->assertSame(0, ChatMessage::query()->visibleTo($b)->count());
        $this->assertSame(1, ChatMessage::query()->visibleTo($a)->count());
    }

    public function test_delete_for_everyone_leaves_no_trace_and_moves_the_last_message_back(): void
    {
        [$chat, $a, $b] = $this->privateChat();
        $first = $this->chat()->send($a, $chat, 'first');
        $second = $this->chat()->send($a, $chat, 'second');

        $this->chat()->deleteForEveryone($a, $second);

        $this->assertNull(ChatMessage::query()->find($second->id));
        $this->assertSame($first->id, $chat->fresh()->last_message_id);

        $this->expectException(ValidationException::class);
        $this->chat()->deleteForEveryone($b, $first); // not theirs, and not a group admin
    }

    public function test_forwarding_names_the_original_author_and_copies_files(): void
    {
        [$source, $rana, $me] = $this->privateChat();
        $original = $this->chat()->send($rana, $source, 'see attached', [UploadedFile::fake()->createWithContent('plan.pdf', 'PLAN')]);
        [$target] = [$this->chat()->openPrivate($me, $george = $this->person())];

        $copies = $this->chat()->forward($me, [$original], [$target], comment: 'FYI');

        $this->assertCount(2, $copies);
        [$comment, $copy] = [$copies[0], $copies[1]];
        $this->assertSame('FYI', $comment->body);
        $this->assertSame($rana->id, $copy->forwarded_from_user_id);
        $this->assertSame($original->id, $copy->forwarded_from_message_id);
        $this->assertSame('see attached', $copy->body);

        $copied = $copy->attachments->first();
        $this->assertNotSame($original->attachments->first()->path, $copied->path);
        $this->assertSame('PLAN', app(ChatFileVault::class)->read($copied->path));

        // Forwarding the copy again still credits Rana.
        $again = $this->chat()->forward($george, [$copy], [$this->chat()->openPrivate($george, $this->person())]);
        $this->assertSame($rana->id, $again->first()->forwarded_from_user_id);
    }

    public function test_forwarding_with_the_sender_hidden_reads_as_ones_own(): void
    {
        [$source, $rana, $me] = $this->privateChat();
        $original = $this->chat()->send($rana, $source, 'numbers');
        $target = $this->chat()->openPrivate($me, $this->person());

        $copy = $this->chat()->forward($me, [$original], [$target], hideSender: true)->first();

        $this->assertNull($copy->forwarded_from_user_id);
        $this->assertTrue($copy->forward_hidden);
        $this->assertFalse($copy->isForwarded());
    }

    public function test_one_cannot_forward_into_or_out_of_a_chat_one_is_not_in(): void
    {
        [$source, $rana] = $this->privateChat();
        $original = $this->chat()->send($rana, $source, 'private');
        $stranger = $this->person();

        $this->expectException(AuthorizationException::class);
        $this->chat()->forward($stranger, [$original], [$this->chat()->openPrivate($stranger, $this->person())]);
    }

    public function test_unread_counts_and_read_ticks(): void
    {
        [$chat, $a, $b] = $this->privateChat();
        $message = $this->chat()->send($a, $chat, 'one');
        $this->chat()->send($a, $chat, 'two');

        $this->assertSame(2, $this->chat()->unreadCount($b, $chat));
        $this->assertSame(0, $this->chat()->unreadCount($a, $chat));
        $this->assertFalse($this->chat()->isReadByOthers($message));

        $this->chat()->markRead($b, $chat, $message->id);

        $this->assertSame(1, $this->chat()->unreadCount($b, $chat));
        $this->assertTrue($this->chat()->isReadByOthers($message));

        // The marker never moves back.
        $this->chat()->markRead($b, $chat, 0);
        $this->assertSame(1, $this->chat()->unreadCount($b, $chat));
    }
}
