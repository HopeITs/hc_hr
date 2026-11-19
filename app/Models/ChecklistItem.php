<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ChecklistItem extends Model
{
    use HasFactory, LogsActivity;

    /** Which delivery step of the onboarding journey an item belongs to (OnboardingDeliveries). */
    public const GROUP_ELECTRONICS = 'electronics';

    public const GROUP_CARD = 'card';

    public const GROUP_AFTER_PROBATION = 'after_probation';

    public const GROUP_OTHER = 'other';

    public const GROUP_EXIT = 'exit';

    /** Item name => group for the items the seeder creates; everything else is "other", the order-3 return items "exit". */
    public const GROUP_BY_NAME = [
        'Email' => self::GROUP_ELECTRONICS,
        'Email Signature' => self::GROUP_ELECTRONICS,
        'Laptop' => self::GROUP_ELECTRONICS,
        'Mouse' => self::GROUP_ELECTRONICS,
        'Mobile' => self::GROUP_ELECTRONICS,
        'SIM' => self::GROUP_ELECTRONICS,
        'ID Cartoon' => self::GROUP_CARD,
        'ID Plastic' => self::GROUP_CARD,
        'Mug' => self::GROUP_AFTER_PROBATION,
    ];

    protected $fillable = [
        'name',
        'order',
        'onboarding_group',
    ];

    public function checklist()
    {
        return $this->belongsTo(Checklist::class);
    }

    public function defaultDeliverable()
    {
        return $this->hasMany(DefaultDeliverable::class, 'item_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('checklist_item')
            ->logOnly([
                'name',
                'order',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
