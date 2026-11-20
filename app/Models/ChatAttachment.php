<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file sent in a chat. The file on disk is encrypted (ChatFileVault) and
 * stored under a random name; the original name is encrypted in the table.
 */
class ChatAttachment extends Model
{
    protected $table = 'messenger_attachments';

    protected $fillable = ['message_id', 'path', 'original_name', 'mime', 'size', 'width', 'height', 'thumbnail_path'];

    protected $casts = [
        'original_name' => 'encrypted',
        'size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'message_id');
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }
}
