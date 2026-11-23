<?php

namespace App\Services\Reports;

use App\Models\Contract;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Whose file is missing what, for people with a contract in force today inside
 * the viewer's scope. It only asks whether each item is there: no value (phone,
 * number, address, photo) is read into the report, so it exposes nothing the
 * employee list does not.
 */
class ProfileCompletenessReport
{
    public const LIST_LIMIT = 50;

    /** The checks, in the order they are shown. */
    public const ITEMS = ['photo', 'national_number', 'dob', 'gender', 'nationality', 'personal_phone', 'personal_email', 'address', 'education', 'emergency_contact'];

    public function __construct(public readonly User $viewer, public readonly ?int $cityId = null, public readonly ?string $missing = null) {}

    /** @return array<string, string> check key => label */
    public static function labels(): array
    {
        return [
            'photo' => __('Photo'),
            'national_number' => __('National number'),
            'dob' => __('Date of birth'),
            'gender' => __('Gender'),
            'nationality' => __('Nationality'),
            'personal_phone' => __('Personal phone'),
            'personal_email' => __('Personal email'),
            'address' => __('Address'),
            'education' => __('Education'),
            'emergency_contact' => __('Emergency contact'),
        ];
    }

    /** @return array<string, mixed> */
    public function build(): array
    {
        $labels = self::labels();

        $people = $this->employees()->get()->map(function (Employee $e) use ($labels) {
            $present = [
                'photo' => $e->avatar_count > 0,
                'national_number' => filled($e->national_number),
                'dob' => $e->dob !== null,
                'gender' => filled($e->gender),
                'nationality' => filled($e->nationality),
                'personal_phone' => filled($e->personal_phone),
                'personal_email' => filled($e->personal_email),
                'address' => filled($e->address_en) || filled($e->address_ar),
                'education' => $e->educations_count > 0,
                'emergency_contact' => $e->relatives_count > 0,
            ];
            $missing = array_keys(array_filter($present, fn (bool $ok) => ! $ok));

            return [
                'name' => $e->name,
                'username' => $e->username,
                'city_id' => $e->city_id,
                'city' => $e->city?->name ?: '-',
                'missing' => $missing,
                'missingLabels' => array_map(fn (string $k) => $labels[$k], $missing),
                'percent' => round((count($present) - count($missing)) / count($present) * 100, 1),
            ];
        });

        $count = $people->count();
        $complete = $people->filter(fn (array $p) => $p['missing'] === []);
        $listed = $people->reject(fn (array $p) => $p['missing'] === [])
            ->when($this->missing && isset($labels[$this->missing]), fn (Collection $c) => $c->filter(fn (array $p) => in_array($this->missing, $p['missing'], true)))
            ->sortBy([['percent', 'asc'], ['name', 'asc']])->values();

        return [
            'people' => $count,
            'complete' => $complete->count(),
            'averagePercent' => $count ? round($people->avg('percent'), 1) : null,
            'byItem' => collect($labels)->map(fn (string $label, string $key) => [
                'key' => $key,
                'label' => $label,
                'count' => $people->filter(fn (array $p) => ! in_array($key, $p['missing'], true))->count(),
                'percent' => $count ? round($people->filter(fn (array $p) => ! in_array($key, $p['missing'], true))->count() / $count * 100, 1) : 0.0,
            ])->values(),
            'byCity' => $people->groupBy('city')->map(fn (Collection $g, string $city) => [
                'label' => $city,
                'count' => $g->count(),
                'percent' => round($g->avg('percent'), 1),
            ])->sortBy('percent')->values(),
            'list' => $listed,
            'listTotal' => $listed->count(),
        ];
    }

    private function employees(): Builder
    {
        $ids = Contract::query()->activeNow()->visibleTo($this->viewer)->pluck('employee_id')->unique();

        return Employee::query()
            ->where('is_active', true)
            ->whereIn('id', $ids)
            ->when($this->cityId, fn (Builder $q) => $q->where('city_id', $this->cityId))
            ->withCount([
                'educations',
                'relatives',
                'media as avatar_count' => fn (Builder $m) => $m->where('collection_name', 'avatar'),
            ])
            ->with('city');
    }
}
