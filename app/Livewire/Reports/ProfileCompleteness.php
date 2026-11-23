<?php

namespace App\Livewire\Reports;

use App\Exports\ReportWorkbook;
use App\Models\City;
use App\Models\User;
use App\Services\Reports\ProfileCompletenessReport;
use App\Support\Reports\ReportCatalog;
use Livewire\Attributes\Url;
use Livewire\Component;
use Maatwebsite\Excel\Facades\Excel;

/** Which employee files are missing what. Presence only: no value is read into the report. */
class ProfileCompleteness extends Component
{
    #[Url(as: 'city')]
    public ?string $cityId = null;

    #[Url(as: 'missing')]
    public ?string $missing = null;

    public static function mayView(?User $user): bool
    {
        return ReportCatalog::allows('profile-completeness', $user);
    }

    public function mount(): void
    {
        abort_unless(self::mayView(auth()->user()), 403);
    }

    public function export()
    {
        abort_unless(self::mayView(auth()->user()), 403);
        $r = $this->report();

        return Excel::download(new ReportWorkbook([
            ['title' => 'Summary', 'headings' => ['Metric', 'Value'], 'rows' => [
                ['People', $r['people']], ['Complete files', $r['complete']], ['Average completeness %', $r['averagePercent'] ?? '-'],
            ]],
            ['title' => 'By item', 'headings' => ['Item', 'People with it', 'Share'],
                'rows' => $r['byItem']->map(fn ($i) => [$i['label'], $i['count'], $i['percent'].'%'])->all()],
            ['title' => 'By city', 'headings' => ['City', 'People', 'Average completeness %'],
                'rows' => $r['byCity']->map(fn ($c) => [$c['label'], $c['count'], $c['percent']])->all()],
            ['title' => 'Incomplete files', 'headings' => ['Name', 'City', 'Completeness %', 'Missing'],
                'rows' => $r['list']->map(fn ($p) => [$p['name'], $p['city'], $p['percent'], implode(', ', $p['missingLabels'])])->all()],
        ]), 'profile_completeness_'.now()->format('Y-m-d').'.xlsx');
    }

    public function render()
    {
        abort_unless(self::mayView(auth()->user()), 403);
        $cityIds = auth()->user()->activeCityIds();

        return view('livewire.reports.profile-completeness', [
            'r' => $this->report(),
            'cities' => City::query()->when($cityIds !== null, fn ($q) => $q->whereIn('id', $cityIds))->orderBy('id')->get()->pluck('name', 'id')->all(),
            'items' => ProfileCompletenessReport::labels(),
            'limit' => ProfileCompletenessReport::LIST_LIMIT,
        ]);
    }

    private function report(): array
    {
        return (new ProfileCompletenessReport(
            auth()->user(),
            filled($this->cityId) ? (int) $this->cityId : null,
            filled($this->missing) ? $this->missing : null,
        ))->build();
    }
}
