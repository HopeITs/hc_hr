<?php

namespace App\Models;

use App\Enums\LeavingCategory;
use App\Traits\HasTranslation;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TerminationReason extends Model
{
    use HasFactory,HasTranslation;

    protected $fillable = ['title_en', 'title_ar', 'category'];

    protected $casts = ['category' => LeavingCategory::class];

    protected function title(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->getTranslatableAttribute('title'),
            set: fn ($value) => $this->setTranslatableAttribute('title', $value),
        );
    }
}
