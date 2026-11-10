<?php

use App\Http\Controllers\RecruitmentApplicantErasureController;
use App\Http\Controllers\RecruitmentApplicantExportController;
use App\Http\Controllers\RecruitmentApplicantFileController;
use App\Livewire\Recruitment\Applicants\Index as ApplicantIndex;
use App\Livewire\Recruitment\Applicants\Show as ApplicantShow;
use App\Livewire\Recruitment\Approvals\Pending;
use App\Livewire\Recruitment\Campaigns\Create as CampaignCreate;
use App\Livewire\Recruitment\Campaigns\Index as CampaignIndex;
use App\Livewire\Recruitment\Campaigns\Invites;
use App\Livewire\Recruitment\Campaigns\Show as CampaignShow;
use App\Livewire\Recruitment\Indicators\Rules as IndicatorRules;
use App\Livewire\Recruitment\Interviews\Form as InterviewForm;
use App\Livewire\Recruitment\Interviews\Index as InterviewIndex;
use App\Livewire\Recruitment\Interviews\Show as InterviewShow;
use App\Livewire\Recruitment\Offers\Convert as OfferConvert;
use App\Livewire\Recruitment\Offers\Form as OfferForm;
use App\Livewire\Recruitment\Offers\Index as OfferIndex;
use App\Livewire\Recruitment\Offers\Revise as OfferRevise;
use App\Livewire\Recruitment\Offers\Show as OfferShow;
use App\Livewire\Recruitment\Requests\Create;
use App\Livewire\Recruitment\Requests\Details;
use App\Livewire\Recruitment\Requests\DirectOpen;
use App\Livewire\Recruitment\Requests\Edit;
use App\Livewire\Recruitment\Requests\Index;
use App\Livewire\Recruitment\Requests\TeamStaffing;
use Illuminate\Support\Facades\Route;

Route::prefix('recruitment/requests')->name('recruitment.requests.')
    ->middleware('active_permission:recruitment-requests.view')
    ->group(function () {
        Route::get('/', Index::class)->name('index');
        Route::get('/team', TeamStaffing::class)
            ->middleware('active_permission:recruitment-requests.manage')->name('team');
        Route::get('/create', Create::class)->middleware('active_permission:recruitment-requests.manage')->name('create');
        // The shortcut is behind its own permission, never implied by manage.
        Route::get('/direct-open', DirectOpen::class)
            ->middleware('active_permission:recruitment-requests.direct-open')->name('direct-open');
        Route::get('/{requestId}/edit', Edit::class)->whereNumber('requestId')->middleware('active_permission:recruitment-requests.manage')->name('edit');
        Route::get('/{requestId}', Details::class)->whereNumber('requestId')->name('show');
    });

Route::get('recruitment/approvals', Pending::class)
    ->middleware(['active_permission:recruitment-requests.approve'])
    ->name('recruitment.approvals.pending');

Route::prefix('recruitment/campaigns')->name('recruitment.campaigns.')
    ->middleware('active_permission:recruitment-campaigns.view')
    ->group(function () {
        Route::get('/', CampaignIndex::class)->name('index');
        // The optional vacancy lets an approved request hand the seat
        // straight over rather than making HR find it again in a list.
        Route::get('/create/{vacancyId?}', CampaignCreate::class)
            ->whereNumber('vacancyId')
            ->middleware('active_permission:recruitment-campaigns.manage')->name('create');
        Route::get('/{campaignId}', CampaignShow::class)->whereNumber('campaignId')->name('show');
    });

// The permission is the outer wall; each applicant is re-resolved through the
// recruitment scope inside the component, so a guessed id is a 404.
Route::prefix('recruitment/applicants')->name('recruitment.applicants.')
    ->middleware('active_permission:recruitment-applicants.view')
    ->group(function () {
        Route::get('/', ApplicantIndex::class)->name('index');
        Route::get('/{applicantId}', ApplicantShow::class)->whereNumber('applicantId')->name('show');
    });

// Phase 2 Task 7 (Team 2): interviews. The list, calendar and a single
// interview have no permission middleware on purpose: participants see the
// interviews they are on without holding any applicant permission, and the
// components resolve every interview through RecruitmentInterview::visibleTo()
// and the policy (out of reach is a 404). Scheduling and editing are HR's.
Route::prefix('recruitment/interviews')->name('recruitment.interviews.')
    ->group(function () {
        Route::get('/', InterviewIndex::class)->name('index');
        Route::get('/{interviewId}', InterviewShow::class)->whereNumber('interviewId')->name('show');
        Route::get('/{interviewId}/edit', InterviewForm::class)->whereNumber('interviewId')
            ->middleware('active_permission:recruitment-applicants.schedule-interviews')->name('edit');
    });

Route::get('recruitment/applications/{applicationId}/interviews/create', InterviewForm::class)
    ->whereNumber('applicationId')
    ->middleware('active_permission:recruitment-applicants.schedule-interviews')
    ->name('recruitment.interviews.create');

// Phase 2 Task 9 (Team 2): job offers. The list and a single offer have no
// permission middleware on purpose: the manager who requested the position
// reads them without any recruitment permission. The components resolve every
// offer through RecruitmentJobOffer::visibleTo() and the policy (a guessed id
// is a 404); writing keeps its own permission, and the policy also requires
// canViewSalary() to write one.
Route::prefix('recruitment/offers')->name('recruitment.offers.')
    ->group(function () {
        Route::get('/', OfferIndex::class)->name('index');
        Route::get('/{offerId}', OfferShow::class)->whereNumber('offerId')->name('show');
        Route::get('/{offerId}/edit', OfferForm::class)->whereNumber('offerId')
            ->middleware('active_permission:recruitment-applicants.manage-offers')->name('edit');
        // Its own component, not a route default on OfferForm: see OfferForm::mount().
        Route::get('/{offerId}/revise', OfferRevise::class)->whereNumber('offerId')
            ->middleware('active_permission:recruitment-applicants.manage-offers')->name('revise');
        // Phase 2 Task 10: hire the candidate who accepted — employee and
        // contract. The component asks the conversion policy (convert) too.
        Route::get('/{offerId}/convert', OfferConvert::class)->whereNumber('offerId')
            ->middleware('active_permission:recruitment-applicants.convert-hires')->name('convert');
    });

Route::get('recruitment/applications/{applicationId}/offer/create', OfferForm::class)
    ->whereNumber('applicationId')
    ->middleware('active_permission:recruitment-applicants.manage-offers')
    ->name('recruitment.offers.create');

// Phase 2 Task 11. Outside the group above, with no permission middleware, for
// the same reason as the file download below: the policy enforces the explicit
// export / erasure permission inside the controller, so a refused attempt is
// audited instead of being stopped at the door. Both are POST (CSRF-protected).
Route::post('recruitment/applicants/export', RecruitmentApplicantExportController::class)
    ->middleware('throttle:10,1')
    ->name('recruitment.applicants.export');

Route::post('recruitment/applicants/{applicant}/erasure', [RecruitmentApplicantErasureController::class, 'store'])
    ->whereNumber('applicant')
    ->middleware('throttle:10,1')
    ->name('recruitment.applicants.erasure');

// No permission middleware on purpose: the policy enforces it, so a refused
// attempt still reaches the controller and lands in the download audit.
Route::get('recruitment/applicant-files/{file}/download', [RecruitmentApplicantFileController::class, 'download'])
    ->whereNumber('file')
    ->middleware('throttle:60,1')
    ->name('recruitment.applicant-files.download');

// Team 2 (Phase 2 Task 1): stand-alone page for a campaign's invite list.
// The same InviteTable is also embedded in Team 1's publishing page.
Route::get('recruitment/campaigns/{campaignId}/invites', Invites::class)
    ->whereNumber('campaignId')
    ->middleware('active_permission:recruitment-applicants.manage')
    ->name('recruitment.campaigns.invites');

// HR's editor for the matching-indicator rules. The action methods are guarded
// by the 'livewire' map in config/access-control.php, and the component checks
// the permission itself as well.
Route::get('recruitment/indicator-rules', IndicatorRules::class)
    ->middleware('active_permission:recruitment-indicators.manage')
    ->name('recruitment.indicator-rules');
