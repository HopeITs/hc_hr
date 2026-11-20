<?php

namespace Tests\Feature\Chat;

use App\Models\ChatAttachment;
use App\Models\ChatMessage;
use App\Services\Chat\ChatFileVault;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;

/** Stage 4: sending files, previews, and getting them back out. */
class ChatFilesTest extends ChatTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['chat.enabled' => true]);
    }

    public function test_a_photo_is_sent_with_its_caption_and_gets_an_encrypted_preview_while_the_original_stays_untouched(): void
    {
        [$chat, $me] = $this->privateChat();
        $photo = UploadedFile::fake()->image('team.jpg', 1600, 1200);
        $original = file_get_contents($photo->getRealPath());

        $this->actingAs($me)
            ->postJson(route('chat.messages.store', $chat->id), ['body' => 'our team', 'files' => [$photo]])
            ->assertOk()
            ->assertJsonStructure(['id']);

        $attachment = ChatAttachment::query()->sole();
        $vault = app(ChatFileVault::class);

        $this->assertSame('our team', ChatMessage::query()->sole()->body);
        $this->assertSame($original, $vault->read($attachment->path));
        $this->assertNotNull($attachment->thumbnail_path);
        $this->assertSame([1600, 1200], [$attachment->width, $attachment->height]);

        $preview = $vault->read($attachment->thumbnail_path);
        [$w, $h, $type] = getimagesizefromstring($preview);
        $this->assertSame(IMAGETYPE_JPEG, $type);
        $this->assertSame(480, max($w, $h));
    }

    public function test_only_members_can_send_files_into_a_chat_not_even_super_admin(): void
    {
        [$chat] = $this->privateChat();
        $boss = $this->person();
        $boss->assignRole(Role::findOrCreate('super-admin', 'web'));

        $this->actingAs($boss)
            ->postJson(route('chat.messages.store', $chat->id), ['files' => [UploadedFile::fake()->create('x.pdf', 10)]])
            ->assertForbidden();

        $this->assertSame(0, ChatAttachment::query()->count());
    }

    public function test_a_blocked_file_type_is_refused_with_a_message(): void
    {
        [$chat, $me] = $this->privateChat();

        $this->actingAs($me)
            ->postJson(route('chat.messages.store', $chat->id), ['files' => [UploadedFile::fake()->createWithContent('run.bat', '@echo off')]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('files');
    }

    public function test_a_member_gets_the_photo_back_shown_inline_and_its_preview(): void
    {
        [$chat, $me, $colleague] = $this->privateChat();
        $photo = UploadedFile::fake()->image('cat.png', 300, 200);
        $original = file_get_contents($photo->getRealPath());
        $this->chat()->send($me, $chat, null, [$photo]);
        $attachment = ChatAttachment::query()->sole();

        $full = $this->actingAs($colleague)->get(route('chat.files.show', $attachment->id));
        $full->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('inline', $full->headers->get('Content-Disposition'));
        $this->assertSame($original, $full->streamedContent());

        $thumb = $this->actingAs($colleague)->get(route('chat.files.show', $attachment->id).'?thumb=1');
        $thumb->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_nobody_outside_the_chat_can_download_a_file_not_even_super_admin(): void
    {
        [$chat, $me] = $this->privateChat();
        $this->chat()->send($me, $chat, null, [UploadedFile::fake()->createWithContent('salaries.xlsx', 'secret')]);
        $attachment = ChatAttachment::query()->sole();
        $boss = $this->person();
        $boss->assignRole(Role::findOrCreate('super-admin', 'web'));

        $this->actingAs($boss)->get(route('chat.files.show', $attachment->id))->assertForbidden();
    }

    public function test_other_files_download_under_their_arabic_name_and_svg_is_never_shown_inline(): void
    {
        [$chat, $me] = $this->privateChat();
        $this->chat()->send($me, $chat, null, [
            UploadedFile::fake()->createWithContent('عقد العمل.pdf', '%PDF-1.4 test'),
            UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
        ]);
        [$pdf, $svg] = ChatAttachment::query()->orderBy('id')->get()->all();

        $pdfResponse = $this->actingAs($me)->get(route('chat.files.show', $pdf->id));
        $pdfResponse->assertOk()->assertHeader('Content-Type', 'application/octet-stream');
        $this->assertStringStartsWith('attachment', $pdfResponse->headers->get('Content-Disposition'));
        $this->assertStringContainsString("filename*=utf-8''".rawurlencode('عقد العمل.pdf'), $pdfResponse->headers->get('Content-Disposition'));

        $this->assertNull($svg->thumbnail_path);
        $svgResponse = $this->actingAs($me)->get(route('chat.files.show', $svg->id));
        $svgResponse->assertHeader('Content-Type', 'application/octet-stream');
        $this->assertStringStartsWith('attachment', $svgResponse->headers->get('Content-Disposition'));
        $this->assertStringContainsString('sandbox', $svgResponse->headers->get('Content-Security-Policy'));
    }

    public function test_the_file_routes_are_closed_while_the_chat_is_switched_off(): void
    {
        [$chat, $me] = $this->privateChat();
        $this->chat()->send($me, $chat, null, [UploadedFile::fake()->createWithContent('a.txt', 'a')]);
        $attachment = ChatAttachment::query()->sole();
        config(['chat.enabled' => false]);

        $this->actingAs($me)->get(route('chat.files.show', $attachment->id))->assertNotFound();
        $this->actingAs($me)->postJson(route('chat.messages.store', $chat->id), ['body' => 'x'])->assertNotFound();
    }
}
