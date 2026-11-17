<?php

namespace Tests\Feature\Recruitment;

use App\Enums\HiringCampaignStatus;
use App\Enums\RecruitmentCampaignLinkMode;
use App\Enums\RecruitmentFileScanStatus;
use App\Enums\RecruitmentRequestMode;
use App\Enums\RecruitmentRequestStatus;
use App\Enums\RecruitmentRequestType;
use App\Exceptions\UnsafeRecruitmentFileException;
use App\Http\Controllers\RecruitmentApplicantFileController;
use App\Models\ApplicationForm;
use App\Models\Department;
use App\Models\HiringCampaign;
use App\Models\JobLevel;
use App\Models\JobPost;
use App\Models\Position;
use App\Models\Program;
use App\Models\PublicRecruitmentLink;
use App\Models\RecruitmentApplicant;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentRequest;
use App\Models\RecruitmentRequestVersion;
use App\Models\User;
use App\Models\Vacancy;
use App\Services\RecruitmentApplicantFileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Spatie\Activitylog\Models\Activity;
use Spatie\MediaLibrary\MediaCollections\Exceptions\DiskDoesNotExist;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

class RecruitmentApplicantFileSecurityTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            LocaleSessionRedirect::class,
            LaravelLocalizationRedirectFilter::class,
        ]);

        Storage::fake('recruitment-quarantine');
        Storage::fake('recruitment-files');
        Storage::fake('public');
        config([
            'recruitment-uploads.quarantine_disk' => 'recruitment-quarantine',
            'recruitment-uploads.clean_disk' => 'recruitment-files',
        ]);
    }

    public function test_valid_cv_is_quarantined_with_a_generated_name(): void
    {
        [$application] = $this->makeApplication();

        $file = app(RecruitmentApplicantFileService::class)->store(
            $application,
            $this->pdf('Samer CV final.pdf'),
            'cv',
        );

        $media = $file->getFirstMedia('document');

        $this->assertSame(RecruitmentFileScanStatus::Pending, $file->scan_status);
        $this->assertSame('recruitment-quarantine', $media->disk);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.pdf$/',
            $media->file_name,
        );
        $this->assertStringNotContainsString('Samer', $media->file_name);
        $this->assertSame(64, strlen((string) $media->getCustomProperty('sha256')));
        Storage::disk('recruitment-quarantine')->assertExists($media->getPathRelativeToRoot());
        Storage::disk('public')->assertMissing($media->getPathRelativeToRoot());
    }

    public function test_executable_or_mismatched_files_are_rejected_before_database_storage(): void
    {
        [$application] = $this->makeApplication();
        $service = app(RecruitmentApplicantFileService::class);

        foreach ([
            UploadedFile::fake()->createWithContent('cv.php.pdf', "%PDF-1.4\n<?php echo 'owned';"),
            UploadedFile::fake()->createWithContent('cv.pdf', "<?php echo 'owned';"),
            UploadedFile::fake()->createWithContent('cv.pdf', "\x89PNG\r\n\x1A\nnot-a-pdf"),
        ] as $upload) {
            try {
                $service->store($application, $upload, 'cv');
                $this->fail('The unsafe upload was accepted.');
            } catch (UnsafeRecruitmentFileException) {
                // Expected.
            }
        }

        $this->assertDatabaseCount('recruitment_applicant_files', 0);
        $this->assertDatabaseCount('media', 0);
    }

    public function test_actual_byte_size_and_per_field_count_limits_are_enforced(): void
    {
        [$application] = $this->makeApplication();
        $service = app(RecruitmentApplicantFileService::class);

        config(['recruitment-uploads.max_bytes' => 32]);
        try {
            $service->store($application, $this->pdf('large.pdf'), 'cv');
            $this->fail('The oversized upload was accepted.');
        } catch (UnsafeRecruitmentFileException) {
            // Expected.
        }

        config(['recruitment-uploads.max_bytes' => 10 * 1024 * 1024]);
        $service->store($application, $this->pdf('first.pdf'), 'cv');

        $this->expectException(UnsafeRecruitmentFileException::class);
        $service->store($application, $this->pdf('second.pdf'), 'cv');
    }

    public function test_storage_failure_rolls_back_the_applicant_file_row(): void
    {
        [$application] = $this->makeApplication();
        config(['recruitment-uploads.quarantine_disk' => 'missing-recruitment-disk']);

        try {
            app(RecruitmentApplicantFileService::class)->store($application, $this->pdf('cv.pdf'), 'cv');
            $this->fail('The missing disk did not fail.');
        } catch (DiskDoesNotExist) {
            // Expected from the filesystem manager.
        }

        $this->assertDatabaseCount('recruitment_applicant_files', 0);
        $this->assertDatabaseCount('media', 0);
    }

    public function test_only_clean_files_can_be_downloaded_by_hr_inside_the_campaign_scope(): void
    {
        [$application, $hr] = $this->makeApplication();
        $service = app(RecruitmentApplicantFileService::class);
        $file = $service->store($application, $this->pdf('cv.pdf'), 'cv');

        $this->actingAs($hr)
            ->get(route('recruitment.applicant-files.download', $file))
            ->assertForbidden();

        $clean = $service->markClean($file, ['engine' => 'test-scanner', 'result' => 'clean']);
        $media = $clean->getFirstMedia('document');

        $this->assertSame(RecruitmentFileScanStatus::Clean, $clean->scan_status);
        $this->assertSame('recruitment-files', $media->disk);
        Storage::disk('recruitment-files')->assertExists($media->getPathRelativeToRoot());
        Storage::disk('recruitment-quarantine')->assertMissing($media->getPathRelativeToRoot());

        $download = $this->actingAs($hr)
            ->get(route('recruitment.applicant-files.download', $clean));
        $download->assertOk()->assertHeader('x-content-type-options', 'nosniff');
        $this->assertStringContainsString('private', (string) $download->headers->get('cache-control'));
        $this->assertStringContainsString('no-store', (string) $download->headers->get('cache-control'));

        $otherCity = $this->makeCity('ALP', '001');
        $otherHr = $this->makeUser($otherCity, 'HR-ALP', ['recruitment-applicants.download-cv']);

        $this->actingAs($otherHr)
            ->get(route('recruitment.applicant-files.download', $clean))
            ->assertForbidden();
    }

    public function test_scan_failure_stays_private_and_cannot_be_downloaded(): void
    {
        [$application, $hr] = $this->makeApplication();
        $service = app(RecruitmentApplicantFileService::class);
        $file = $service->store($application, $this->pdf('cv.pdf'), 'cv');

        $failed = $service->markScanFailed($file, ['reason' => 'scanner unavailable']);

        $this->assertSame(RecruitmentFileScanStatus::ScanFailed, $failed->scan_status);
        $this->assertSame('recruitment-quarantine', $failed->getFirstMedia('document')->disk);
        $this->actingAs($hr)
            ->get(route('recruitment.applicant-files.download', $failed))
            ->assertForbidden();
    }

    public function test_file_metadata_comes_from_the_stored_bytes_and_a_sanitized_name(): void
    {
        [$application] = $this->makeApplication();
        $upload = $this->pdf("..\\../etc/Samer\x07 CV:final.PDF");

        $file = app(RecruitmentApplicantFileService::class)->store($application, $upload, 'cv');
        $media = $file->getFirstMedia('document');

        // All metadata lives on the Spatie media row; no extra columns or tables.
        $this->assertSame('Samer CVfinal.pdf', $media->name);
        $this->assertStringNotContainsString('Samer', $media->file_name);
        $this->assertSame('application/pdf', $media->getCustomProperty('detected_mime'));
        $this->assertSame(Storage::disk('recruitment-quarantine')->size($media->getPathRelativeToRoot()), (int) $media->size);
        $this->assertSame(RecruitmentFileScanStatus::Pending, $file->scan_status);
    }

    public function test_pdfs_carrying_active_content_are_rejected(): void
    {
        [$application] = $this->makeApplication();
        $service = app(RecruitmentApplicantFileService::class);
        $hidden = gzcompress('<< /Type /Action /S /Launch /F (cmd.exe) >>');

        foreach ([
            "%PDF-1.4\n1 0 obj\n<< /OpenAction << /S /JavaScript /JS (app.alert(1)) >> >>\nendobj\n%%EOF\n",
            "%PDF-1.4\n1 0 obj\n<< /S /J#61vaScript >>\nendobj\n%%EOF\n",
            "%PDF-1.4\n1 0 obj\n<< /Names << /EmbeddedFiles 2 0 R >> >>\nendobj\n%%EOF\n",
            "%PDF-1.5\n1 0 obj\n<< /Type /ObjStm /Filter /FlateDecode >>\nstream\n{$hidden}\nendstream\nendobj\n%%EOF\n",
        ] as $index => $content) {
            try {
                $service->store($application, UploadedFile::fake()->createWithContent('cv.pdf', $content), 'cv');
                $this->fail("Active PDF #{$index} was accepted.");
            } catch (UnsafeRecruitmentFileException) {
                // Expected.
            }
        }

        $this->assertDatabaseCount('recruitment_applicant_files', 0);

        // A compressed stream with ordinary page content is still accepted.
        $page = gzcompress('BT /F1 12 Tf 72 712 Td (Curriculum Vitae) Tj ET');
        $service->store($application, UploadedFile::fake()->createWithContent(
            'cv.pdf',
            "%PDF-1.4\n1 0 obj\n<< /Length 50 /Filter /FlateDecode >>\nstream\n{$page}\nendstream\nendobj\n%%EOF\n",
        ), 'cv');
        $this->assertDatabaseCount('recruitment_applicant_files', 1);
    }

    public function test_pdf_stream_inspection_accepts_sync_flushed_streams_and_caps_inflation(): void
    {
        [$application] = $this->makeApplication();
        $service = app(RecruitmentApplicantFileService::class);
        $wrap = fn (string $stream): string => "%PDF-1.5\n1 0 obj\n<< /Filter /FlateDecode >>\nstream\n{$stream}\nendstream\nendobj\n%%EOF\n";

        // Real writers end xref streams with a sync flush and no final block.
        $deflate = deflate_init(ZLIB_ENCODING_DEFLATE);
        $syncFlushed = deflate_add($deflate, '<< /Type /XRef /W [1 2 1] >>', ZLIB_SYNC_FLUSH);
        $service->store($application, UploadedFile::fake()->createWithContent('cv.pdf', $wrap($syncFlushed)), 'cv');
        $this->assertDatabaseCount('recruitment_applicant_files', 1);

        config(['recruitment-uploads.pdf_max_inflated_bytes' => 64 * 1024]);
        $this->expectException(UnsafeRecruitmentFileException::class);
        $service->store(
            $application,
            UploadedFile::fake()->createWithContent('bomb.pdf', $wrap(gzcompress(str_repeat("\0", 1024 * 1024)))),
            'portfolio',
        );
    }

    public function test_every_download_attempt_is_audited_with_its_outcome(): void
    {
        [$application, $hr] = $this->makeApplication();
        $service = app(RecruitmentApplicantFileService::class);
        $file = $service->store($application, $this->pdf('Samer CV.pdf'), 'cv');

        $this->actingAs($hr)->get(route('recruitment.applicant-files.download', 999999))->assertNotFound();
        $this->actingAs($hr)->get(route('recruitment.applicant-files.download', $file))->assertForbidden();

        $service->markClean($file);

        $this->actingAs($hr)->get(route('recruitment.applicant-files.download', $file))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertDownload('Samer CV.pdf');

        $otherHr = $this->makeUser($this->makeCity('ALP', '001'), 'HR-ALP', ['recruitment-applicants.download-cv']);
        $this->actingAs($otherHr)->get(route('recruitment.applicant-files.download', $file))->assertForbidden();

        $employee = $this->makeUser($this->makeCity('LAT', '004'), 'employee');
        $this->actingAs($employee)->get(route('recruitment.applicant-files.download', $file))->assertForbidden();

        $events = Activity::query()
            ->where('log_name', RecruitmentApplicantFileController::AUDIT_LOG)
            ->orderBy('id')
            ->get();
        $this->assertSame(
            [
                [999999, $hr->id, 'not_found', 'no_record'],
                [$file->id, $hr->id, 'denied', 'scan_pending'],
                [$file->id, $hr->id, 'success', null],
                [$file->id, $otherHr->id, 'denied', 'out_of_scope'],
                [$file->id, $employee->id, 'denied', 'missing_permission'],
            ],
            $events->map(fn (Activity $event): array => [
                (int) $event->getExtraProperty('file_id'),
                (int) $event->causer_id,
                $event->getExtraProperty('outcome'),
                $event->getExtraProperty('reason'),
            ])->all(),
        );
        $this->assertNull($events[0]->subject_id);
        $this->assertSame($file->id, (int) $events[2]->subject_id);
        $this->assertSame('HR-HMS', $events[2]->getExtraProperty('role'));
        $this->assertNotNull($events[2]->getExtraProperty('ip_address'));
    }

    private function pdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<<>>\n%%EOF\n",
        );
    }

    /** @return array{RecruitmentApplication, User} */
    private function makeApplication(): array
    {
        $city = $this->makeCity('HMS', '002');
        $program = Program::query()->forceCreate([
            'name' => 'DISC', 'code' => 'D', 'slug' => 'disc-'.uniqid(), 'is_active' => true,
        ]);
        $department = Department::query()->forceCreate([
            'name' => 'Information Technology', 'code' => 'IT-'.uniqid(),
        ]);
        $jobLevel = JobLevel::query()->forceCreate([
            'name_en' => 'Specialist', 'name_ar' => 'اختصاصي', 'grade' => 6,
        ]);
        $position = Position::query()->forceCreate([
            'program_id' => $program->id,
            'city_id' => $city->id,
            'job_level_id' => $jobLevel->id,
            'department_id' => $department->id,
            'title_en' => 'IT Specialist',
            'title_ar' => 'اختصاصي تقنية معلومات',
            'max_emp' => 2,
            'exist_emp' => 0,
            'is_active' => true,
        ]);
        $requester = $this->makeUser($city, 'employee');
        $request = RecruitmentRequest::query()->forceCreate([
            'request_number' => 'REQ-'.strtoupper(uniqid()),
            'requester_user_id' => $requester->id,
            'request_type' => RecruitmentRequestType::CreatePosition,
            'mode' => RecruitmentRequestMode::Normal,
            'status' => RecruitmentRequestStatus::Applied,
            'scope_city_id' => $city->id,
            'target_position_id' => $position->id,
            'submitted_at' => now(),
            'applied_at' => now(),
        ]);
        RecruitmentRequestVersion::query()->forceCreate([
            'request_id' => $request->id,
            'version_no' => 1,
            'snapshot_json' => ['approved' => true],
            'created_by_user_id' => $requester->id,
            'submitted_at' => now(),
        ]);
        $vacancy = Vacancy::query()->forceCreate([
            'position_id' => $position->id,
            'created_from_request_id' => $request->id,
            'status' => 'open',
            'needed_count' => 1,
            'filled_count' => 0,
            'open_at' => now(),
        ]);
        $hr = $this->makeUser($city, 'HR-HMS', ['recruitment-applicants.download-cv']);
        $campaign = HiringCampaign::query()->forceCreate([
            'vacancy_id' => $vacancy->id,
            'status' => HiringCampaignStatus::Open,
            'link_mode' => RecruitmentCampaignLinkMode::PublicOpen,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(14),
            'created_by_user_id' => $hr->id,
        ]);
        $jobPost = JobPost::query()->forceCreate([
            'campaign_id' => $campaign->id,
            'status' => 'published',
            'created_by_user_id' => $hr->id,
        ]);
        $jobVersion = $jobPost->versions()->create([
            'version_no' => 1,
            'title_en' => 'IT Specialist',
            'title_ar' => 'اختصاصي تقنية معلومات',
            'public_payload_json' => [],
            'status' => 'published',
            'published_at' => now(),
        ]);
        $jobPost->forceFill(['current_version_id' => $jobVersion->id])->save();
        $form = ApplicationForm::query()->forceCreate([
            'campaign_id' => $campaign->id,
            'status' => 'published',
            'created_by_user_id' => $hr->id,
        ]);
        $formVersion = $form->versions()->create([
            'version_no' => 1,
            'fields_json' => [['key' => 'cv']],
            'privacy_notice_version' => 'v1',
            'status' => 'published',
            'published_at' => now(),
        ]);
        $form->forceFill(['current_version_id' => $formVersion->id])->save();
        $publicLink = PublicRecruitmentLink::query()->forceCreate([
            'campaign_id' => $campaign->id,
            'job_post_version_id' => $jobVersion->id,
            'form_version_id' => $formVersion->id,
            'token_hash' => hash('sha256', uniqid('token', true)),
            'status' => PublicRecruitmentLink::STATUS_ACTIVE,
            'expires_at' => now()->addWeek(),
            'created_by_user_id' => $hr->id,
        ]);
        $applicant = RecruitmentApplicant::query()->forceCreate([
            'full_name' => 'Applicant One',
            'email_normalized' => 'applicant@example.com',
            'city_id' => $city->id,
            'source' => 'public_link',
        ]);
        $application = RecruitmentApplication::query()->forceCreate([
            'applicant_id' => $applicant->id,
            'campaign_id' => $campaign->id,
            'vacancy_id' => $vacancy->id,
            'form_version_id' => $formVersion->id,
            'public_link_id' => $publicLink->id,
            'email_normalized' => $applicant->email_normalized,
            'status' => 'submitted',
            'privacy_notice_version' => 'v1',
            'privacy_accepted_at' => now(),
            'submitted_at' => now(),
        ]);

        return [$application, $hr];
    }
}
