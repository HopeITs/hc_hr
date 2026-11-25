<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PolicyDocument extends Model
{
    /** Read and agreed. */
    public const KIND_POLICY = 'policy';

    /** Made by another team (the Media guideline) and only reviewed. */
    public const KIND_REVIEW = 'review';

    public const KINDS = [self::KIND_POLICY, self::KIND_REVIEW];

    protected $fillable = [
        'title',
        'mime_type',
        'content',
        'source_modified_at',
        'required_for_onboarding',
        'onboarding_kind',
    ];

    protected $casts = [
        'source_modified_at' => 'datetime',
        'required_for_onboarding' => 'boolean',
    ];

    /** Documents with text the assistant can read. */
    public function scopeReadable(Builder $query): Builder
    {
        return $query->whereNotNull('content')->where('content', '!=', '');
    }
}
