<?php

namespace App\Http\Controllers;

use App\Models\ChatAttachment;
use App\Models\ChatConversation;
use App\Services\Chat\ChatFileVault;
use App\Services\Chat\ChatService;
use App\Services\Chat\ChatThumbnails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Chat files in and out.
 *
 * In: a message with files is posted here (not through Livewire's temporary
 * uploads, which would leave an unencrypted copy on disk for up to a day and
 * whose size limit is shared by the whole site). Each file is encrypted
 * straight away by ChatService.
 *
 * Out: a file is decrypted piece by piece into the response, for current
 * members of its conversation only (ChatConversationPolicy, no role
 * exception). Only plain photo types are shown inline; everything else,
 * SVG included, downloads, and nothing served here may run script.
 */
class ChatFileController extends Controller
{
    public function store(Request $request, ChatConversation $conversation, ChatService $chat): JsonResponse
    {
        abort_unless(config('chat.enabled'), 404);

        $request->validate([
            'body' => ['nullable', 'string'],
            'files' => ['array'],
            'files.*' => ['file'],
            'reply_to' => ['nullable', 'integer'],
        ]);

        // ChatService checks membership, the deactivated rule, size and type.
        $message = $chat->send(
            $request->user(),
            $conversation,
            $request->input('body'),
            array_values($request->file('files', [])),
            $request->integer('reply_to') ?: null,
        );

        return response()->json(['id' => $message->id]);
    }

    public function show(Request $request, ChatAttachment $attachment, ChatFileVault $vault): StreamedResponse
    {
        abort_unless(config('chat.enabled'), 404);

        Gate::authorize('view', $attachment->message->conversation);

        $thumbnail = $request->boolean('thumb') && $attachment->thumbnail_path !== null;
        $inline = $thumbnail || in_array($attachment->mime, ChatThumbnails::INLINE_MIMES, true);
        $name = (string) $attachment->original_name;
        $fallback = preg_replace('/[^\x20-\x7E]/', '_', $name) ?: 'file';

        $headers = [
            'Content-Type' => $thumbnail ? 'image/jpeg' : ($inline ? $attachment->mime : 'application/octet-stream'),
            'Content-Disposition' => HeaderUtils::makeDisposition(
                $inline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT,
                $thumbnail ? 'preview.jpg' : $name,
                $thumbnail ? 'preview.jpg' : str_replace(['/', '\\', '%'], '_', $fallback),
            ),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
            'Cache-Control' => 'private, no-store',
        ];

        if (! $thumbnail) {
            $headers['Content-Length'] = (string) $attachment->size;
        }

        $path = $thumbnail ? $attachment->thumbnail_path : $attachment->path;

        return response()->stream(function () use ($vault, $path) {
            $vault->stream($path, function (string $piece): void {
                echo $piece;
                flush();
            });
        }, 200, $headers);
    }
}
