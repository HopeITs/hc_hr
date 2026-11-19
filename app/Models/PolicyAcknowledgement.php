<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A volunteer's "read and agree" on one policy document (track 7). One row
 * per volunteer and document; it keeps the version of the document that was
 * read. Only PolicyAcknowledgements writes it.
 */
class PolicyAcknowledgement extends Model
{
    protected $guarded = ['*'];

    protected $casts = [
        'acknowledged_at' => 'datetime',
        'document_modified_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(PolicyDocument::class, 'policy_document_id');
    }
}
