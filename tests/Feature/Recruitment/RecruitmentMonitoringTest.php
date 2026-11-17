<?php

namespace Tests\Feature\Recruitment;

use App\Enums\HiringCampaignStatus;
use App\Enums\RecruitmentCampaignLinkMode;
use App\Enums\RecruitmentRequestMode;
use App\Enums\RecruitmentRequestStatus;
use App\Enums\RecruitmentRequestType;
use App\Jobs\ScanRecruitmentApplicantFile;
use App\Models\ApplicationForm;
use App\Models\City;
use App\Models\Department;
use App\Models\HiringCampaign;
use App\Models\JobLevel;
use App\Models\JobPost;
use App\Models\Position;
use App\Models\Program;
use App\Models\RecruitmentRequest;
use App\Models\RecruitmentRequestVersion;
use App\Models\Vacancy;
use App\Services\Monitoring\SecurityMonitor;
use App\Services\PublicRecruitmentLinkService;
use App\Services\Recruitment\Scanning\RecruitmentFileScanner;
use App\Services\RecruitmentApplicantFileService;
use App\Services\RecruitmentFileScanService;
use App\Support\Recruitment\ScanResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use RuntimeException;
use Tests\Concerns\BuildsRecruitmentApplications;
use Tests\Concerns\CapturesSecurityEvents;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * Phase 2 Task 11: monitoring points for failures and abuse.
 *
 * Each test drives the real entry point — the careers form, the download
 * route, the scanner, the exception handler — and reads back what reached the
 * security channel. Every event must be free of personal data, and repeats
 * must be thinned so the log cannot be used to fill a disk.
 */
class RecruitmentMonitoringTest extends TestCase
{
    use BuildsRecruitmentApplications, CapturesSecurityEvents, CreatesHrFixtures, RefreshDatabase;

    private City $homs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);
        $this->captureSecurityEvents();

        Storage::fake('recruitment-quarantine');
        Storage::fake('recruitment-files');
        config([
            'recruitment-uploads.quarantine_disk' => 'recruitment-quarantine',
            'recruitment-uploads.clean_disk' => 'recruitment-files',
        ]);

        $this->homs = $this->makeCity('HMS', '002');
    }

    // ------------------------------------------------------------------
    // Public endpoint
    // ------------------------------------------------------------------

    public function test_a_bot_tripping_the_honeypot_is_recorded_every_time_without_personal_data(): void
    {
        $token = $this->publicLink();

        foreach ([1, 2] as $attempt) {
            $this->post(route('careers.submit', $token), [...$this->payload("bot{$attempt}@spam.test"), 'website' => 'https://spam.test'])
                ->assertUnprocessable();
        }

        $events = $this->securityEvents(SecurityMonitor::SUBMISSION_FAILED);
        $this->assertCount(2, $events);
        $this->assertSame('invalid_request', $events[0]['context']['reason']);
        $this->assertSame('warning', $events[0]['level']);
        $this->assertSame('careers.submit', $events[0]['context']['route']);
        $this->assertSame(12, strlen($events[0]['context']['link']));

        $this->assertNoPersonalData(['bot1@spam.test', 'bot2@spam.test', 'Applicant Name', $token]);
    }

    public function test_an_applicants_own_mistake_is_recorded_but_thinned(): void
    {
        $token = $this->publicLink();

        foreach (range(1, 3) as $attempt) {
            $this->post(route('careers.submit', $token), $this->payload('not-an-email'))->assertUnprocessable();
        }

        $events = $this->securityEvents(SecurityMonitor::SUBMISSION_FAILED);
        $this->assertCount(1, $events);
        $this->assertSame('info', $events[0]['level']);
        $this->assertSame(1, $events[0]['context']['occurrences']);
    }

    public function test_repeated_failed_submissions_from_one_address_are_flagged_as_abuse(): void
    {
        config(['recruitment-privacy.monitoring.submission_failures' => ['threshold' => 3, 'window' => 900]]);
        $token = $this->publicLink();

        foreach (range(1, 3) as $attempt) {
            $this->post(route('careers.submit', $token), [...$this->payload("x{$attempt}@spam.test"), 'status' => 'hired']);
        }

        $abuse = $this->securityEvents(SecurityMonitor::PUBLIC_ABUSE);
        $this->assertCount(1, $abuse);
        $this->assertSame('critical', $abuse[0]['level']);
        $this->assertSame('repeated_failed_submissions', $abuse[0]['context']['signal']);
        $this->assertSame(3, $abuse[0]['context']['count']);
        $this->assertSame('127.0.0.1', $abuse[0]['context']['ip']);
    }

    public function test_guessing_link_tokens_is_flagged_without_logging_the_tokens(): void
    {
        config(['recruitment-privacy.monitoring.token_probes' => ['threshold' => 3, 'window' => 600]]);

        foreach (['guess-one', 'guess-two', 'guess-three'] as $guess) {
            $this->get(route('careers.show', $guess))->assertNotFound();
        }

        $abuse = $this->securityEvents(SecurityMonitor::PUBLIC_ABUSE);
        $this->assertCount(1, $abuse);
        $this->assertSame('unknown_link_tokens', $abuse[0]['context']['signal']);
        $this->assertNoPersonalData(['guess-one', 'guess-two', 'guess-three']);
    }

    public function test_a_public_rate_limit_hit_is_recorded_and_counts_as_abuse(): void
    {
        config(['recruitment-privacy.monitoring.rate_limit_hits' => ['threshold' => 1, 'window' => 600]]);
        $token = $this->publicLink();

        // careers-submit allows 5 a minute for one address and link.
        foreach (range(1, 5) as $attempt) {
            $this->post(route('careers.submit', $token), [...$this->payload("r{$attempt}@spam.test"), 'website' => 'x']);
        }
        $this->post(route('careers.submit', $token), $this->payload('r6@spam.test'))->assertStatus(429);

        $hit = $this->securityEvents(SecurityMonitor::RATE_LIMIT_HIT);
        $this->assertCount(1, $hit);
        $this->assertSame('careers.submit', $hit[0]['context']['limited_route']);
        $this->assertSame('rate_limit_exceeded', $this->securityEvents(SecurityMonitor::PUBLIC_ABUSE)[0]['context']['signal']);
        $this->assertNoPersonalData([$token, 'r6@spam.test']);
    }

    // ------------------------------------------------------------------
    // Uploads and scanning
    // ------------------------------------------------------------------

    public function test_a_pdf_carrying_a_script_is_a_warning_and_an_oversized_one_is_information(): void
    {
        $token = $this->publicLink();

        // A real PDF by its bytes, so the form's mimes rule lets it through to
        // the file service, which finds the script.
        $scripted = UploadedFile::fake()->createWithContent(
            'cv.pdf',
            "%PDF-1.4\n1 0 obj\n<< /OpenAction << /S /JavaScript /JS (app.alert(1)) >> >>\nendobj\n%%EOF\n",
        );
        $this->post(route('careers.submit', $token), $this->payload('one@example.com', $scripted))->assertUnprocessable();

        config(['recruitment-uploads.max_bytes' => 16]);
        $this->post(route('careers.submit', $token), $this->payload('two@example.com'))->assertUnprocessable();

        $events = $this->securityEvents(SecurityMonitor::UPLOAD_REJECTED);
        $this->assertSame(
            [['executable', 'warning'], ['too_large', 'info']],
            array_map(fn (array $event): array => [$event['context']['reason'], $event['level']], $events),
        );
        // The refused submission is thinned like any applicant mistake; which
        // one was an attack is what upload.rejected above is for.
        $this->assertSame(
            ['file_rejected'],
            array_map(fn (array $event): string => $event['context']['reason'], $this->securityEvents(SecurityMonitor::SUBMISSION_FAILED)),
        );
        $this->assertNoPersonalData(['one@example.com', 'two@example.com', $token]);
    }

    public function test_an_infected_file_is_critical_and_a_failed_scan_is_an_error(): void
    {
        Bus::fake([ScanRecruitmentApplicantFile::class]);
        $files = app(RecruitmentApplicantFileService::class);
        $application = $this->applyTo($this->makeCampaignIn($this->homs), $this->makeApplicant());
        $infected = $files->store($application, $this->pdf(), 'cv');
        $unscannable = $files->store($application, $this->pdf('portfolio.pdf'), 'portfolio');

        $this->scannerSays(ScanResult::infected('clamav', 'Eicar-Signature'));
        app(RecruitmentFileScanService::class)->scan($infected->id);

        $this->scannerSays(ScanResult::error('clamav', 'too large', permanent: true));
        app(RecruitmentFileScanService::class)->scan($unscannable->id);

        $infectedEvent = $this->securityEvents(SecurityMonitor::SCAN_INFECTED)[0];
        $this->assertSame('critical', $infectedEvent['level']);
        $this->assertSame('Eicar-Signature', $infectedEvent['context']['signature']);
        $this->assertSame($infected->id, $infectedEvent['context']['file_id']);

        $failedEvent = $this->securityEvents(SecurityMonitor::SCAN_FAILED)[0];
        $this->assertSame('error', $failedEvent['level']);
        $this->assertSame($unscannable->id, $failedEvent['context']['file_id']);
    }

    public function test_a_scan_job_that_gives_up_is_an_error(): void
    {
        (new ScanRecruitmentApplicantFile(42))->failed(new RuntimeException('clamd is not reachable'));

        $event = $this->securityEvents(SecurityMonitor::SCAN_GAVE_UP)[0];
        $this->assertSame('error', $event['level']);
        $this->assertSame(42, $event['context']['file_id']);
        $this->assertSame('RuntimeException', $event['context']['exception']);
        // A job has no client; nothing from a request is attached.
        $this->assertArrayNotHasKey('ip', $event['context']);
    }

    // ------------------------------------------------------------------
    // Inside the application
    // ------------------------------------------------------------------

    public function test_many_cv_downloads_in_a_short_while_are_flagged(): void
    {
        config(['recruitment-privacy.monitoring.downloads' => ['threshold' => 3, 'window' => 600]]);
        Bus::fake([ScanRecruitmentApplicantFile::class]);
        $files = app(RecruitmentApplicantFileService::class);
        $application = $this->applyTo($this->makeCampaignIn($this->homs), $this->makeApplicant());
        $cv = $files->markClean($files->store($application, $this->pdf(), 'cv'));
        $hr = $this->makeUser($this->homs, 'HR-HMS', ['recruitment-applicants.download-cv']);

        foreach (range(1, 2) as $download) {
            $this->actingAs($hr)->get(route('recruitment.applicant-files.download', $cv))->assertOk();
        }
        $this->assertSame([], $this->securityEvents(SecurityMonitor::DOWNLOAD_REPEATED));

        $this->actingAs($hr)->get(route('recruitment.applicant-files.download', $cv))->assertOk();

        $event = $this->securityEvents(SecurityMonitor::DOWNLOAD_REPEATED);
        $this->assertCount(1, $event);
        $this->assertSame($hr->id, $event[0]['context']['user_id']);
        $this->assertSame(3, $event[0]['context']['count']);
    }

    public function test_a_refused_page_is_recorded_as_an_unauthorized_access_attempt(): void
    {
        $employee = $this->makeUser($this->homs, 'employee');

        $this->actingAs($employee)->get(route('recruitment.applicants.index'))->assertForbidden();

        $event = $this->securityEvents(SecurityMonitor::ACCESS_DENIED)[0];
        $this->assertSame('warning', $event['level']);
        $this->assertSame($employee->id, $event['context']['user_id']);
        $this->assertSame('recruitment.applicants.index', $event['context']['denied_route']);
    }

    public function test_a_denied_policy_check_is_recorded_too(): void
    {
        Bus::fake([ScanRecruitmentApplicantFile::class]);
        $files = app(RecruitmentApplicantFileService::class);
        $cv = $files->markClean($files->store($this->applyTo($this->makeCampaignIn($this->homs), $this->makeApplicant()), $this->pdf(), 'cv'));
        $outsider = $this->makeUser($this->makeCity('ALP', '001'), 'HR-ALP', ['recruitment-applicants.download-cv']);

        $this->actingAs($outsider)->get(route('recruitment.applicant-files.download', $cv))->assertForbidden();

        $this->assertSame('recruitment.applicant-files.download', $this->securityEvents(SecurityMonitor::ACCESS_DENIED)[0]['context']['denied_route']);
    }

    // ------------------------------------------------------------------
    // The monitor itself
    // ------------------------------------------------------------------

    public function test_a_repeated_event_is_written_at_its_1st_10th_and_100th_occurrence(): void
    {
        $monitor = app(SecurityMonitor::class);

        foreach (range(1, 100) as $occurrence) {
            $monitor->record(SecurityMonitor::ACCESS_DENIED, ['n' => $occurrence], 'same-key');
        }

        $this->assertSame([1, 10, 100], array_map(
            fn (array $event): int => $event['context']['occurrences'],
            $this->securityEvents(SecurityMonitor::ACCESS_DENIED),
        ));
    }

    public function test_missing_monitoring_config_falls_back_instead_of_breaking_the_form(): void
    {
        $token = $this->publicLink();
        // Every limit gone; only the capture channel is kept so the test can read the events.
        config(['recruitment-privacy.monitoring' => ['channel' => 'security_capture']]);

        $this->post(route('careers.submit', $token), [...$this->payload('bot@spam.test'), 'website' => 'x'])
            ->assertUnprocessable();
        $this->get(route('careers.show', 'unknown-token'))->assertNotFound();

        $this->assertSame('invalid_request', $this->securityEvents(SecurityMonitor::SUBMISSION_FAILED)[0]['context']['reason']);
    }

    public function test_monitoring_that_fails_never_breaks_the_request_it_watches(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'monitor');
        $previous = ini_set('error_log', $log);
        Cache::shouldReceive('add')->andThrow(new RuntimeException('cache is down'));

        try {
            app(SecurityMonitor::class)->record(SecurityMonitor::ACCESS_DENIED, [], 'key');
            $this->assertFalse(app(SecurityMonitor::class)->burst(SecurityMonitor::PUBLIC_ABUSE, 'c', 'k', ['threshold' => 1, 'window' => 1]));
        } finally {
            ini_set('error_log', (string) $previous);
        }

        $this->assertStringContainsString('cache is down', (string) file_get_contents($log));
        @unlink($log);
    }

    // ------------------------------------------------------------------

    /** @param array<int, string> $needles */
    private function assertNoPersonalData(array $needles): void
    {
        $everything = json_encode($this->securityEvents());

        foreach ($needles as $needle) {
            $this->assertStringNotContainsString($needle, $everything, "A monitoring event contains '{$needle}'.");
        }
    }

    private function pdf(string $name = 'cv.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<<>>\n%%EOF\n");
    }

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

    /** @return array<string, mixed> */
    private function payload(string $email, ?UploadedFile $cv = null): array
    {
        return [
            'locale' => 'en',
            'submission_key' => bin2hex(random_bytes(32)),
            'answers' => [
                'full_name' => 'Applicant Name',
                'email' => $email,
                'phone' => '0999123456',
                'cv' => $cv ?? $this->pdf(),
                'privacy_consent' => '1',
            ],
        ];
    }

    /** A published campaign with a public link; returns the link's token. */
    private function publicLink(): string
    {
        $program = Program::query()->forceCreate(['name' => 'DISC', 'code' => 'D', 'slug' => 'disc-'.uniqid(), 'is_active' => true]);
        $department = Department::query()->forceCreate(['name' => 'IT', 'code' => 'IT-'.uniqid()]);
        $jobLevel = JobLevel::query()->forceCreate(['name_en' => 'Specialist', 'name_ar' => 'اختصاصي', 'grade' => 6]);
        $position = Position::query()->forceCreate([
            'program_id' => $program->id, 'city_id' => $this->homs->id, 'job_level_id' => $jobLevel->id,
            'department_id' => $department->id, 'title_en' => 'IT Specialist', 'title_ar' => 'اختصاصي',
            'max_emp' => 2, 'exist_emp' => 0, 'is_active' => true,
        ]);
        $requester = $this->makeUser($this->homs, 'employee');
        $request = RecruitmentRequest::query()->forceCreate([
            'request_number' => 'REQ-'.strtoupper(uniqid()), 'requester_user_id' => $requester->id,
            'request_type' => RecruitmentRequestType::CreatePosition, 'mode' => RecruitmentRequestMode::Normal,
            'status' => RecruitmentRequestStatus::Applied, 'scope_city_id' => $this->homs->id,
            'target_position_id' => $position->id, 'submitted_at' => now(), 'applied_at' => now(),
        ]);
        RecruitmentRequestVersion::query()->forceCreate([
            'request_id' => $request->id, 'version_no' => 1, 'snapshot_json' => ['approved' => true],
            'created_by_user_id' => $requester->id, 'submitted_at' => now(),
        ]);
        $vacancy = Vacancy::query()->forceCreate([
            'position_id' => $position->id, 'created_from_request_id' => $request->id, 'status' => 'open',
            'needed_count' => 1, 'filled_count' => 0, 'open_at' => now(),
        ]);
        $hr = $this->makeUser($this->homs, 'HR-HMS', ['recruitment-campaigns.view', 'recruitment-links.view', 'recruitment-links.manage']);
        $campaign = HiringCampaign::query()->forceCreate([
            'vacancy_id' => $vacancy->id, 'status' => HiringCampaignStatus::Open,
            'link_mode' => RecruitmentCampaignLinkMode::PublicOpen,
            'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(14), 'created_by_user_id' => $hr->id,
        ]);
        $jobPost = JobPost::query()->forceCreate(['campaign_id' => $campaign->id, 'status' => 'published', 'created_by_user_id' => $hr->id]);
        $jobVersion = $jobPost->versions()->create([
            'version_no' => 1, 'title_en' => 'IT Specialist', 'title_ar' => 'اختصاصي', 'body_en' => 'b', 'body_ar' => 'b',
            'public_payload_json' => ['template' => 'hope_center_hiring_v1', 'city' => 'HOMS', 'program' => 'DISC', 'job_title' => 'IT Specialist', 'outputs' => []],
            'status' => 'published', 'published_at' => now(),
        ]);
        $jobPost->forceFill(['current_version_id' => $jobVersion->id])->save();
        $form = ApplicationForm::query()->forceCreate(['campaign_id' => $campaign->id, 'status' => 'published', 'created_by_user_id' => $hr->id]);
        $formVersion = $form->versions()->create([
            'version_no' => 1, 'fields_json' => [['key' => 'full_name'], ['key' => 'email']],
            'privacy_notice_version' => 'v1', 'status' => 'published', 'published_at' => now(),
        ]);
        $form->forceFill(['current_version_id' => $formVersion->id])->save();

        return (string) app(PublicRecruitmentLinkService::class)
            ->generate($campaign, $hr, now()->addDays(10), RecruitmentCampaignLinkMode::PublicOpen)['token'];
    }
}
