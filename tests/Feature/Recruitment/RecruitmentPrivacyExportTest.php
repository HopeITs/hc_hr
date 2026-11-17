<?php

namespace Tests\Feature\Recruitment;

use App\Enums\RecruitmentApplicationStatus as Status;
use App\Models\City;
use App\Models\Permission;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentPrivacyAudit;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use LogicException;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Tests\Concerns\BuildsRecruitmentApplications;
use Tests\Concerns\CapturesSecurityEvents;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * Phase 2 Task 11: candidate data export.
 *
 * The rules under test: a distinct permission that no role carries and that an
 * administrator role does not imply; rows limited to the caller's scope
 * whatever the filters say; size and rate limits; an audit row for every
 * attempt, written before any data leaves; a spreadsheet that cannot run an
 * applicant's name as a formula.
 */
class RecruitmentPrivacyExportTest extends TestCase
{
    use BuildsRecruitmentApplications, CapturesSecurityEvents, CreatesHrFixtures, RefreshDatabase;

    private const EXPORT = 'recruitment-applicants.export';

    private City $aleppo;

    private City $damascus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);
        $this->captureSecurityEvents();

        $this->aleppo = $this->makeCity('ALP', '001');
        $this->damascus = $this->makeCity('DMS', '003');
    }

    // ------------------------------------------------------------------
    // Permission
    // ------------------------------------------------------------------

    public function test_export_and_erasure_are_attached_to_no_role_not_even_a_new_hr_role(): void
    {
        $new = Role::findOrCreate('HR-TAR', 'web');
        $administrator = Role::findOrCreate('super-admin-clone', 'web');

        foreach ([self::EXPORT, 'recruitment-applicants.request-erasure'] as $name) {
            $this->assertSame(0, Permission::findByName($name)->roles()->count(), "{$name} must not be granted through any role.");
            $this->assertFalse($new->hasPermissionTo($name));
            $this->assertFalse($administrator->hasPermissionTo($name));
        }
    }

    public function test_an_administrator_role_does_not_imply_the_export_permission(): void
    {
        $admin = $this->makeUser($this->aleppo, 'super-admin');

        // The bypass every other permission enjoys...
        $this->assertTrue($admin->hasActivePermission(self::EXPORT));
        // ...is exactly what this one refuses.
        $this->assertFalse($admin->hasExplicitPermission(self::EXPORT));
        $this->assertSame('missing_permission', Gate::forUser($admin)->inspect('export', RecruitmentApplication::class)->code());

        $admin->givePermissionTo(self::EXPORT);
        $this->assertTrue(Gate::forUser($admin->fresh())->inspect('export', RecruitmentApplication::class)->allowed());
    }

    public function test_hr_with_view_but_without_export_is_refused_and_the_attempt_is_audited(): void
    {
        $hr = $this->hr($this->aleppo, withExport: false);
        $this->applyTo($this->makeCampaignIn($this->aleppo), $this->makeApplicant('Aleppo One', $this->aleppo));

        $this->actingAs($hr)->post(route('recruitment.applicants.export'))->assertForbidden();

        $audit = RecruitmentPrivacyAudit::query()->sole();
        $this->assertSame('export', $audit->action);
        $this->assertSame('refused', $audit->outcome);
        $this->assertSame('missing_permission', $audit->reason);
        $this->assertSame($hr->id, (int) $audit->actor_user_id);
        $this->assertSame(0, $audit->record_count);
        $this->assertSame(['export.refused'], array_column($this->securityEvents('export.refused'), 'event'));
    }

    public function test_export_needs_view_as_well(): void
    {
        $user = $this->makeUser($this->aleppo, 'HR-ALP', [self::EXPORT]);

        $this->actingAs($user)->post(route('recruitment.applicants.export'))->assertForbidden();
        $this->assertSame('missing_permission', RecruitmentPrivacyAudit::query()->sole()->reason);
    }

    // ------------------------------------------------------------------
    // Scope
    // ------------------------------------------------------------------

    public function test_the_file_holds_only_what_the_caller_may_see_and_the_audit_records_how_far_they_reach(): void
    {
        $alpCampaign = $this->makeCampaignIn($this->aleppo);
        $this->applyTo($alpCampaign, $this->makeApplicant('Aleppo One', $this->aleppo));
        $this->applyTo($alpCampaign, $this->makeApplicant('Aleppo Two', $this->aleppo));
        $this->applyTo($this->makeCampaignIn($this->damascus), $this->makeApplicant('Damascus One', $this->damascus));
        $hr = $this->hr($this->aleppo);

        $response = $this->actingAs($hr)->post(route('recruitment.applicants.export'));

        $response->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertHeader('x-content-type-options', 'nosniff');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('cache-control'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('content-disposition'));

        $rows = $this->csv($response->streamedContent());
        $this->assertSame(['Aleppo One', 'Aleppo Two'], array_column($rows, 'full_name'));
        $this->assertSame(['ALP', 'ALP'], array_column($rows, 'governorate'));
        $this->assertStringNotContainsString('Damascus', $response->streamedContent());

        $audit = RecruitmentPrivacyAudit::query()->sole();
        $this->assertSame(RecruitmentPrivacyAudit::ACTION_EXPORT, $audit->action);
        $this->assertSame(RecruitmentPrivacyAudit::OUTCOME_SUCCESS, $audit->outcome);
        $this->assertSame($hr->id, (int) $audit->actor_user_id);
        $this->assertSame('HR-ALP', $audit->actor_role);
        $this->assertSame('city', $audit->scope_type);
        $this->assertSame([$this->aleppo->id], $audit->scope_city_ids);
        $this->assertNull($audit->filters_json);
        $this->assertSame(2, $audit->record_count);
        $this->assertNotNull($audit->created_at);
        $this->assertNotNull($audit->ip_address);
    }

    public function test_organization_wide_hr_exports_every_governorate(): void
    {
        $this->applyTo($this->makeCampaignIn($this->aleppo), $this->makeApplicant('Aleppo One', $this->aleppo));
        $this->applyTo($this->makeCampaignIn($this->damascus), $this->makeApplicant('Damascus One', $this->damascus));
        $hq = $this->makeUser($this->damascus, 'HR-SYR', ['recruitment-applicants.view', self::EXPORT]);

        $rows = $this->csv($this->actingAs($hq)->post(route('recruitment.applicants.export'))->streamedContent());

        $this->assertEqualsCanonicalizing(['Aleppo One', 'Damascus One'], array_column($rows, 'full_name'));
        $this->assertSame('organization', RecruitmentPrivacyAudit::query()->sole()->scope_type);
    }

    public function test_filters_narrow_the_export_and_are_audited_as_applied(): void
    {
        $campaign = $this->makeCampaignIn($this->aleppo);
        $this->applyTo($campaign, $this->makeApplicant('Rejected One', $this->aleppo), ['status' => Status::Rejected]);
        $this->applyTo($campaign, $this->makeApplicant('Submitted One', $this->aleppo));
        $hr = $this->hr($this->aleppo);

        $response = $this->actingAs($hr)->post(route('recruitment.applicants.export'), [
            'filters' => ['status' => ['rejected'], 'search' => '  ', 'unknown' => 'x', 'campaign' => [(string) $campaign->id]],
        ]);

        $this->assertSame(['Rejected One'], array_column($this->csv($response->streamedContent()), 'full_name'));

        $audit = RecruitmentPrivacyAudit::query()->sole();
        $this->assertSame(['campaign' => [(string) $campaign->id], 'status' => ['rejected']], $audit->filters_json);
        $this->assertSame(1, $audit->record_count);
    }

    public function test_a_filter_naming_something_outside_the_scope_is_an_attempt_not_an_empty_result(): void
    {
        $this->applyTo($this->makeCampaignIn($this->aleppo), $this->makeApplicant('Aleppo One', $this->aleppo));
        $damascus = $this->makeCampaignIn($this->damascus);
        $this->applyTo($damascus, $this->makeApplicant('Damascus One', $this->damascus));
        $hr = $this->hr($this->aleppo);

        $this->actingAs($hr)
            ->post(route('recruitment.applicants.export'), ['filters' => ['campaign' => [$damascus->id]]])
            ->assertForbidden();

        $audit = RecruitmentPrivacyAudit::query()->sole();
        $this->assertSame('refused', $audit->outcome);
        $this->assertSame('out_of_scope_filter', $audit->reason);
        $this->assertSame(['campaign' => [(string) $damascus->id]], $audit->filters_json);

        $this->actingAs($hr)
            ->post(route('recruitment.applicants.export'), ['filters' => ['city' => [$this->damascus->id]]])
            ->assertForbidden();
        $this->assertSame(2, RecruitmentPrivacyAudit::query()->where('reason', 'out_of_scope_filter')->count());
    }

    // ------------------------------------------------------------------
    // Size and rate
    // ------------------------------------------------------------------

    public function test_an_export_over_the_cap_is_refused_audited_and_reported(): void
    {
        config(['recruitment-privacy.export.max_rows' => 2]);
        $campaign = $this->makeCampaignIn($this->aleppo);
        foreach (['One', 'Two', 'Three'] as $name) {
            $this->applyTo($campaign, $this->makeApplicant("Applicant {$name}", $this->aleppo));
        }
        $hr = $this->hr($this->aleppo);

        $this->actingAs($hr)
            ->post(route('recruitment.applicants.export'))
            ->assertSessionHasErrors('export');

        $audit = RecruitmentPrivacyAudit::query()->sole();
        $this->assertSame('refused', $audit->outcome);
        $this->assertSame('too_large', $audit->reason);
        $this->assertSame(3, $audit->record_count);
        $this->assertSame('too_large', $this->securityEvents('export.refused')[0]['context']['reason']);

        // Narrowing it under the cap works.
        config(['recruitment-privacy.export.max_rows' => 5]);
        $this->actingAs($hr)->post(route('recruitment.applicants.export'))->assertOk();
    }

    public function test_a_large_export_is_reported_to_monitoring_even_when_allowed(): void
    {
        config(['recruitment-privacy.export.alert_rows' => 2]);
        $campaign = $this->makeCampaignIn($this->aleppo);
        $this->applyTo($campaign, $this->makeApplicant('Applicant One', $this->aleppo));
        $hr = $this->hr($this->aleppo);

        $this->actingAs($hr)->post(route('recruitment.applicants.export'))->assertOk();
        $this->assertSame([], $this->securityEvents('export.large'));

        $this->applyTo($campaign, $this->makeApplicant('Applicant Two', $this->aleppo));
        $this->actingAs($hr)->post(route('recruitment.applicants.export'))->assertOk();

        $event = $this->securityEvents('export.large');
        $this->assertCount(1, $event);
        $this->assertSame('warning', $event[0]['level']);
        $this->assertSame(2, $event[0]['context']['records']);
        $this->assertSame($hr->id, $event[0]['context']['user_id']);
    }

    public function test_exports_are_limited_per_user_per_hour(): void
    {
        config(['recruitment-privacy.export.max_per_hour' => 1]);
        $this->applyTo($this->makeCampaignIn($this->aleppo), $this->makeApplicant('Applicant One', $this->aleppo));
        $hr = $this->hr($this->aleppo);

        $this->actingAs($hr)->post(route('recruitment.applicants.export'))->assertOk();
        $this->actingAs($hr)->post(route('recruitment.applicants.export'))->assertStatus(429);

        $this->assertSame(
            [['success', null], ['refused', 'rate_limited']],
            RecruitmentPrivacyAudit::query()->orderBy('id')->get()->map(fn ($row): array => [$row->outcome, $row->reason])->all(),
        );
    }

    public function test_an_export_with_nothing_in_it_is_a_validation_error_and_not_an_audited_act(): void
    {
        $this->applyTo($this->makeCampaignIn($this->aleppo), $this->makeApplicant('Applicant One', $this->aleppo));

        $this->actingAs($this->hr($this->aleppo))
            ->post(route('recruitment.applicants.export'), ['filters' => ['search' => 'nobody-called-this']])
            ->assertSessionHasErrors('export');

        $this->assertSame(0, RecruitmentPrivacyAudit::query()->count());
    }

    // ------------------------------------------------------------------
    // The file itself
    // ------------------------------------------------------------------

    public function test_a_spreadsheet_cannot_run_an_applicants_name_as_a_formula(): void
    {
        $campaign = $this->makeCampaignIn($this->aleppo);
        $formula = $this->makeApplicant('=HYPERLINK("http://evil.test","click")', $this->aleppo);
        $formula->forceFill(['phone_normalized' => '+963111234567'])->save();
        $mention = $this->makeApplicant('@SUM(1+1)', $this->aleppo);
        $this->applyTo($campaign, $formula);
        $this->applyTo($campaign, $mention);

        $content = $this->actingAs($this->hr($this->aleppo))->post(route('recruitment.applicants.export'))->streamedContent();
        $rows = $this->csv($content);

        $this->assertSame(['\'=HYPERLINK("http://evil.test","click")', '\'@SUM(1+1)'], array_column($rows, 'full_name'));
        // A phone number starts with '+' too, but can only ever be digits.
        $this->assertSame('+963111234567', $rows[0]['phone']);
    }

    public function test_anonymized_applicants_appear_without_their_data(): void
    {
        $applicant = $this->makeApplicant('Rana Haddad', $this->aleppo);
        $this->applyTo($this->makeCampaignIn($this->aleppo), $applicant);
        $applicant->forceFill(['full_name' => '[anonymized]', 'anonymized_at' => now()])->save();

        $content = $this->actingAs($this->hr($this->aleppo))->post(route('recruitment.applicants.export'))->streamedContent();
        $rows = $this->csv($content);

        $this->assertStringNotContainsString('Rana', $content);
        $this->assertSame('yes', $rows[0]['anonymized']);
    }

    public function test_the_file_carries_the_consent_the_application_was_made_under(): void
    {
        $this->applyTo($this->makeCampaignIn($this->aleppo), $this->makeApplicant('Applicant One', $this->aleppo));

        $rows = $this->csv($this->actingAs($this->hr($this->aleppo))->post(route('recruitment.applicants.export'))->streamedContent());

        $this->assertSame('v1', $rows[0]['privacy_notice_version']);
        $this->assertNotSame('', $rows[0]['privacy_accepted_at']);
    }

    public function test_the_audit_trail_is_append_only(): void
    {
        $this->applyTo($this->makeCampaignIn($this->aleppo), $this->makeApplicant('Applicant One', $this->aleppo));
        $this->actingAs($this->hr($this->aleppo))->post(route('recruitment.applicants.export'))->assertOk();
        $audit = RecruitmentPrivacyAudit::query()->sole();

        try {
            $audit->update(['record_count' => 0]);
            $this->fail('An audit row was edited.');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        $audit->delete();
    }

    public function test_an_unauthenticated_request_never_reaches_the_exporter(): void
    {
        $this->post(route('recruitment.applicants.export'))->assertRedirect();

        $this->assertSame(0, RecruitmentPrivacyAudit::query()->count());
    }

    // ------------------------------------------------------------------

    /** Export is granted to the person, not the role: two HR users of one city must not share it. */
    private function hr(City $city, bool $withExport = true): User
    {
        $user = $this->makeUser($city, 'HR-'.$city->slug, ['recruitment-applicants.view']);

        if ($withExport) {
            $user->givePermissionTo(self::EXPORT);
        }

        return $user->fresh();
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function csv(string $content): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $lines = array_values(array_filter(explode("\n", str_replace("\r\n", "\n", $content)), fn (string $line): bool => $line !== ''));
        $header = str_getcsv(array_shift($lines), ',', '"', '');

        return array_map(fn (string $line): array => array_combine($header, str_getcsv($line, ',', '"', '')), $lines);
    }
}
