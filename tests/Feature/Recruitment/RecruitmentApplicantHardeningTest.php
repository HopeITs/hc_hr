<?php

namespace Tests\Feature\Recruitment;

use App\Enums\RecruitmentApplicationStatus as Status;
use App\Enums\RecruitmentFileScanStatus;
use App\Livewire\Recruitment\Applicants\ApplicationTable;
use App\Livewire\Recruitment\Applicants\BulkReject;
use App\Livewire\Recruitment\Applicants\Notes;
use App\Livewire\Recruitment\Applicants\Show;
use App\Models\City;
use App\Models\RecruitmentApplicant;
use App\Models\RecruitmentApplicantFile;
use App\Models\RecruitmentApplication;
use App\Models\User;
use App\Services\RecruitmentApplicantNoteService;
use App\Services\RecruitmentApplicationStatusService;
use App\Services\RecruitmentMatchingIndicators;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Tests\Concerns\BuildsRecruitmentApplications;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * Keyboard and screen-reader behaviour of the applicant screens: where focus
 * goes after each open / cancel / confirm, how a validation error is tied to
 * its field, and that direction-sensitive glyphs follow the page language.
 *
 * Focus is moved in the browser by a `focus-element` listener; these tests
 * pin the id each action names and that an element with that id is rendered.
 */
class RecruitmentApplicantHardeningTest extends TestCase
{
    use BuildsRecruitmentApplications, CreatesHrFixtures, RefreshDatabase;

    private City $homs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);

        $this->homs = $this->makeCity('HMS', '002');
    }

    // ------------------------------------------------------------------
    // Decisions
    // ------------------------------------------------------------------

    public function test_opening_and_cancelling_a_decision_moves_focus_between_button_and_panel(): void
    {
        $applicant = $this->makeApplicant();
        $application = $this->applyTo($this->makeCampaignIn($this->homs), $applicant);
        $this->actingAs($this->hr($this->homs));
        $opener = 'decide-'.$application->id.'-'.Status::Shortlisted;

        Livewire::test(Show::class, ['applicantId' => $applicant->id])
            ->assertSeeHtml('id="'.$opener.'"')
            ->call('beginStatusChange', $application->id, Status::Shortlisted)
            ->assertDispatched('focus-element', id: 'decision-panel-'.$application->id)
            ->assertSeeHtml('id="decision-panel-'.$application->id.'"')
            ->assertDontSeeHtml('id="'.$opener.'"')
            ->call('cancelStatusChange')
            ->assertDispatched('focus-element', id: $opener)
            ->assertSeeHtml('id="'.$opener.'"')
            ->assertDontSeeHtml('id="decision-panel-'.$application->id.'"');
    }

    public function test_a_confirmed_decision_moves_focus_to_the_application_heading(): void
    {
        $applicant = $this->makeApplicant();
        $application = $this->applyTo($this->makeCampaignIn($this->homs), $applicant);
        $this->actingAs($this->hr($this->homs));

        Livewire::test(Show::class, ['applicantId' => $applicant->id])
            ->call('beginStatusChange', $application->id, Status::Shortlisted)
            ->set('statusReason', 'Application is ready for the first recruiter review.')
            ->call('changeStatus')
            ->assertHasNoErrors()
            ->assertDispatched('focus-element', id: 'application-'.$application->id.'-title')
            ->assertSeeHtml('id="application-'.$application->id.'-title"');

        $this->assertSame(Status::Shortlisted, $application->fresh()->status);
    }

    public function test_a_missing_rejection_reason_is_announced_on_its_field_and_keeps_focus(): void
    {
        $applicant = $this->makeApplicant();
        $application = $this->applyTo($this->makeCampaignIn($this->homs), $applicant);
        $this->actingAs($this->hr($this->homs));

        $screen = Livewire::test(Show::class, ['applicantId' => $applicant->id])
            ->call('beginStatusChange', $application->id, Status::Rejected)
            ->set('statusReason', 'Candidate does not meet the mandatory language requirement.')
            ->assertDontSeeHtml('statusReasonCode-error')
            ->call('changeStatus')
            ->assertHasErrors('statusReasonCode')
            // A failed confirm leaves focus where it is: nothing to move to.
            ->assertNotDispatched('focus-element')
            ->assertSeeHtml('aria-invalid="true" aria-describedby="statusReasonCode-error"')
            ->assertSeeHtml('id="statusReasonCode-error"');

        $this->assertMatchesRegularExpression('/<p id="statusReasonCode-error"\s+role="alert"/', $screen->html());

        // The note is always required, including when the reason is "other".
        $screen->set('statusReasonCode', 'other')
            ->set('statusReason', '')
            ->call('changeStatus')
            ->assertHasErrors('statusReason')
            ->assertSeeHtml('id="statusReason-error"');

        $this->assertMatchesRegularExpression('/<textarea id="statusReason"[^>]*aria-describedby="statusReason-hint\s+statusReason-error\s*"[^>]*aria-invalid="true"/', $screen->html());
        $this->assertSame(Status::Submitted, $application->fresh()->status);
    }

    public function test_the_decision_history_uses_a_mirroring_arrow_with_a_spoken_word(): void
    {
        $applicant = $this->makeApplicant();
        $application = $this->applyTo($this->makeCampaignIn($this->homs), $applicant);
        $hr = $this->hr($this->homs);
        $statuses = app(RecruitmentApplicationStatusService::class);
        $statuses->change($hr, $application, Status::Submitted, Status::Shortlisted, null);
        $this->actingAs($hr);

        foreach (['en' => 'to', 'ar' => 'إلى'] as $locale => $word) {
            app()->setLocale($locale);
            $this->assertSame($word, __('recruitment-applicant.transition_to'));

            $html = Livewire::test(Show::class, ['applicantId' => $applicant->id])
                ->assertSeeHtml('<span class="sr-only">'.$word.'</span>')
                ->assertDontSeeHtml('&rarr;')
                ->assertDontSeeHtml('→')
                ->html();

            // From-state, flipping arrow, spoken word, to-state — in that order.
            $this->assertMatchesRegularExpression(
                '/'.preg_quote(__('recruitment-applicant.statuses.'.Status::Submitted), '/')
                .'\s*<svg class="[^"]*rtl:-scale-x-100[^"]*"[^>]*aria-hidden="true">.*?<\/svg><span class="sr-only">'.preg_quote($word, '/').'<\/span>\s*'
                .preg_quote(__('recruitment-applicant.statuses.'.Status::Shortlisted), '/').'/su',
                $html,
            );
        }
    }

    // ------------------------------------------------------------------
    // CV archiving
    // ------------------------------------------------------------------

    public function test_the_archive_panel_opens_inside_its_file_row_and_cancel_returns_to_the_button(): void
    {
        // A talent-pool CV: archiving it needs a reason, so an error can be shown.
        $applicant = $this->makeApplicant('Pool Paul', $this->homs, 'walk_in');
        $cv = $this->cvFor($applicant);
        $this->actingAs($this->hr($this->homs));

        $screen = Livewire::test(Show::class, ['applicantId' => $applicant->id])
            ->assertSeeHtml('id="archive-'.$cv->id.'"')
            ->call('beginArchive', $cv->id)
            ->assertDispatched('focus-element', id: 'archive-panel-'.$cv->id);

        $row = $this->fileRow($screen->html(), $cv->id);
        $this->assertStringContainsString('id="archive-panel-'.$cv->id.'"', $row);
        $this->assertStringContainsString('id="archiveReason"', $row);

        $screen->call('archiveCv')
            ->assertHasErrors('archiveReason')
            ->assertNotDispatched('focus-element');
        $row = $this->fileRow($screen->html(), $cv->id);
        $this->assertStringContainsString('id="archiveReason-error"', $row);
        $this->assertMatchesRegularExpression('/aria-describedby="archiveReason-hint\s+archiveReason-error\s*"/', $row);

        $screen->call('cancelArchive')
            ->assertDispatched('focus-element', id: 'archive-'.$cv->id)
            ->assertSeeHtml('id="archive-'.$cv->id.'"')
            ->assertDontSeeHtml('id="archive-panel-'.$cv->id.'"');

        $this->assertFalse($cv->fresh()->isArchived());
    }

    public function test_archiving_moves_focus_to_the_file_row(): void
    {
        $applicant = $this->makeApplicant();
        $application = $this->applyTo($this->makeCampaignIn($this->homs), $applicant);
        $cv = $this->cvFor($applicant, $application);
        $this->actingAs($this->hr($this->homs));

        Livewire::test(Show::class, ['applicantId' => $applicant->id])
            ->call('beginArchive', $cv->id)
            ->call('archiveCv')
            ->assertHasNoErrors()
            ->assertDispatched('focus-element', id: 'file-'.$cv->id)
            ->assertSeeHtml('id="file-'.$cv->id.'"')
            ->assertDontSeeHtml('id="archive-'.$cv->id.'"');

        $this->assertTrue($cv->fresh()->isArchived());
    }

    // ------------------------------------------------------------------
    // Notes
    // ------------------------------------------------------------------

    public function test_deleting_a_note_moves_focus_to_the_reason_then_back_or_to_the_heading(): void
    {
        $applicant = $this->makeApplicant();
        $application = $this->applyTo($this->makeCampaignIn($this->homs), $applicant);
        $hr = $this->hr($this->homs);
        $note = app(RecruitmentApplicantNoteService::class)->add($hr, $applicant, $application, 'Medical detail', sensitive: true);
        $this->actingAs($hr);

        Livewire::test(Notes::class, ['applicantId' => $applicant->id])
            ->assertSeeHtml('id="note-'.$note->id.'-delete"')
            ->call('confirmDelete', $note->id)
            ->assertDispatched('focus-element', id: 'deletionReason')
            ->assertSeeHtml('id="deletionReason"')
            ->call('cancelDelete')
            ->assertDispatched('focus-element', id: 'note-'.$note->id.'-delete')
            ->assertDontSeeHtml('id="deletionReason"')
            ->call('confirmDelete', $note->id)
            ->call('deleteNote')
            ->assertHasErrors('deletionReason')
            ->assertNotDispatched('focus-element')
            ->assertSeeHtml('id="deletionReason-error"')
            ->set('deletionReason', 'Recorded against the wrong applicant')
            ->call('deleteNote')
            ->assertHasNoErrors()
            ->assertDispatched('focus-element', id: 'notes-title')
            ->assertSeeHtml('id="notes-title"')
            ->assertDontSeeHtml('id="note-'.$note->id.'-delete"');

        $this->assertTrue($note->fresh()->trashed());
    }

    // ------------------------------------------------------------------
    // Bulk panels
    // ------------------------------------------------------------------

    public function test_bulk_rejection_focuses_its_panel_then_its_result_or_the_page_title(): void
    {
        Mail::fake();
        $application = $this->applyTo($this->makeCampaignIn($this->homs), $this->makeApplicant());
        $this->actingAs($this->hr($this->homs));

        Livewire::test(BulkReject::class)
            ->call('open', [$application->id])
            ->assertDispatched('focus-element', id: 'bulk-reject-title')
            ->assertSeeHtml('id="bulk-reject-title"')
            ->call('cancel')
            ->assertDispatched('focus-element', id: 'applicants-title')
            ->assertDontSeeHtml('id="bulk-reject-title"')
            ->call('open', [$application->id])
            ->set('reasonCode', 'position_filled')
            ->set('notify', false)
            ->call('apply')
            ->assertHasNoErrors()
            ->assertDispatched('focus-element', id: 'bulk-reject-result')
            ->assertSeeHtml('id="bulk-reject-result"');

        $this->assertSame(Status::Rejected, $application->fresh()->status);
    }

    // ------------------------------------------------------------------
    // Tags
    // ------------------------------------------------------------------

    // ------------------------------------------------------------------
    // List
    // ------------------------------------------------------------------

    public function test_the_indicator_column_spells_out_each_indicator_for_screen_readers(): void
    {
        $application = $this->applyTo($this->makeCampaignIn($this->homs), $this->makeApplicant('Listed Lina'));
        $this->actingAs($this->hr($this->homs));

        $set = app(RecruitmentMatchingIndicators::class)->evaluate($application);
        $cv = $set->get('cv_availability');
        $expected = e($cv->label().': '.__('recruitment-applicant.indicators.outcomes.'.$cv->outcome));

        $html = Livewire::test(ApplicationTable::class)->assertSee('Listed Lina')->html();

        $this->assertMatchesRegularExpression('/<span class="sr-only"> \([^<]*'.preg_quote($expected, '/').'[^<]*\)<\/span>/u', $html);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** Governorate HR holding what the permission migration grants HR roles. */
    private function hr(City $city): User
    {
        return $this->makeUser($city, 'HR-'.$city->slug, [
            'recruitment-campaigns.view',
            ...array_map(
                fn (string $action): string => "recruitment-applicants.{$action}",
                config('access-control.groups.recruitment-applicants'),
            ),
        ]);
    }

    private function cvFor(RecruitmentApplicant $applicant, ?RecruitmentApplication $application = null): RecruitmentApplicantFile
    {
        return RecruitmentApplicantFile::query()->forceCreate([
            'applicant_id' => $applicant->id,
            'application_id' => $application?->id,
            'file_type' => RecruitmentApplicantFile::TYPE_CV,
            'scan_status' => RecruitmentFileScanStatus::Clean,
            'uploaded_at' => now(),
        ]);
    }

    /** The rendered <li> of one file, from its opening tag to its own close. */
    private function fileRow(string $html, int $fileId): string
    {
        $this->assertSame(1, preg_match('/<li[^>]*\bid="file-'.$fileId.'".*?<\/li>/s', $html, $match), "File row {$fileId} is not rendered.");

        return $match[0];
    }
}
