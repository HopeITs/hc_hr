<?php

namespace Tests\Feature\Recruitment;

use App\Enums\RecruitmentFileScanStatus;
use App\Exceptions\RecruitmentScannerUnavailableException;
use App\Jobs\AnalyzeRecruitmentCv;
use App\Jobs\ScanRecruitmentApplicantFile;
use App\Livewire\Recruitment\Applicants\Show;
use App\Models\City;
use App\Models\RecruitmentApplicantFile;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentCvAnalysis;
use App\Models\RecruitmentRequestPositionDetail;
use App\Models\RecruitmentRequestVersion;
use App\Models\Role;
use App\Models\User;
use App\Services\Recruitment\Cv\CvTextExtractor;
use App\Services\Recruitment\Scanning\ClamdScanner;
use App\Services\Recruitment\Scanning\RecruitmentFileScanner;
use App\Services\RecruitmentApplicantFileService;
use App\Services\RecruitmentCvAnalysisService;
use App\Services\RecruitmentFileScanService;
use App\Support\Recruitment\ScanResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\BuildsRecruitmentApplications;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;
use ZipArchive;

class RecruitmentCvScanningTest extends TestCase
{
    use BuildsRecruitmentApplications, CreatesHrFixtures, RefreshDatabase;

    private City $homs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);
        Storage::fake('recruitment-quarantine');
        Storage::fake('recruitment-files');
        Storage::fake('public');

        $this->homs = $this->makeCity('HMS', '002');
    }

    // ------------------------------------------------------------------
    // Scanning
    // ------------------------------------------------------------------

    public function test_an_upload_queues_its_scan_only_once_the_submission_commits(): void
    {
        Bus::fake([ScanRecruitmentApplicantFile::class]);
        $application = $this->application();

        DB::transaction(function () use ($application): void {
            app(RecruitmentApplicantFileService::class)->store($application, $this->pdf(), 'cv');
            Bus::assertNotDispatched(ScanRecruitmentApplicantFile::class);
        });

        Bus::assertDispatched(ScanRecruitmentApplicantFile::class, 1);

        // A submission that rolls back queues nothing.
        try {
            DB::transaction(function () use ($application): void {
                app(RecruitmentApplicantFileService::class)->store($application, $this->pdf('photo.pdf'), 'portfolio');
                throw new RuntimeException('rolled back');
            });
        } catch (RuntimeException) {
        }
        Bus::assertDispatched(ScanRecruitmentApplicantFile::class, 1);
    }

    public function test_a_clean_file_is_released_and_its_cv_is_queued_for_analysis(): void
    {
        config(['recruitment.cv_analysis.enabled' => true]);
        $this->scannerSays(ScanResult::clean('clamav'));
        $file = $this->pendingCv();

        $this->assertSame(RecruitmentFileScanService::CLEAN, app(RecruitmentFileScanService::class)->scan($file->id));

        $file->refresh();
        $media = $file->getFirstMedia('document');
        $this->assertSame(RecruitmentFileScanStatus::Clean, $file->scan_status);
        $this->assertSame('recruitment-files', $media->disk);
        $this->assertSame('clamav', $media->getCustomProperty('scan_metadata')['engine']);
        Bus::assertDispatched(AnalyzeRecruitmentCv::class, fn ($job) => $job->fileId === $file->id);

        // Running it again does nothing.
        $this->assertSame(RecruitmentFileScanService::SKIPPED, app(RecruitmentFileScanService::class)->scan($file->id));
    }

    public function test_an_infected_file_is_locked_logged_and_never_downloadable(): void
    {
        $this->scannerSays(ScanResult::infected('clamav', 'Eicar-Signature'));
        $file = $this->pendingCv();

        $this->assertSame(RecruitmentFileScanService::INFECTED, app(RecruitmentFileScanService::class)->scan($file->id));

        $file->refresh();
        $this->assertSame(RecruitmentFileScanStatus::Quarantined, $file->scan_status);
        $this->assertSame('recruitment-quarantine', $file->getFirstMedia('document')->disk);
        $this->assertSame('Eicar-Signature', Activity::query()->where('description', 'recruitment_file.infected')->sole()->properties['signature']);
        $this->assertSame(0, RecruitmentCvAnalysis::query()->count());

        $this->actingAs($this->hr())
            ->get(route('recruitment.applicant-files.download', $file))
            ->assertForbidden();
    }

    public function test_a_scanner_that_cannot_answer_leaves_the_file_pending_for_a_retry(): void
    {
        $this->scannerSays(ScanResult::error('clamav', 'clamd is not reachable'));
        $file = $this->pendingCv();

        try {
            app(RecruitmentFileScanService::class)->scan($file->id);
            $this->fail('A scanner outage must be retried, not recorded as a verdict.');
        } catch (RecruitmentScannerUnavailableException) {
        }

        $this->assertSame(RecruitmentFileScanStatus::Pending, $file->fresh()->scan_status);

        // A file clamd refuses outright (too large) will not improve with retries.
        $this->scannerSays(ScanResult::error('clamav', 'too large', permanent: true));
        $this->assertSame(RecruitmentFileScanService::FAILED, app(RecruitmentFileScanService::class)->scan($file->id));
        $this->assertSame(RecruitmentFileScanStatus::ScanFailed, $file->fresh()->scan_status);
    }

    public function test_disabled_scanning_never_releases_a_file(): void
    {
        config(['recruitment-uploads.scanner.driver' => 'disabled']);
        $file = $this->pendingCv();

        $this->assertSame(RecruitmentFileScanService::DISABLED, app(RecruitmentFileScanService::class)->scan($file->id));
        $this->assertSame(RecruitmentFileScanStatus::Pending, $file->fresh()->scan_status);
        $this->artisan('recruitment:scanner-check')->assertFailed();
    }

    public function test_the_sweep_scans_the_backlog_but_leaves_fresh_uploads_to_their_own_job(): void
    {
        $this->scannerSays(ScanResult::clean('clamav'));
        config(['recruitment.cv_analysis.enabled' => false]);
        $old = $this->pendingCv();
        $old->forceFill(['uploaded_at' => now()->subHour()])->save();
        $fresh = $this->pendingCv($this->application('Second'));

        $this->artisan('recruitment:scan-files', ['--now' => true])->assertSuccessful();

        $this->assertSame(RecruitmentFileScanStatus::Clean, $old->fresh()->scan_status);
        $this->assertSame(RecruitmentFileScanStatus::Pending, $fresh->fresh()->scan_status);
    }

    public function test_clamd_replies_are_read_correctly(): void
    {
        $clamd = new ClamdScanner;

        $this->assertTrue($clamd->interpret("stream: OK\0")->isClean());
        $infected = $clamd->interpret('stream: Win.Test.EICAR_HDB-1 FOUND');
        $this->assertTrue($infected->isInfected());
        $this->assertSame('Win.Test.EICAR_HDB-1', $infected->signature);
        $this->assertTrue($clamd->interpret('INSTREAM size limit exceeded. ERROR')->permanent);

        $garbage = $clamd->interpret('');
        $this->assertFalse($garbage->isClean() || $garbage->isInfected() || $garbage->permanent);
    }

    // ------------------------------------------------------------------
    // Analysis
    // ------------------------------------------------------------------

    public function test_a_clean_cv_is_read_and_compared_with_the_request(): void
    {
        Process::fake(['*' => Process::result(<<<'CV'
            Rana Haddad — Field Officer
            Experience
            2019 - 2024 Case worker, local NGO
            Skills: Excel, psychosocial support
            Education: Bachelor of Social Work
            Languages: Arabic, English
            CV)]);
        $application = $this->application(requirements: [
            'required_experience_years' => 3,
            'required_qualifications' => 'Bachelor degree',
            'required_skills' => 'إكسل، الدعم النفسي، قيادة سيارة',
        ]);
        $file = $this->cleanCv($application);

        $analysis = app(RecruitmentCvAnalysisService::class)->analyze($file->load('application.campaign.vacancy.createdFromRequest.latestVersion.positionDetail'));

        $this->assertSame(RecruitmentCvAnalysis::COMPLETED, $analysis->status);
        $this->assertSame('pdftotext', $analysis->extraction_method);
        $this->assertSame(5, $analysis->facts_json['experience']['years']);
        $this->assertSame('met', $analysis->comparison_json['experience']['outcome']);
        $this->assertSame('met', $analysis->comparison_json['education']['outcome']);
        $this->assertSame([2, 3], [$analysis->comparison_json['skills']['matched'], $analysis->comparison_json['skills']['total']]);
        // Facts only: the CV's own text is not stored anywhere.
        $this->assertStringNotContainsString('Rana Haddad', json_encode($analysis->getAttributes()));
        Process::assertRan(fn ($process) => $process->command[0] === 'pdftotext');
        $this->assertSame([], glob(storage_path('app/private/cv-analysis/*')) ?: []);
    }

    public function test_a_missing_tool_is_reported_not_guessed(): void
    {
        Process::fake(['*' => Process::result(exitCode: 127)]);
        $file = $this->cleanCv($this->application());

        $analysis = app(RecruitmentCvAnalysisService::class)->analyze($file);

        $this->assertSame(RecruitmentCvAnalysis::TOOL_MISSING, $analysis->status);
        $this->assertNull($analysis->facts_json);
    }

    public function test_a_docx_cv_is_read_without_any_external_tool(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cv').'.docx';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="w"><w:body>'
            .'<w:p><w:r><w:t>خبرة 4 سنوات</w:t></w:r></w:p><w:p><w:r><w:t>ماجستير &amp; Excel</w:t></w:r></w:p>'
            .'</w:body></w:document>');
        $zip->close();

        try {
            $result = app(CvTextExtractor::class)->extract($path, 'docx');
        } finally {
            @unlink($path);
        }

        $this->assertSame([RecruitmentCvAnalysis::COMPLETED, 'docx'], [$result['status'], $result['method']]);
        $this->assertSame(['خبرة 4 سنوات', 'ماجستير & Excel'], array_values(array_filter(array_map('trim', explode("\n", $result['text'])))));
    }

    public function test_the_profile_shows_the_reading_only_to_those_who_may_open_the_cv(): void
    {
        Process::fake(['*' => Process::result("Experience\n2018 - 2023 Accountant\nSkills: Excel")]);
        $application = $this->application(requirements: ['required_skills' => 'Excel']);
        $file = $this->cleanCv($application);
        app(RecruitmentCvAnalysisService::class)->analyze($file);
        $hr = $this->hr();

        $this->actingAs($hr);
        Livewire::test(Show::class, ['applicantId' => $application->applicant_id])
            ->assertSee(__('recruitment-applicant.cv_analysis.title'))
            ->assertSee(__('recruitment-applicant.cv_analysis.required_skills', ['matched' => 1, 'total' => 1]));

        Role::findByName($hr->myRole(), 'web')->revokePermissionTo('recruitment-applicants.download-cv');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($hr->fresh());

        Livewire::test(Show::class, ['applicantId' => $application->applicant_id])
            ->assertDontSee(__('recruitment-applicant.cv_analysis.title'));
    }

    // ------------------------------------------------------------------

    private function scannerSays(ScanResult $result): void
    {
        config(['recruitment-uploads.scanner.driver' => 'clamd']);
        $this->app->instance(RecruitmentFileScanner::class, new class($result) implements RecruitmentFileScanner
        {
            public function __construct(private readonly ScanResult $result) {}

            public function enabled(): bool
            {
                return true;
            }

            public function scan($stream): ScanResult
            {
                stream_get_contents($stream);

                return $this->result;
            }

            public function version(): ?string
            {
                return 'ClamAV test';
            }
        });
    }

    /** @param array<string, mixed> $requirements */
    private function application(string $name = 'Applicant One', array $requirements = []): RecruitmentApplication
    {
        $campaign = $this->makeCampaignIn($this->homs);
        $request = $campaign->vacancy->createdFromRequest;
        $version = RecruitmentRequestVersion::query()->forceCreate([
            'request_id' => $request->id,
            'version_no' => 1,
            'snapshot_json' => [],
            'created_by_user_id' => $request->requester_user_id,
            'submitted_at' => now(),
        ]);
        RecruitmentRequestPositionDetail::query()->forceCreate(['request_version_id' => $version->id] + $requirements);

        return $this->applyTo($campaign, $this->makeApplicant($name));
    }

    private function pendingCv(?RecruitmentApplication $application = null): RecruitmentApplicantFile
    {
        Bus::fake([ScanRecruitmentApplicantFile::class, AnalyzeRecruitmentCv::class]);

        return app(RecruitmentApplicantFileService::class)->store($application ?? $this->application(), $this->pdf(), 'cv');
    }

    private function cleanCv(RecruitmentApplication $application): RecruitmentApplicantFile
    {
        return app(RecruitmentApplicantFileService::class)->markClean($this->pendingCv($application), ['engine' => 'test']);
    }

    private function pdf(string $name = 'cv.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<<>>\n%%EOF\n");
    }

    private function hr(): User
    {
        return $this->makeUser($this->homs, 'HR-HMS', [
            'recruitment-campaigns.view',
            ...array_map(
                fn (string $action): string => "recruitment-applicants.{$action}",
                config('access-control.groups.recruitment-applicants'),
            ),
        ]);
    }
}
