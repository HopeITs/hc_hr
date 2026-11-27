<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * One policy: a file in the Policies folder (PolicyFolder) and the text read
 * from it, as Markdown (headings, numbered clauses, bold, tables), which the
 * policy page shows, the search looks through and the assistant answers from.
 *
 * source_modified_at is the version: it moves when a replaced file's new text
 * is in, and an agreement older than it asks the employee to agree again.
 */
class PolicyDocument extends Model
{
    /** Read and agreed. */
    public const KIND_POLICY = 'policy';

    /** Made by another team (the Media guideline) and only reviewed. */
    public const KIND_REVIEW = 'review';

    public const KINDS = [self::KIND_POLICY, self::KIND_REVIEW];

    public const READING = 'reading';

    public const READY = 'ready';

    public const FAILED = 'failed';

    protected $fillable = [
        'media_id',
        'title',
        'mime_type',
        'content',
        'read_status',
        'read_error',
        'source_modified_at',
        'required_for_onboarding',
        'onboarding_kind',
    ];

    protected $casts = [
        'source_modified_at' => 'datetime',
        'required_for_onboarding' => 'boolean',
    ];

    /** The file in the Policies folder. A policy from before the folder has none. */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    /** Documents with text the assistant can read. */
    public function scopeReadable(Builder $query): Builder
    {
        return $query->whereNotNull('content')->where('content', '!=', '');
    }

    /**
     * Whether there is text to read: only then can a policy be required for
     * onboarding or agreed to. A replaced file that is still being read, or
     * failed to read, keeps its earlier text, so it still has text.
     */
    public function hasText(): bool
    {
        return trim((string) $this->content) !== '';
    }

    /**
     * rtl when most of the letters are Arabic: the page lays the policy out by
     * its own language, whatever the language of the interface.
     */
    public function direction(): string
    {
        $text = mb_substr((string) $this->content, 0, 4000);
        $arabic = preg_match_all('/\p{Arabic}/u', $text);
        $latin = preg_match_all('/[A-Za-z]/', $text);

        return $arabic >= $latin ? 'rtl' : 'ltr';
    }

    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf';
    }
}
