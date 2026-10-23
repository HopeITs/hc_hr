<?php

namespace App\Livewire\Employee;

use App\Livewire\Concerns\ScopesToAuthorizedCity;
use App\Models\Contract;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Livewire\Component;

class EmployeeStatistics extends Component
{
    use ScopesToAuthorizedCity;

    public function getStatistics($mode): JsonResponse
    {
        return match ($mode) {
            'monthly' => $this->getMonthlyData(),
            'quarterly' => $this->getQuarterlyData(),
            'yearly' => $this->getYearlyData(),
            default => response()->json([]),
        };
    }

    /**
     * Base query for every chart series on this endpoint.
     *
     * All nine aggregates previously counted Contract rows across the whole
     * organization, so a regional HR user reading the dashboard chart learned
     * national hiring, expiry, and termination volumes. Position::inCity is
     * the same scope the contract forms use, so a contract is attributed to a
     * city through its position or that position's centre.
     */
    private function contracts(): Builder
    {
        $cityIds = $this->authorizedCityIds();

        return Contract::query()->when(
            $cityIds !== null,
            fn (Builder $query) => $query->whereHas('position', fn ($p) => $p->inCity($cityIds)),
        );
    }

    private function getMonthlyData(): JsonResponse
    {
        $months = collect(range(0, 5))->map(function ($i) {
            // Chart axis labels: month names in the viewer's language.
            return now()->subMonths($i)->locale(app()->getLocale())->translatedFormat('M');
        })->reverse()->values();

        $new = $months->map(function ($label, $i) {
            $date = now()->subMonths(5 - $i);

            return $this->contracts()->where('is_active', true)
                ->whereMonth('start_date', $date->month)
                ->whereYear('start_date', $date->year)
                ->count();
        });

        $ended = $months->map(function ($label, $i) {
            $date = now()->subMonths(5 - $i);

            return $this->contracts()->whereMonth('end_date', $date->month)
                ->whereYear('end_date', $date->year)
                ->count();
        });

        $terminated = $months->map(function ($label, $i) {
            $date = now()->subMonths(5 - $i);

            return $this->contracts()->whereNotNull('terminated_at')
                ->whereMonth('terminated_at', $date->month)
                ->whereYear('terminated_at', $date->year)
                ->count();
        });

        return response()->json([
            'labels' => $months,
            'new' => $new,
            'ended' => $ended,
            'terminated' => $terminated,
        ]);
    }

    private function getQuarterlyData(): JsonResponse
    {
        $quarters = ['Q1', 'Q2', 'Q3', 'Q4'];

        $new = collect($quarters)->map(fn ($q) => $this->countInQuarter('start_date', $q));
        $ended = collect($quarters)->map(fn ($q) => $this->countInQuarter('end_date', $q));
        $terminated = collect($quarters)->map(fn ($q) => $this->countInQuarter('terminated_at', $q, true));

        return response()->json([
            'labels' => collect($quarters)->map(fn (string $q) => __('employees.statistics.quarter', ['number' => substr($q, 1)])),
            'new' => $new,
            'ended' => $ended,
            'terminated' => $terminated,
        ]);
    }

    private function getYearlyData(): JsonResponse
    {
        $years = range(now()->year - 4, now()->year);

        $new = collect($years)->map(fn ($year) => $this->contracts()->where('is_active', true)->whereYear('start_date', $year)->count(),
        );

        $ended = collect($years)->map(fn ($year) => $this->contracts()->whereYear('end_date', $year)->count(),
        );

        $terminated = collect($years)->map(fn ($year) => $this->contracts()->whereNotNull('terminated_at')->whereYear('terminated_at', $year)->count(),
        );

        return response()->json([
            'labels' => $years,
            'new' => $new,
            'ended' => $ended,
            'terminated' => $terminated,
        ]);
    }

    private function countInQuarter(string $column, string $quarter, bool $checkNull = false): int
    {
        $year = now()->year;
        $ranges = [
            'Q1' => [1, 3],
            'Q2' => [4, 6],
            'Q3' => [7, 9],
            'Q4' => [10, 12],
        ];

        [$startMonth, $endMonth] = $ranges[$quarter];

        $start = Carbon::create($year, $startMonth, 1)->startOfMonth();
        $end = Carbon::create($year, $endMonth, 1)->endOfMonth();

        $query = $this->contracts()->whereBetween($column, [$start, $end]);

        if ($checkNull) {
            $query->whereNotNull($column);
        }

        return $query->count();
    }
}
