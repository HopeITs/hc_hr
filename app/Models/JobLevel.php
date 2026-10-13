<?php

namespace App\Models;

use App\Traits\HasTranslation;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class JobLevel extends Model
{
    use HasFactory, HasTranslation, LogsActivity;

    protected $fillable = [
        'name_en',
        'name_ar',
        'description',
        'grade',
        'email',
    ];

    public function positions()
    {
        return $this->hasMany(Position::class, 'job_level_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('job_level')
            ->logOnly([
                'name',        // translatable accessor
                'name_en',
                'name_ar',
                'description', // translatable field
                'grade',
                'email',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected function name(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->getTranslatableAttribute('name'),
            set: fn ($value) => $this->setTranslatableAttribute('name', $value),
        );
    }
}
