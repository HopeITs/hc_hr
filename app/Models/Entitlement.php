<?php

namespace App\Models;

use App\Traits\HasTranslation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An item of the Permissions Library (meeting 19/11, track 5): access a job
 * needs outside this system. Recorded and tracked here, never enforced, and
 * never a Spatie permission.
 */
class Entitlement extends Model
{
    use HasTranslation;

    public const CATEGORIES = ['database', 'telegram', 'mail_list', 'camera', 'it_maintenance', 'other'];

    protected $fillable = ['name_en', 'name_ar', 'category', 'reference', 'mail_list_id', 'description', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function mailList(): BelongsTo
    {
        return $this->belongsTo(MailList::class);
    }

    public function defaults(): HasMany
    {
        return $this->hasMany(PositionEntitlement::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(EmployeeEntitlement::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('is_active'), true);
    }

    protected function name(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->getTranslatableAttribute('name') ?: ($this->name_en ?: $this->name_ar),
        );
    }
}
