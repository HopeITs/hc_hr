<?php

namespace App\Livewire\Reports;

use App\Exports\ReportWorkbook;
use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\User;
use App\Services\Reports\TrainingReport;
use App\Support\Reports\ReportCatalog;
use Livewire\Attributes\Url;
use Livewire\Component;
use Maatwebsite\Excel\Facades\Excel;

/** Courses recorded on employee files. */
class Training extends Component
{
    #[Url(as: 'year')]
    public ?string $year = null;

    #[Url(as: 'category')]
    public ?string $categoryId = null;

    public function mount(): void
    {
        abort_unless(self::mayView(auth()->user()), 403);
        $this->year ??= (string) now()->year;
    }

    public static function mayView(?User $user): bool
    {
        return ReportCatalog::allows('training', $user);
    }

    public function export()
    {
        abort_unless(self::mayView(auth()->user()), 403);
        $r = $this->report();
        $share = fn ($c) => $c->map(fn ($x) => [$x['label'], $x['count'], $x['percent'].'%'])->all();

        return Excel::download(new ReportWorkbook([
            ['title' => 'Summary', 'headings' => ['Metric', 'Value'], 'rows' => [
                ['Year', $r['year'] ?? 'All'], ['Courses', $r['courses']], ['People trained', $r['trained']],
                ['People with a contract in force', $r['people']], ['Coverage %', $r['coveragePercent'] ?? '-'],
                ['Cost', $r['cost']], ['With a certificate', $r['certificates']], ['People with no course', $r['untrainedCount']],
            ]],
            ['title' => 'By category', 'headings' => ['Category', 'Courses', 'Share'], 'rows' => $share($r['byCategory'])],
            ['title' => 'By status', 'headings' => ['Status', 'Courses', 'Share'], 'rows' => $share($r['byStatus'])],
            ['title' => 'By city', 'headings' => ['City', 'Courses', 'Share'], 'rows' => $share($r['byCity'])],
            ['title' => 'By month', 'headings' => ['Month', 'Courses'], 'rows' => $r['byMonth']->map(fn ($n, $m) => [$m, $n])->values()->all()],
            ['title' => 'No course', 'headings' => ['Name', 'City'], 'rows' => $r['untrained']->map(fn ($p) => [$p['name'], $p['city']])->all()],
        ]), 'training_report_'.now()->format('Y-m-d').'.xlsx');
    }

    public function render()
    {
        abort_unless(self::mayView(auth()->user()), 403);

        // toBase(): an empty Eloquent collection stays one after map(), and its unique() expects models.
        $years = Course::query()->whereNotNull('start_date')->get(['start_date'])->toBase()->map(fn ($c) => $c->start_date->year)
            ->push(now()->year)->unique()->sortDesc()->values();

        return view('livewire.reports.training', [
            'r' => $this->report(),
            'years' => $years,
            'categories' => CourseCategory::query()->orderBy(transColumn('name'))->pluck(transColumn('name'), 'id')->all(),
        ]);
    }

    private function report(): array
    {
        return (new TrainingReport(
            auth()->user(),
            filled($this->year) ? (int) $this->year : null,
            filled($this->categoryId) ? (int) $this->categoryId : null,
        ))->build();
    }
}
