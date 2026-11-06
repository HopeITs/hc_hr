<?php

namespace App\Models;

use App\Casts\EncryptedSalary;
use App\Traits\HasTranslation;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * One pay tier of a flexible contract, as it stood before or after an
 * amendment. Same shape and same protection as ContractPayTier.
 */
class ContractAmendmentPayTier extends Model
{
    use HasTranslation;

    const SIDE_BEFORE = 0;

    const SIDE_AFTER = 1;

    protected $fillable = [
        'contract_amendment_id',
        'side',
        'condition_en',
        'condition_ar',
        'amount',
        'sort_order',
    ];

    protected $casts = [
        'side' => 'integer',
        'amount' => EncryptedSalary::class,
        'sort_order' => 'integer',
    ];

    public function amendment()
    {
        return $this->belongsTo(ContractAmendment::class, 'contract_amendment_id');
    }

    protected function condition(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->getTranslatableAttribute('condition'),
            set: fn ($value) => $this->setTranslatableAttribute('condition', $value),
        );
    }
}
