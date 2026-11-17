<?php

use App\Livewire\Recruitment\ApplicationForms\Editor as ApplicationFormEditor;
use App\Livewire\Recruitment\JobPosts\Editor as JobPostEditor;
use App\Models\HiringCampaign;
use Illuminate\Support\Facades\Route;

Route::prefix('recruitment/campaigns')
    ->name('recruitment.campaigns.')
    ->group(function () {
        // Preserve existing links from the editors, while keeping all
        // campaign preparation on one page.
        Route::get('/{campaign}/publishing', function (HiringCampaign $campaign) {
            return to_route('recruitment.campaigns.show', ['campaignId' => $campaign->getKey()])
                ->withFragment('campaign-publishing');
        })
            ->middleware('active_permission:recruitment-campaigns.view')
            ->name('publishing');

        Route::get('/{campaign}/job-post', JobPostEditor::class)
            ->middleware('active_permission:recruitment-job-posts.manage')
            ->name('job-post.edit');

        Route::get('/{campaign}/application-form', ApplicationFormEditor::class)
            ->middleware('active_permission:recruitment-forms.manage')
            ->name('application-form.edit');
    });
