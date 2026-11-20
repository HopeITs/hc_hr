<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PolicyDocument extends Model
{
    public const SOURCE_DRIVE = 'drive';

    public const SOURCE_PANEL = 'panel';

    /** Read and agreed. */
    public const KIND_POLICY = 'policy';

    /** Made by another team (the Media guideline) and only reviewed. */
    public const KIND_REVIEW = 'review';

    public const KINDS = [self::KIND_POLICY, self::KIND_REVIEW];

    protected $fillable = [
        'source',
        'external_id',
        'title',
        'mime_type',
        'source_url',
        'content',
        'source_modified_at',
        'synced_at',
        'required_for_onboarding',
        'onboarding_kind',
    ];

    protected $casts = [
        'source_modified_at' => 'datetime',
        'synced_at' => 'datetime',
        'required_for_onboarding' => 'boolean',
    ];

    /** Documents with text the assistant can read. */
    public function scopeReadable(Builder $query): Builder
    {
        return $query->whereNotNull('content')->where('content', '!=', '');
    }
}
