<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The days a contract works from effective_from on, until the next row.
 * weekdays null = no days of its own from that date: the day range and the
 * center's weekend apply again. Changed through ContractWorkPatterns only.
 */
class ContractWorkPattern extends Model
{
    protected $fillable = ['contract_id', 'weekdays', 'effective_from', 'contract_amendment_id', 'created_by', 'note'];

    protected $casts = [
        'effective_from' => 'date',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /** The amendment that set these days, when one did. */
    public function amendment(): BelongsTo
    {
        return $this->belongsTo(ContractAmendment::class, 'contract_amendment_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return array<int, int>|null */
    public function days(): ?array
    {
        $days = Contract::parseWeekdays($this->weekdays);

        return $days === [] ? null : $days;
    }
}
