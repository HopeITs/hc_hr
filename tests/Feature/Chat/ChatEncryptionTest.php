<?php

namespace Tests\Feature\Chat;

use App\Models\ChatAttachment;
use App\Services\Chat\ChatFileVault;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ChatEncryptionTest extends ChatTestCase
{
    public function test_message_text_is_encrypted_in_the_database(): void
    {
        [$chat, $a] = $this->privateChat();

        $message = $this->chat()->send($a, $chat, 'The salary sheet is ready');

        $raw = DB::table('messenger_messages')->where('id', $message->id)->value('body');
        $this->assertStringNotContainsString('salary', $raw);
        $this->assertSame('The salary sheet is ready', $message->fresh()->body);
    }

    public function test_attachment_names_and_contents_are_encrypted_at_rest(): void
    {
        [$chat, $a] = $this->privateChat();
        $secret = str_repeat('CONFIDENTIAL CONTRACT TERMS ', 200);

        $message = $this->chat()->send($a, $chat, null, [
            UploadedFile::fake()->createWithContent('george-contract.pdf', $secret),
        ]);

        $attachment = $message->attachments->first();
        $rawName = DB::table('messenger_attachments')->where('id', $attachment->id)->value('original_name');
        $rawFile = Storage::disk('chat-files')->get($attachment->path);

        $this->assertStringNotContainsString('george', $rawName);
        $this->assertStringNotContainsString('george', $attachment->path);
        $this->assertStringNotContainsString('CONFIDENTIAL', $rawFile);
        $this->assertSame('george-contract.pdf', $attachment->fresh()->original_name);
        $this->assertSame($secret, app(ChatFileVault::class)->read($attachment->path));
        $this->assertSame(strlen($secret), $attachment->size);
    }

    public function test_a_file_larger_than_one_piece_round_trips(): void
    {
        config(['chat.file_chunk_bytes' => 1024]);
        $vault = app(ChatFileVault::class);

        foreach ([0, 1, 1023, 1024, 1025, 5000] as $length) {
            $plain = $length === 0 ? '' : random_bytes($length);
            $stored = $vault->storeContents($plain);

            $this->assertSame($plain, $vault->read($stored['path']), "length {$length}");
            $this->assertSame($length, $stored['size']);
        }
    }

    public function test_a_tampered_file_is_refused(): void
    {
        $vault = app(ChatFileVault::class);
        $stored = $vault->storeContents(str_repeat('a', 3000));
        $disk = Storage::disk('chat-files');

        $bytes = $disk->get($stored['path']);
        $bytes[40] = $bytes[40] === 'x' ? 'y' : 'x';
        $disk->put($stored['path'], $bytes);

        $this->expectException(RuntimeException::class);
        $vault->read($stored['path']);
    }

    public function test_a_truncated_file_is_refused(): void
    {
        config(['chat.file_chunk_bytes' => 1024]);
        $vault = app(ChatFileVault::class);
        $stored = $vault->storeContents(str_repeat('b', 3000)); // three pieces
        $disk = Storage::disk('chat-files');

        // Drop the last piece: 12 nonce + 4 length + 952 ciphertext + 16 tag.
        $bytes = $disk->get($stored['path']);
        $disk->put($stored['path'], substr($bytes, 0, strlen($bytes) - (12 + 4 + 952 + 16)));

        $this->expectException(RuntimeException::class);
        $vault->read($stored['path']);
    }

    public function test_the_search_index_holds_no_readable_text(): void
    {
        [$chat, $a] = $this->privateChat();
        $this->chat()->send($a, $chat, 'contract renewal for George');

        $tokens = DB::table('messenger_search_tokens')->pluck('token');

        $this->assertNotEmpty($tokens);
        foreach ($tokens as $token) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token);
        }
        $this->assertStringNotContainsString('con', $tokens->implode(''));
    }

    public function test_deleting_for_everyone_removes_the_encrypted_file(): void
    {
        [$chat, $a] = $this->privateChat();
        $message = $this->chat()->send($a, $chat, null, [UploadedFile::fake()->createWithContent('a.txt', 'hello')]);
        $path = $message->attachments->first()->path;

        $this->chat()->deleteForEveryone($a, $message);

        Storage::disk('chat-files')->assertMissing($path);
        $this->assertSame(0, ChatAttachment::query()->count());
    }
}
