<?php

namespace App\Models;

use App\Traits\HasTranslation;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class City extends Model
{
    use HasFactory, HasTranslation, LogsActivity;

    /**
     * Slug of the nationwide "city".
     *
     * Syria is not a city beside Aleppo or Homs — it is the headquarters scope
     * that sits above all of them. A contract issued against it may be held by
     * an employee from any city, so the employee dropdown and the city
     * consistency check both drop their city filter for this one row.
     */
    public const NATIONAL_SLUG = 'SYR';

    protected $fillable = [
        'name_en',
        'name_ar',
        'code',
        // The emergency number on this city's public ID cards (A2D-Q7).
        'hr_hotline',
        'is_active',
    ];

    /**
     * Is $cityId the nationwide scope?
     *
     * Resolved by slug rather than by a hard-coded id, because the ids are
     * assigned by whichever order CitySeeder happened to run in.
     */
    public static function isNational(?int $cityId): bool
    {
        return $cityId !== null
            && static::query()
                ->whereKey($cityId)
                ->where('slug', self::NATIONAL_SLUG)
                ->exists();
    }

    /**
     * City slugs a regional HR role is responsible for.
     *
     * Usually just the slug in the role name (HR-ALP → ALP), but a role may
     * cover several cities — HR-HMS also covers Homs Rural.
     *
     * @return array<int, string>
     */
    public static function slugsForHrRole(string $roleName): array
    {
        $roleName = strtoupper(trim($roleName));

        return config('access-control.hr_role_cities.'.$roleName)
            ?? [Str::after($roleName, 'HR-')];
    }

    /**
     * The regional HR roles responsible for the city with this slug — the
     * reverse of slugsForHrRole(), for routing notifications.
     *
     * @return array<int, string>
     */
    public static function hrRolesForSlug(string $slug): array
    {
        $slug = strtoupper($slug);

        $roles = collect(config('access-control.hr_role_cities', []))
            ->filter(fn (array $slugs) => in_array($slug, $slugs, true))
            ->keys();

        return $roles->isNotEmpty() ? $roles->values()->all() : ['HR-'.$slug];
    }

    public function centers()
    {
        return $this->hasMany(Center::class);
    }

    public function positions()
    {
        return $this->hasMany(Position::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('city')
            ->logOnly([
                'name',
                'name_en',
                'name_ar',
                'code',
                'hr_hotline',
                'is_active',
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
