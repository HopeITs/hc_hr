<?php

namespace App\Livewire\Reports;

use App\Exports\ReportWorkbook;
use App\Models\User;
use App\Services\Reports\RecruitmentFunnelReport;
use App\Support\Reports\ReportCatalog;
use Livewire\Attributes\Url;
use Livewire\Component;
use Maatwebsite\Excel\Facades\Excel;

/** Recruitment funnel: aggregates only, inside the viewer's recruitment scope. */
class RecruitmentFunnel extends Component
{
    #[Url(as: 'period')]
    public int $days = 365;

    public static function mayView(?User $user): bool
    {
        return ReportCatalog::allows('recruitment-funnel', $user);
    }

    public function mount(): void
    {
        abort_unless(self::mayView(auth()->user()), 403);
    }

    public function export()
    {
        abort_unless(self::mayView(auth()->user()), 403);
        $r = $this->report();
        $rows = fn ($c) => $c->map(fn ($x) => [$x['label'], $x['count'], $x['percent'].'%'])->all();

        return Excel::download(new ReportWorkbook([
            ['title' => 'Funnel', 'headings' => ['Stage', 'Applications', 'Share', 'From previous stage %'],
                'rows' => $r['stages']->map(fn ($s) => [$s['label'], $s['count'], $s['percent'].'%', $s['fromPrevious'] ?? '-'])->all()],
            ['title' => 'Summary', 'headings' => ['Metric', 'Value'], 'rows' => [
                ['Requests submitted', $r['requests']['submitted']],
                ['Requests approved', $r['requests']['approved']],
                ['Requests rejected', $r['requests']['rejected']],
                ['Requests cancelled', $r['requests']['cancelled']],
                ['Requests in review', $r['requests']['inReview']],
                ['Days from request to vacancy', $r['requests']['daysToApply'] ?? '-'],
                ['Days to first interview', $r['times']['toInterview'] ?? '-'],
                ['Days to offer', $r['times']['toOffer'] ?? '-'],
                ['Days to hire', $r['times']['toHire'] ?? '-'],
                ['Offer acceptance %', $r['offerAcceptancePercent'] ?? '-'],
                ['Offers expired', $r['offersExpired']],
            ]],
            ['title' => 'By status', 'headings' => ['Status', 'Applications', 'Share'], 'rows' => $rows($r['byStatus'])],
            ['title' => 'By position', 'headings' => ['Position', 'Applications', 'Interviewed', 'Offers', 'Hired'],
                'rows' => $r['byPosition']->map(fn ($p) => [$p['label'], $p['applications'], $p['interviewed'], $p['offers'], $p['hired']])->all()],
        ]), 'recruitment_funnel_'.now()->format('Y-m-d').'.xlsx');
    }

    public function render()
    {
        abort_unless(self::mayView(auth()->user()), 403);

        return view('livewire.reports.recruitment-funnel', [
            'r' => $this->report(),
            'periods' => RecruitmentFunnelReport::PERIODS,
        ]);
    }

    private function report(): array
    {
        return (new RecruitmentFunnelReport(auth()->user(), $this->days))->build();
    }
}
