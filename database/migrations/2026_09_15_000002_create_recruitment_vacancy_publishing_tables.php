<?php

use App\Enums\HiringCampaignStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vacancies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('position_id');
            $table->foreignId('created_from_request_id');
            $table->string('status', 60)->default('open');
            $table->unsignedInteger('needed_count');
            $table->unsignedInteger('filled_count')->default(0);
            $table->dateTime('open_at');
            $table->dateTime('closed_at')->nullable();
            $table->text('close_reason')->nullable();
            $table->timestamps();

            $table->unique('created_from_request_id', 'vac_request_uq');
            $table->index(['position_id', 'status'], 'vac_position_status_idx');
            $table->index('status', 'vac_status_idx');

            $table->foreign('position_id', 'vac_position_fk')
                ->references('id')->on('positions')->restrictOnDelete();
            $table->foreign('created_from_request_id', 'vac_request_fk')
                ->references('id')->on('recruitment_requests')->restrictOnDelete();
        });

        Schema::create('hiring_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vacancy_id');
            $table->unsignedTinyInteger('status')->default(HiringCampaignStatus::Draft);
            $table->string('link_mode', 60)->default('public_open');
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->foreignId('created_by_user_id');
            $table->foreignId('closed_by_user_id')->nullable();
            $table->timestamps();

            $table->index(['vacancy_id', 'status'], 'hc_vacancy_status_idx');
            $table->index(['status', 'starts_at', 'ends_at'], 'hc_status_window_idx');
            $table->index('link_mode', 'hc_link_mode_idx');
            $table->index('created_by_user_id', 'hc_creator_idx');
            $table->index('closed_by_user_id', 'hc_closer_idx');

            $table->foreign('vacancy_id', 'hc_vacancy_fk')
                ->references('id')->on('vacancies')->restrictOnDelete();
            $table->foreign('created_by_user_id', 'hc_creator_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('closed_by_user_id', 'hc_closer_fk')
                ->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('job_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id');
            $table->string('status', 60)->default('draft');
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->foreignId('created_by_user_id');
            $table->timestamps();

            $table->unique('campaign_id', 'jp_campaign_uq');
            $table->unique('current_version_id', 'jp_current_version_uq');
            $table->index('created_by_user_id', 'jp_creator_idx');
            $table->index('status', 'jp_status_idx');

            $table->foreign('campaign_id', 'jp_campaign_fk')
                ->references('id')->on('hiring_campaigns')->cascadeOnDelete();
            $table->foreign('created_by_user_id', 'jp_creator_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('job_post_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_post_id');
            $table->unsignedInteger('version_no');
            $table->string('title_en');
            $table->string('title_ar');
            $table->text('body_en')->nullable();
            $table->text('body_ar')->nullable();
            $table->json('public_payload_json');
            $table->string('status', 60)->default('draft');
            $table->dateTime('published_at')->nullable();
            $table->timestamps();

            $table->unique(['job_post_id', 'version_no'], 'jpv_post_version_uq');
            $table->index(['job_post_id', 'status'], 'jpv_post_status_idx');
            $table->index('status', 'jpv_status_idx');
            $table->index('published_at', 'jpv_published_idx');

            $table->foreign('job_post_id', 'jpv_job_post_fk')
                ->references('id')->on('job_posts')->cascadeOnDelete();
        });

        Schema::create('application_forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id');
            $table->string('status', 60)->default('draft');
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->foreignId('created_by_user_id');
            $table->timestamps();

            $table->unique('campaign_id', 'af_campaign_uq');
            $table->unique('current_version_id', 'af_current_version_uq');
            $table->index('created_by_user_id', 'af_creator_idx');
            $table->index('status', 'af_status_idx');

            $table->foreign('campaign_id', 'af_campaign_fk')
                ->references('id')->on('hiring_campaigns')->cascadeOnDelete();
            $table->foreign('created_by_user_id', 'af_creator_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('application_form_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_form_id');
            $table->unsignedInteger('version_no');
            $table->json('fields_json');
            $table->string('privacy_notice_version')->nullable();
            $table->string('status', 60)->default('draft');
            $table->dateTime('published_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['application_form_id', 'version_no'],
                'afv_form_version_uq',
            );
            $table->index(['application_form_id', 'status'], 'afv_form_status_idx');
            $table->index('status', 'afv_status_idx');
            $table->index('published_at', 'afv_published_idx');

            $table->foreign('application_form_id', 'afv_form_fk')
                ->references('id')->on('application_forms')->cascadeOnDelete();
        });

        Schema::create('public_recruitment_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id');
            $table->foreignId('job_post_version_id');
            $table->foreignId('form_version_id');
            $table->string('token_hash', 64);
            $table->string('status', 60)->default('active');
            $table->dateTime('expires_at');
            $table->foreignId('created_by_user_id');
            $table->timestamps();

            $table->unique('token_hash', 'prl_token_hash_uq');
            $table->index(['campaign_id', 'status'], 'prl_campaign_status_idx');
            $table->index(['status', 'expires_at'], 'prl_status_expiry_idx');
            $table->index('expires_at', 'prl_expires_idx');
            $table->index('job_post_version_id', 'prl_post_version_idx');
            $table->index('form_version_id', 'prl_form_version_idx');
            $table->index('created_by_user_id', 'prl_creator_idx');

            $table->foreign('campaign_id', 'prl_campaign_fk')
                ->references('id')->on('hiring_campaigns')->cascadeOnDelete();
            $table->foreign('job_post_version_id', 'prl_post_version_fk')
                ->references('id')->on('job_post_versions')->restrictOnDelete();
            $table->foreign('form_version_id', 'prl_form_version_fk')
                ->references('id')->on('application_form_versions')->restrictOnDelete();
            $table->foreign('created_by_user_id', 'prl_creator_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('recruitment_campaign_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id');
            $table->string('full_name', 191);
            $table->string('email_normalized', 191);
            $table->string('status', 60)->default('pending');
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('used_at')->nullable();
            $table->foreignId('created_by_user_id');
            $table->timestamps();

            $table->unique(['campaign_id', 'email_normalized'], 'rci_campaign_email_uq');
            $table->index(['campaign_id', 'status'], 'rci_campaign_status_idx');
            $table->index('email_normalized', 'rci_email_idx');
            $table->index('status', 'rci_status_idx');
            $table->index('created_by_user_id', 'rci_creator_idx');

            $table->foreign('campaign_id', 'rci_campaign_fk')
                ->references('id')->on('hiring_campaigns')->cascadeOnDelete();
            $table->foreign('created_by_user_id', 'rci_creator_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_campaign_invites');
        Schema::dropIfExists('public_recruitment_links');
        Schema::dropIfExists('application_form_versions');
        Schema::dropIfExists('application_forms');
        Schema::dropIfExists('job_post_versions');
        Schema::dropIfExists('job_posts');
        Schema::dropIfExists('hiring_campaigns');
        Schema::dropIfExists('vacancies');
    }
};
