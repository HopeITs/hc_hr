<?php

namespace App\Services;

use App\Enums\RecruitmentFileScanStatus;
use App\Models\RecruitmentApplicantFile;
use App\Models\RecruitmentCvAnalysis;
use App\Services\Recruitment\Cv\CvAnalyzer;
use App\Services\Recruitment\Cv\CvTextExtractor;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Reads a cleared CV and stores what it found.
 *
 * Only a file the antivirus has cleared is ever opened here. The file is
 * copied to a private temporary path for the extraction tools and deleted
 * straight after; the text itself is used in memory and never stored.
 */
class RecruitmentCvAnalysisService
{
    public function __construct(
        private readonly CvTextExtractor $extractor,
        private readonly CvAnalyzer $analyzer,
    ) {}

    public function analyze(RecruitmentApplicantFile $file): ?RecruitmentCvAnalysis
    {
        if (! $file->isCv() || $file->scan_status !== RecruitmentFileScanStatus::Clean) {
            return null;
        }

        $media = $file->getFirstMedia('document');
        if (! $media instanceof Media) {
            return $this->store($file, ['status' => RecruitmentCvAnalysis::FAILED, 'method' => null, 'text' => '', 'error' => 'The CV file is missing.']);
        }

        $extension = strtolower(pathinfo($media->file_name, PATHINFO_EXTENSION));
        $path = $this->temporaryCopy($media, $extension);

        try {
            $extracted = $path === null
                ? ['status' => RecruitmentCvAnalysis::FAILED, 'method' => null, 'text' => '', 'error' => 'The CV could not be read.']
                : $this->extractor->extract($path, $extension);
        } finally {
            if ($path !== null) {
                File::delete($path);
            }
        }

        return $this->store($file, $extracted);
    }

    /** @param array{status: string, method: ?string, text: string, error: ?string} $extracted */
    private function store(RecruitmentApplicantFile $file, array $extracted): RecruitmentCvAnalysis
    {
        $facts = null;
        $comparison = null;
        $status = $extracted['status'];

        if ($status === RecruitmentCvAnalysis::COMPLETED) {
            $facts = $this->analyzer->facts($extracted['text']);
            $detail = $file->application?->campaign?->vacancy?->createdFromRequest?->latestVersion?->positionDetail;
            $comparison = $this->analyzer->compare(
                $extracted['text'],
                $facts,
                $detail?->required_experience_years,
                $detail?->required_qualifications,
                $detail?->required_skills,
            );
        }

        return RecruitmentCvAnalysis::query()->updateOrCreate(
            ['file_id' => $file->getKey()],
            [
                'application_id' => $file->application_id,
                'status' => $status,
                'extraction_method' => $extracted['method'],
                'text_chars' => mb_strlen(trim($extracted['text'])),
                'facts_json' => $facts,
                'comparison_json' => $comparison,
                'analyzer_version' => CvAnalyzer::VERSION,
                'error' => $extracted['error'],
                'analyzed_at' => now(),
            ],
        );
    }

    private function temporaryCopy(Media $media, string $extension): ?string
    {
        $source = Storage::disk($media->disk)->readStream($media->getPathRelativeToRoot());
        if (! is_resource($source)) {
            return null;
        }

        $dir = storage_path('app/private/cv-analysis');
        File::ensureDirectoryExists($dir, 0700);
        // A generated name: nothing the applicant typed reaches the tools.
        $path = $dir.DIRECTORY_SEPARATOR.Str::uuid().'.'.preg_replace('/[^a-z0-9]/', '', $extension);

        try {
            $target = fopen($path, 'wb');
            if ($target === false) {
                return null;
            }
            stream_copy_to_stream($source, $target);
            fclose($target);
        } finally {
            fclose($source);
        }

        return $path;
    }
}
