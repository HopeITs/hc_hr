<?php

namespace App\Console\Commands;

use App\Enums\RecruitmentFileScanStatus;
use App\Models\RecruitmentApplicantFile;
use App\Models\RecruitmentCvAnalysis;
use App\Services\Recruitment\Cv\CvAnalyzer;
use App\Services\RecruitmentCvAnalysisService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Reads cleared CVs that have no reading yet, or — with --stale — whose
 * reading predates the current analyzer rules or failed for want of a tool.
 * Writes analysis rows only; no status, decision or tag is touched.
 */
class AnalyzeRecruitmentCvs extends Command
{
    protected $signature = 'recruitment:analyze-cvs
        {--stale : Also redo readings from older rules, or that failed for a missing tool}
        {--chunk=50 : CVs per batch}';

    protected $description = 'Extract skills, education, languages and experience from cleared CVs';

    public function handle(RecruitmentCvAnalysisService $analyses): int
    {
        $query = RecruitmentApplicantFile::query()
            ->where('file_type', RecruitmentApplicantFile::TYPE_CV)
            ->where('scan_status', RecruitmentFileScanStatus::Clean)
            ->where(fn ($query) => $query
                ->whereDoesntHave('cvAnalysis')
                ->when($this->option('stale'), fn ($query) => $query->orWhereHas('cvAnalysis', fn ($analysis) => $analysis
                    ->where('analyzer_version', '!=', CvAnalyzer::VERSION)
                    ->orWhere('status', RecruitmentCvAnalysis::TOOL_MISSING))));

        $counts = [];
        $query->with('application.campaign.vacancy.createdFromRequest.latestVersion.positionDetail')
            ->chunkById(max(1, (int) $this->option('chunk')), function (Collection $files) use ($analyses, &$counts): void {
                foreach ($files as $file) {
                    $status = $analyses->analyze($file)?->status ?? 'skipped';
                    $counts[$status] = ($counts[$status] ?? 0) + 1;
                }
            });

        if ($counts === []) {
            $this->info('Nothing to analyze.');

            return self::SUCCESS;
        }

        $this->table(['Status', 'CVs'], collect($counts)->map(fn ($count, $status) => [$status, $count])->values());

        if (isset($counts[RecruitmentCvAnalysis::TOOL_MISSING])) {
            $this->warn('Some tools are missing: apt install poppler-utils tesseract-ocr tesseract-ocr-ara antiword');
        }

        return self::SUCCESS;
    }
}
