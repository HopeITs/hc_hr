<?php

namespace Tests\Feature\Recruitment;

use App\Enums\RecruitmentApplicationStatus as Status;
use App\Jobs\ScanRecruitmentApplicantFile;
use App\Models\City;
use App\Models\RecruitmentApplicant;
use App\Models\RecruitmentApplicantFile;
use App\Models\RecruitmentApplicantNote;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentApplicationAnswer;
use App\Models\RecruitmentCampaignInvite;
use App\Models\RecruitmentCvAnalysis;
use App\Models\RecruitmentPrivacyAudit;
use App\Models\User;
use App\Services\Recruitment\Privacy\RecruitmentAnonymizer;
use App\Services\Recruitment\Privacy\RecruitmentRetentionService;
use App\Services\Recruitment\Privacy\RetentionPolicy;
use App\Services\RecruitmentApplicantFileService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use RuntimeException;
use Tests\Concerns\BuildsRecruitmentApplications;
use Tests\Concerns\CapturesSecurityEvents;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * Phase 2 Task 11: erasure on request, retention, anonymization.
 *
 * What must hold: personal data is stripped and nothing else changes — every
 * row that history points at stays, the aggregate counts are the same, and
 * consent evidence survives; an open application or a hire stops it; a request
 * that cannot run yet is kept and carried out later; automatic time-based
 * anonymization does nothing until it is switched on.
 */
class RecruitmentErasureAndRetentionTest extends TestCase
{
    use BuildsRecruitmentApplications, CapturesSecurityEvents, CreatesHrFixtures, RefreshDatabase;

    private const ERASE = 'recruitment-applicants.request-erasure';

    /** Every table that holds history or reporting, and must keep every row. */
    private const KEPT_TABLES = [
        'recruitment_applicants', 'recruitment_applications', 'recruitment_application_answers',
        'recruitment_applicant_files', 'recruitment_applicant_notes', 'recruitment_application_decisions',
        'recruitment_rejection_notices', 'recruitment_campaign_invites', 'recruitment_application_indicator_snapshots',
        'recruitment_interviews', 'recruitment_interview_reschedules', 'recruitment_interview_notices',
        'recruitment_interview_evaluations', 'recruitment_interview_evaluation_revisions',
        'recruitment_job_offers', 'recruitment_job_offer_versions',
    ];

    private City $aleppo;

    private City $damascus;

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
            'recruitment-privacy.retention.closed_application_days' => 365,
            'recruitment-privacy.retention.no_application_days' => 365,
            'recruitment-privacy.retention.enforce' => false,
        ]);

        $this->aleppo = $this->makeCity('ALP', '001');
        $this->damascus = $this->makeCity('DMS', '003');
    }

    // ------------------------------------------------------------------
    // Anonymization
    // ------------------------------------------------------------------

    public function test_erasure_strips_the_personal_data_and_keeps_every_row_history_points_at(): void
    {
        [$applicant, $application, $hr] = $this->richApplicant();
        $before = $this->rowCounts();

        $this->actingAs($hr)
            ->postJson(route('recruitment.applicants.erasure', $applicant->id), ['channel' => 'email', 'confirm' => true])
            ->assertOk()
            ->assertJson(['status' => 'completed', 'blocker' => null]);

        // Not one row lost; the CV reading (derived from the file) and the file's bytes are the only things removed.
        $this->assertSame($before, $this->rowCounts());
        $this->assertSame(0, RecruitmentCvAnalysis::query()->count());
        $this->assertSame(0, DB::table('media')->count());
        $this->assertSame([], Storage::disk('recruitment-quarantine')->allFiles());

        $applicant->refresh();
        $this->assertSame('[anonymized]', $applicant->full_name);
        $this->assertSame("anonymized-applicant-{$applicant->id}@anonymized.invalid", $applicant->email_normalized);
        $this->assertNull($applicant->phone_normalized);
        $this->assertNull($applicant->phone_match_key);
        $this->assertNull($applicant->identity_key_hash);
        $this->assertNull($applicant->identity_verified_at);
        $this->assertNotNull($applicant->anonymized_at);
        $this->assertSame('erasure_request', $applicant->anonymization_reason);
        // Consent evidence and the governorate outlive the person's data.
        $this->assertSame('v1', $applicant->privacy_notice_version);
        $this->assertNotNull($applicant->privacy_accepted_at);
        $this->assertSame($this->aleppo->id, (int) $applicant->city_id);

        $application->refresh();
        $this->assertSame(Status::Rejected, $application->status);
        $this->assertSame("anonymized-application-{$application->id}@anonymized.invalid", $application->email_normalized);
        $this->assertNull($application->ip_address);
        $this->assertNull($application->user_agent);
        $this->assertNull($application->submission_key_hash);
        $this->assertSame('v1', $application->privacy_notice_version);
        $this->assertNotNull($application->privacy_accepted_at);
        $this->assertTrue($application->consent_payload_json['privacy_consent']);

        $this->assertSame([['redacted' => true], ['redacted' => true]], RecruitmentApplicationAnswer::query()->orderBy('id')->get()->pluck('answer_json')->all());
        // Both notes, the soft-deleted one included.
        $this->assertSame(['[redacted]', '[redacted]'], RecruitmentApplicantNote::withTrashed()->orderBy('id')->pluck('body')->all());
        $this->assertNull(DB::table('recruitment_applicant_notes')->whereNotNull('deleted_at')->value('deletion_reason'));

        $decision = DB::table('recruitment_application_decisions')->sole();
        $this->assertNull($decision->reason_text);
        $this->assertSame('not_qualified', $decision->reason_code);

        $this->assertSame('anonymized-notice@anonymized.invalid', DB::table('recruitment_rejection_notices')->value('recipient_email'));
        $this->assertNull(DB::table('recruitment_rejection_notices')->value('failure_reason'));
        $this->assertSame('anonymized-notice@anonymized.invalid', DB::table('recruitment_interview_notices')->value('recipient_email'));

        $invite = RecruitmentCampaignInvite::query()->sole();
        $this->assertSame('[anonymized]', $invite->full_name);
        $this->assertSame("anonymized-invite-{$invite->id}@anonymized.invalid", $invite->email_normalized);
        $this->assertSame(RecruitmentCampaignInvite::STATUS_USED, $invite->status);

        $file = RecruitmentApplicantFile::query()->sole();
        $this->assertNotNull($file->purged_at);
        $this->assertSame('cv', $file->file_type);

        $evaluation = DB::table('recruitment_interview_evaluations')->sole();
        $this->assertNull($evaluation->pros);
        $this->assertNull($evaluation->cons);
        $this->assertNull($evaluation->notes);
        $this->assertSame('hire', $evaluation->recommendation);
        $revision = DB::table('recruitment_interview_evaluation_revisions')->sole();
        $this->assertNull($revision->notes);
        $this->assertNull($revision->reason);
        $this->assertNull(DB::table('recruitment_interviews')->value('outcome_note'));
        $this->assertSame('[redacted]', DB::table('recruitment_interview_reschedules')->value('reason'));

        $this->assertNull(DB::table('recruitment_job_offers')->value('response_note'));
        $this->assertNull(DB::table('recruitment_job_offer_versions')->value('revision_reason'));
        $this->assertSame([], json_decode((string) DB::table('recruitment_application_indicator_snapshots')->value('indicators_json'), true));
        $this->assertSame(3, (int) DB::table('recruitment_application_indicator_snapshots')->value('met_count'));

        // Nothing the person typed is left anywhere in the recruitment tables.
        foreach (self::KEPT_TABLES as $table) {
            foreach (DB::table($table)->get() as $row) {
                foreach (['Rana', 'rana@example', '963955000111', '203.0.113.9', 'home address', 'budgets'] as $needle) {
                    $this->assertStringNotContainsString($needle, json_encode($row), "{$table} still holds '{$needle}'.");
                }
            }
        }
    }

    public function test_aggregate_reporting_is_the_same_after_anonymization(): void
    {
        [$applicant, $application, $hr] = $this->richApplicant();
        $campaign = $application->campaign;
        $this->applyTo($campaign, $this->makeApplicant('Second Person', $this->aleppo), ['status' => Status::Shortlisted]);

        $report = fn (): array => RecruitmentApplication::query()
            ->where('campaign_id', $campaign->id)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->orderBy('status')
            ->pluck('total', 'status')
            ->all();
        $byGovernorate = fn (): array => RecruitmentApplicant::query()->selectRaw('city_id, count(*) as total')->groupBy('city_id')->pluck('total', 'city_id')->all();
        $before = [$report(), $byGovernorate(), DB::table('recruitment_application_decisions')->count()];

        app(RecruitmentAnonymizer::class)->anonymize($applicant, RecruitmentAnonymizer::REASON_ERASURE);

        $this->assertSame($before, [$report(), $byGovernorate(), DB::table('recruitment_application_decisions')->count()]);
        $this->assertNotNull($applicant->fresh()->anonymized_at);
    }

    public function test_the_erasure_is_audited_as_a_request_and_as_the_anonymization_it_caused(): void
    {
        [$applicant, , $hr] = $this->richApplicant();

        $this->actingAs($hr)
            ->postJson(route('recruitment.applicants.erasure', $applicant->id), ['channel' => 'in_person', 'confirm' => true])
            ->assertOk();

        [$request, $anonymization] = RecruitmentPrivacyAudit::query()->orderBy('id')->get()->all();

        $this->assertSame(['erasure_request', 'success', $hr->id, 1, $applicant->id, 'recruitment_applicant'], [
            $request->action, $request->outcome, (int) $request->actor_user_id, $request->record_count, (int) $request->subject_id, $request->subject_type,
        ]);
        $this->assertSame(['channel' => 'in_person'], $request->details_json);
        $this->assertSame('city', $request->scope_type);
        $this->assertSame([$this->aleppo->id], $request->scope_city_ids);

        $this->assertSame(['anonymization', 'success', 'erasure_request'], [$anonymization->action, $anonymization->outcome, $anonymization->reason]);
        $this->assertSame(['applications' => 1, 'files_purged' => 1], $anonymization->details_json);
    }

    public function test_repeating_a_request_on_an_anonymized_applicant_changes_nothing(): void
    {
        [$applicant, , $hr] = $this->richApplicant();
        $this->actingAs($hr)->postJson(route('recruitment.applicants.erasure', $applicant->id), ['channel' => 'email', 'confirm' => true])->assertOk();
        $anonymizedAt = $applicant->fresh()->anonymized_at;

        $this->actingAs($hr)
            ->postJson(route('recruitment.applicants.erasure', $applicant->id), ['channel' => 'email', 'confirm' => true])
            ->assertOk()
            ->assertJson(['status' => 'completed']);

        $this->assertEquals($anonymizedAt, $applicant->fresh()->anonymized_at);
        $this->assertSame(1, RecruitmentPrivacyAudit::query()->where('action', 'anonymization')->count());
        $this->assertSame('already_anonymized', RecruitmentPrivacyAudit::query()->latest('id')->first()->reason);
    }

    public function test_a_retention_policy_can_be_swapped_and_its_blocker_stops_the_anonymizer(): void
    {
        $this->app->bind(RetentionPolicy::class, fn () => new class implements RetentionPolicy
        {
            public function dueAt(RecruitmentApplicant $applicant): ?CarbonImmutable
            {
                return null;
            }

            public function blocker(RecruitmentApplicant $applicant): ?string
            {
                return 'legal_hold';
            }
        });
        [$applicant] = $this->richApplicant();

        $this->assertSame('legal_hold', app(RecruitmentAnonymizer::class)->anonymize($applicant, RecruitmentAnonymizer::REASON_ERASURE));
        $this->assertNull($applicant->fresh()->anonymized_at);
        $this->assertSame('Rana Haddad', $applicant->fresh()->full_name);
    }

    // ------------------------------------------------------------------
    // What stops it
    // ------------------------------------------------------------------

    public function test_an_open_application_holds_the_request_until_it_is_closed(): void
    {
        $hr = $this->hr($this->aleppo);
        $applicant = $this->makeApplicant('Open Case', $this->aleppo);
        $application = $this->applyTo($this->makeCampaignIn($this->aleppo), $applicant);

        $this->actingAs($hr)
            ->postJson(route('recruitment.applicants.erasure', $applicant->id), ['channel' => 'email', 'confirm' => true])
            ->assertStatus(202)
            ->assertJson(['status' => 'pending', 'blocker' => 'active_application']);

        $applicant->refresh();
        $this->assertNotNull($applicant->erasure_requested_at);
        $this->assertNull($applicant->anonymized_at);
        $this->assertSame('Open Case', $applicant->full_name);
        $this->assertSame(['blocked', 'active_application'], [
            RecruitmentPrivacyAudit::query()->sole()->outcome,
            RecruitmentPrivacyAudit::query()->sole()->reason,
        ]);

        // Still open: the sweep leaves it alone, and says so — and does not
        // push back the retention date the request set.
        $requestedAt = $applicant->retention_until;
        $stats = app(RecruitmentRetentionService::class)->sweep();
        $this->assertSame(1, $stats['blocked']);
        $this->assertNull($applicant->fresh()->anonymized_at);
        $this->assertEquals($requestedAt, $applicant->fresh()->retention_until);

        // HR closes the application — retention enforcement is off, but a request is not a retention period.
        RecruitmentApplication::applyDecision($application, Status::Withdrawn);
        $this->artisan('recruitment:retention-sweep')->assertSuccessful();

        $applicant->refresh();
        $this->assertNotNull($applicant->anonymized_at);
        $this->assertSame('erasure_request', $applicant->anonymization_reason);

        // Carried out by the sweep, so nobody's hand is on it — and no client address.
        $audit = RecruitmentPrivacyAudit::query()->where('action', 'anonymization')->sole();
        $this->assertNull($audit->actor_user_id);
        $this->assertSame('system', $audit->scope_type);
        $this->assertNull($audit->ip_address);
    }

    public function test_a_hired_applicant_is_never_anonymized(): void
    {
        $hr = $this->hr($this->aleppo);
        $applicant = $this->makeApplicant('Now Staff', $this->aleppo);
        $this->applyTo($this->makeCampaignIn($this->aleppo), $applicant, ['status' => Status::Hired]);

        $this->actingAs($hr)
            ->postJson(route('recruitment.applicants.erasure', $applicant->id), ['channel' => 'email', 'confirm' => true])
            ->assertStatus(202)
            ->assertJson(['blocker' => 'hired']);

        $this->artisan('recruitment:retention-sweep')->assertSuccessful();
        $this->assertNull($applicant->fresh()->anonymized_at);
        $this->assertSame('Now Staff', $applicant->fresh()->full_name);
    }

    // ------------------------------------------------------------------
    // Who may ask
    // ------------------------------------------------------------------

    public function test_erasure_is_refused_without_its_permission_and_never_confirms_which_ids_exist(): void
    {
        [$applicant] = $this->richApplicant();
        $viewer = $this->makeUser($this->aleppo, 'HR-ALP', ['recruitment-applicants.view']);

        foreach ([$applicant->id, 987654] as $id) {
            $this->actingAs($viewer)
                ->postJson(route('recruitment.applicants.erasure', $id), ['channel' => 'email', 'confirm' => true])
                ->assertForbidden();
        }

        $this->assertNull($applicant->fresh()->anonymized_at);
        $this->assertSame(
            [['refused', 'missing_permission', $applicant->id], ['refused', 'missing_permission', 987654]],
            RecruitmentPrivacyAudit::query()->orderBy('id')->get()->map(fn ($row): array => [$row->outcome, $row->reason, (int) $row->subject_id])->all(),
        );
    }

    public function test_an_administrator_role_is_not_enough_for_erasure(): void
    {
        [$applicant] = $this->richApplicant();
        $admin = $this->makeUser($this->aleppo, 'HR-SYR');

        $this->actingAs($admin)
            ->postJson(route('recruitment.applicants.erasure', $applicant->id), ['channel' => 'email', 'confirm' => true])
            ->assertForbidden();

        $admin->givePermissionTo(self::ERASE);
        $this->actingAs($admin->fresh())
            ->postJson(route('recruitment.applicants.erasure', $applicant->id), ['channel' => 'email', 'confirm' => true])
            ->assertOk();
    }

    public function test_a_governorate_cannot_erase_what_another_governorate_still_holds(): void
    {
        $hr = $this->hr($this->aleppo);
        $applicant = $this->makeApplicant('Shared Person', $this->aleppo);
        $this->applyTo($this->makeCampaignIn($this->aleppo), $applicant, ['status' => Status::Rejected]);
        $this->applyTo($this->makeCampaignIn($this->damascus), $applicant, ['status' => Status::Rejected]);

        // Visible through the Aleppo application...
        $this->assertTrue($hr->can('view', $applicant));

        // ...but erasing wipes the Damascus application too.
        $this->actingAs($hr)
            ->postJson(route('recruitment.applicants.erasure', $applicant->id), ['channel' => 'email', 'confirm' => true])
            ->assertForbidden();
        $this->assertSame('out_of_scope', RecruitmentPrivacyAudit::query()->sole()->reason);
        $this->assertNull($applicant->fresh()->anonymized_at);

        $hq = $this->makeUser($this->damascus, 'HR-SYR', ['recruitment-applicants.view', self::ERASE]);
        $this->actingAs($hq)
            ->postJson(route('recruitment.applicants.erasure', $applicant->id), ['channel' => 'email', 'confirm' => true])
            ->assertOk();
    }

    public function test_an_unknown_applicant_is_a_404_only_for_someone_who_may_erase(): void
    {
        $hr = $this->hr($this->aleppo);

        $this->actingAs($hr)
            ->postJson(route('recruitment.applicants.erasure', 987654), ['channel' => 'email', 'confirm' => true])
            ->assertNotFound();

        $this->assertSame('not_found', RecruitmentPrivacyAudit::query()->sole()->reason);
    }

    public function test_the_request_needs_a_known_channel_and_an_explicit_confirmation(): void
    {
        [$applicant, , $hr] = $this->richApplicant();

        $this->actingAs($hr)
            ->postJson(route('recruitment.applicants.erasure', $applicant->id), ['channel' => 'carrier-pigeon'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['channel', 'confirm']);

        $this->assertNull($applicant->fresh()->anonymized_at);
        $this->assertNull($applicant->fresh()->erasure_requested_at);
    }

    // ------------------------------------------------------------------
    // Retention
    // ------------------------------------------------------------------

    public function test_the_sweep_stamps_retention_from_the_last_closing_and_clears_it_for_a_live_application(): void
    {
        $hr = $this->hr($this->aleppo);
        $closed = $this->makeApplicant('Closed Case', $this->aleppo);
        $application = $this->applyTo($this->makeCampaignIn($this->aleppo), $closed, ['status' => Status::Rejected]);
        $decidedAt = CarbonImmutable::parse('2026-03-01 09:00:00');
        $this->decision($application, $hr, $decidedAt);

        $live = $this->makeApplicant('Live Case', $this->aleppo);
        $this->applyTo($this->makeCampaignIn($this->aleppo), $live);
        $live->forceFill(['retention_until' => now()->addDay()])->save();

        $idle = $this->makeApplicant('Idle Case', $this->aleppo);
        $idle->forceFill(['last_seen_at' => CarbonImmutable::parse('2026-01-01 00:00:00')])->save();

        $stats = app(RecruitmentRetentionService::class)->sweep();

        $this->assertEquals($decidedAt->addDays(365), $closed->fresh()->retention_until);
        $this->assertNull($live->fresh()->retention_until);
        $this->assertEquals(CarbonImmutable::parse('2026-01-01')->addDays(365), $idle->fresh()->retention_until);
        $this->assertSame(2, $stats['stamped']);
        $this->assertSame(1, $stats['cleared']);

        // Nothing changed, so a second run stamps and clears nothing.
        $again = app(RecruitmentRetentionService::class)->sweep();
        $this->assertSame([0, 0], [$again['stamped'], $again['cleared']]);
    }

    public function test_an_applicant_past_retention_is_left_alone_until_enforcement_is_switched_on(): void
    {
        [$applicant] = $this->dueApplicant();

        $stats = app(RecruitmentRetentionService::class)->sweep();

        $this->assertSame(1, $stats['due_held']);
        $this->assertSame(0, $stats['anonymized']);
        $this->assertNull($applicant->fresh()->anonymized_at);
        $this->assertSame('Overdue Person', $applicant->fresh()->full_name);

        $this->artisan('recruitment:retention-sweep')
            ->expectsOutputToContain('RECRUITMENT_RETENTION_ENFORCE is off')
            ->assertSuccessful();
    }

    public function test_with_enforcement_on_an_applicant_past_retention_is_anonymized_by_the_system(): void
    {
        config(['recruitment-privacy.retention.enforce' => true]);
        [$applicant] = $this->dueApplicant();
        $stillInside = $this->makeApplicant('Recent Person', $this->aleppo);
        $recent = $this->applyTo($this->makeCampaignIn($this->aleppo), $stillInside, ['status' => Status::Rejected]);
        $this->decision($recent, $this->hr($this->aleppo), now()->subDays(10));

        $stats = app(RecruitmentRetentionService::class)->sweep();

        $this->assertSame(1, $stats['anonymized']);
        $applicant->refresh();
        $this->assertSame('[anonymized]', $applicant->full_name);
        $this->assertSame('retention_expired', $applicant->anonymization_reason);
        $this->assertSame('Recent Person', $stillInside->fresh()->full_name);

        $audit = RecruitmentPrivacyAudit::query()->sole();
        $this->assertSame(['anonymization', 'retention_expired', null, 'system'], [$audit->action, $audit->reason, $audit->actor_user_id, $audit->scope_type]);
    }

    public function test_a_dry_run_reports_and_changes_nothing(): void
    {
        config(['recruitment-privacy.retention.enforce' => true]);
        [$applicant] = $this->dueApplicant();
        $applicant->forceFill(['retention_until' => null])->save();

        $this->artisan('recruitment:retention-sweep', ['--dry-run' => true])->assertSuccessful();

        $this->assertNull($applicant->fresh()->retention_until);
        $this->assertNull($applicant->fresh()->anonymized_at);
        $this->assertSame(0, RecruitmentPrivacyAudit::query()->count());
    }

    public function test_one_applicant_that_fails_does_not_stop_the_sweep(): void
    {
        config(['recruitment-privacy.retention.enforce' => true]);
        [$first] = $this->dueApplicant('First Overdue');
        [$second] = $this->dueApplicant('Second Overdue');
        $this->app->bind(RetentionPolicy::class, fn () => new class($first->id) implements RetentionPolicy
        {
            public function __construct(private readonly int $poison) {}

            public function dueAt(RecruitmentApplicant $applicant): ?CarbonImmutable
            {
                return CarbonImmutable::now()->subDay();
            }

            public function blocker(RecruitmentApplicant $applicant): ?string
            {
                if ($applicant->getKey() === $this->poison) {
                    throw new RuntimeException('storage exploded');
                }

                return null;
            }
        });

        $stats = app(RecruitmentRetentionService::class)->sweep();

        $this->assertSame(1, $stats['failed']);
        $this->assertSame(1, $stats['anonymized']);
        $this->assertNull($first->fresh()->anonymized_at);
        $this->assertNotNull($second->fresh()->anonymized_at);
        $this->assertSame($first->id, $this->securityEvents('retention.failed')[0]['context']['applicant_id']);
        $this->artisan('recruitment:retention-sweep')->assertFailed();
    }

    // ------------------------------------------------------------------

    /** Erasure is granted to the person, not the role: two HR users of one city must not share it. */
    private function hr(City $city): User
    {
        $user = $this->makeUser($city, 'HR-'.$city->slug, ['recruitment-applicants.view']);
        $user->givePermissionTo(self::ERASE);

        return $user->fresh();
    }

    /** @return array{RecruitmentApplicant} an applicant whose only application closed 400 days ago */
    private function dueApplicant(string $name = 'Overdue Person'): array
    {
        $applicant = $this->makeApplicant($name, $this->aleppo);
        $application = $this->applyTo($this->makeCampaignIn($this->aleppo), $applicant, ['status' => Status::Rejected]);
        $this->decision($application, $this->hr($this->aleppo), CarbonImmutable::now()->subDays(400));

        return [$applicant];
    }

    private function decision(RecruitmentApplication $application, User $decider, CarbonImmutable|\Illuminate\Support\Carbon $at): void
    {
        DB::table('recruitment_application_decisions')->insert([
            'application_id' => $application->id,
            'from_status' => Status::Submitted,
            'to_status' => $application->status,
            'reason_code' => 'not_qualified',
            'reason_text' => null,
            'decided_by_user_id' => $decider->id,
            'decided_at' => $at,
        ]);
    }

    /** @return array<string, int> */
    private function rowCounts(): array
    {
        return collect(self::KEPT_TABLES)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
    }

    /**
     * One applicant with a row in every table that holds something about them.
     *
     * @return array{RecruitmentApplicant, RecruitmentApplication, User}
     */
    private function richApplicant(): array
    {
        Bus::fake([ScanRecruitmentApplicantFile::class]);

        $hr = $this->hr($this->aleppo);
        $campaign = $this->makeCampaignIn($this->aleppo);

        $invite = RecruitmentCampaignInvite::query()->forceCreate([
            'campaign_id' => $campaign->id,
            'full_name' => 'Rana Haddad',
            'email_normalized' => 'rana@example.com',
            'status' => RecruitmentCampaignInvite::STATUS_USED,
            'used_at' => now(),
            'created_by_user_id' => $hr->id,
        ]);
        $applicant = RecruitmentApplicant::query()->forceCreate([
            'full_name' => 'Rana Haddad',
            'email_normalized' => 'rana@example.com',
            'phone_normalized' => '+963955000111',
            'identity_key_hash' => hash('sha256', 'rana'),
            'identity_verified_at' => now(),
            'city_id' => $this->aleppo->id,
            'source' => 'public_link',
            'privacy_notice_version' => 'v1',
            'privacy_accepted_at' => now(),
        ]);
        $application = $this->applyTo($campaign, $applicant, [
            'status' => Status::Rejected,
            'campaign_invite_id' => $invite->id,
            'ip_address' => '203.0.113.9',
            'user_agent' => 'Mozilla/5.0 (Rana)',
            'submission_key_hash' => hash('sha256', 'submission'),
            'consent_payload_json' => ['privacy_consent' => true, 'locale' => 'ar'],
        ]);

        foreach (['full_name' => 'Rana Haddad', 'phone' => '+963955000111'] as $key => $value) {
            RecruitmentApplicationAnswer::query()->forceCreate([
                'application_id' => $application->id,
                'form_version_id' => $application->form_version_id,
                'field_key' => $key,
                'answer_json' => ['value' => $value],
            ]);
        }

        RecruitmentApplicantNote::query()->forceCreate([
            'applicant_id' => $applicant->id, 'application_id' => $application->id, 'user_id' => $hr->id,
            'note_type' => 'general', 'body' => 'Rana lives near the citadel and asked for a home address change',
        ]);
        RecruitmentApplicantNote::query()->forceCreate([
            'applicant_id' => $applicant->id, 'user_id' => $hr->id, 'note_type' => 'general',
            'body' => 'Second note', 'deleted_at' => now(), 'deleted_by_user_id' => $hr->id,
            'deletion_reason' => 'contained her home address',
        ]);

        DB::table('recruitment_application_decisions')->insert([
            'application_id' => $application->id, 'from_status' => 'submitted', 'to_status' => 'rejected',
            'reason_code' => 'not_qualified', 'reason_text' => 'No experience with budgets',
            'decided_by_user_id' => $hr->id, 'decided_at' => now()->subDays(20),
        ]);
        DB::table('recruitment_rejection_notices')->insert([
            'application_id' => $application->id, 'recipient_email' => 'rana@example.com', 'status' => 'sent',
            'requested_by_user_id' => $hr->id, 'queued_at' => now(), 'failure_reason' => 'no mailbox rana@example.com',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('recruitment_indicator_criteria_versions')->insert(['version' => 'v1', 'definition_json' => '{}']);
        DB::table('recruitment_application_indicator_snapshots')->insert([
            'application_id' => $application->id, 'criteria_version' => 'v1', 'met_count' => 3, 'assessed_count' => 4,
            'indicators_json' => json_encode([['key' => 'languages', 'detail' => 'Rana speaks French']]),
            'computed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $interviewId = DB::table('recruitment_interviews')->insertGetId([
            'application_id' => $application->id, 'interview_type' => 'hr', 'status' => 'completed',
            'scheduled_at' => now()->subDays(15), 'location_type' => 'online', 'created_by_user_id' => $hr->id,
            'outcome_note' => 'Rana was nervous but articulate', 'cancellation_note' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('recruitment_interview_reschedules')->insert([
            'interview_id' => $interviewId, 'from_scheduled_at' => now()->subDays(16), 'to_scheduled_at' => now()->subDays(15),
            'from_location_type' => 'online', 'to_location_type' => 'online',
            'reason' => 'Rana had a family emergency', 'rescheduled_by_user_id' => $hr->id, 'rescheduled_at' => now()->subDays(17),
        ]);
        DB::table('recruitment_interview_notices')->insert([
            'interview_id' => $interviewId, 'kind' => 'scheduled', 'scheduled_for' => now()->subDays(15),
            'recipient_email' => 'rana@example.com', 'status' => 'sent', 'queued_at' => now()->subDays(16),
            'failure_reason' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $evaluationId = DB::table('recruitment_interview_evaluations')->insertGetId([
            'interview_id' => $interviewId, 'reviewer_user_id' => $hr->id, 'pros' => 'Rana knows budgets',
            'cons' => 'Rana is shy', 'notes' => 'Private view of Rana', 'recommendation' => 'hire',
            'submitted_at' => now()->subDays(14), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('recruitment_interview_evaluation_revisions')->insert([
            'evaluation_id' => $evaluationId, 'version' => 1, 'action' => 'created', 'pros' => 'Rana knows budgets',
            'cons' => 'Rana is shy', 'notes' => 'Private view of Rana', 'recommendation' => 'hire',
            'reason' => 'Rana asked us to soften this', 'changed_by_user_id' => $hr->id, 'changed_at' => now()->subDays(14),
        ]);

        $offerId = DB::table('recruitment_job_offers')->insertGetId([
            'application_id' => $application->id, 'vacancy_id' => $campaign->vacancy_id,
            'position_id' => $campaign->vacancy->position_id, 'status' => 'rejected', 'created_by_user_id' => $hr->id,
            'response_note' => 'Rana declined for family reasons', 'cancellation_note' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('recruitment_job_offer_versions')->insert([
            'job_offer_id' => $offerId, 'version_no' => 1, 'status' => 'sent', 'created_by_user_id' => $hr->id,
            'revision_reason' => 'Rana negotiated', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $file = app(RecruitmentApplicantFileService::class)->store(
            $application,
            UploadedFile::fake()->createWithContent('Rana CV.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<<>>\n%%EOF\n"),
            'cv',
        );
        RecruitmentCvAnalysis::query()->forceCreate([
            'file_id' => $file->id, 'application_id' => $application->id, 'status' => 'done', 'analyzer_version' => 'test',
            'facts_json' => ['employers' => ['Rana Trading Co']], 'comparison_json' => [], 'analyzed_at' => now(),
        ]);

        return [$applicant->fresh(), $application->fresh(), $hr];
    }
}
