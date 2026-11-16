<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A saved email or WhatsApp text with {placeholders}. Nothing here is sent by
 * the system: MessageTemplateRenderer fills it in for one person and the
 * result opens as a new message the user reviews first.
 */
class MessageTemplate extends Model
{
    public const EMAIL = 'email';

    public const WHATSAPP = 'whatsapp';

    protected $fillable = ['name', 'channel', 'language', 'subject', 'body', 'cc_manager', 'is_active', 'created_by'];

    protected function casts(): array
    {
        return [
            'cc_manager' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
