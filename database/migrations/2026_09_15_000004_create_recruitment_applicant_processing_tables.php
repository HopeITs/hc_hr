<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_applicants', function (Blueprint $table) {
            $table->id();
            $table->string('full_name', 191);
            $table->string('email_normalized', 191);
            $table->string('phone_normalized', 50)->nullable();
            $table->string('identity_key_hash', 64)->nullable();
            $table->dateTime('identity_verified_at')->nullable();
            $table->foreignId('city_id')->nullable();
            $table->string('source', 60);
            $table->dateTime('last_seen_at')->nullable();
            $table->dateTime('retention_until')->nullable();
            $table->dateTime('anonymized_at')->nullable();
            $table->timestamps();

            $table->unique('identity_key_hash', 'ra_identity_hash_uq');
            $table->index('email_normalized', 'ra_email_idx');
            $table->index('phone_normalized', 'ra_phone_idx');
            $table->index('retention_until', 'ra_retention_idx');
            $table->index('city_id', 'ra_city_idx');

            $table->foreign('city_id', 'ra_city_fk')
                ->references('id')->on('cities')->nullOnDelete();
        });

        Schema::create('recruitment_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('applicant_id');
            $table->foreignId('campaign_id');
            $table->foreignId('vacancy_id');
            $table->foreignId('form_version_id');
            $table->foreignId('public_link_id');
            $table->foreignId('campaign_invite_id')->nullable();
            $table->string('submission_key_hash', 64)->nullable();
            $table->string('email_normalized', 191);
            $table->string('status', 60)->default('submitted');
            $table->string('privacy_notice_version')->nullable();
            $table->dateTime('privacy_accepted_at')->nullable();
            $table->json('consent_payload_json')->nullable();
            $table->dateTime('submitted_at');
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->unique('campaign_invite_id', 'rapp_invite_uq');
            $table->unique('submission_key_hash', 'rapp_submission_hash_uq');
            $table->unique(['applicant_id', 'campaign_id'], 'rapp_applicant_campaign_uq');
            $table->unique(['campaign_id', 'email_normalized'], 'rapp_campaign_email_uq');
            $table->index(['campaign_id', 'status'], 'rapp_campaign_status_idx');
            $table->index(['vacancy_id', 'status'], 'rapp_vacancy_status_idx');
            $table->index('form_version_id', 'rapp_form_version_idx');
            $table->index('public_link_id', 'rapp_public_link_idx');
            $table->index('submitted_at', 'rapp_submitted_idx');
            $table->index('email_normalized', 'rapp_email_idx');
            $table->index('status', 'rapp_status_idx');

            $table->foreign('applicant_id', 'rapp_applicant_fk')
                ->references('id')->on('recruitment_applicants')->restrictOnDelete();
            $table->foreign('campaign_id', 'rapp_campaign_fk')
                ->references('id')->on('hiring_campaigns')->restrictOnDelete();
            $table->foreign('vacancy_id', 'rapp_vacancy_fk')
                ->references('id')->on('vacancies')->restrictOnDelete();
            $table->foreign('form_version_id', 'rapp_form_version_fk')
                ->references('id')->on('application_form_versions')->restrictOnDelete();
            $table->foreign('public_link_id', 'rapp_public_link_fk')
                ->references('id')->on('public_recruitment_links')->restrictOnDelete();
            $table->foreign('campaign_invite_id', 'rapp_invite_fk')
                ->references('id')->on('recruitment_campaign_invites')->restrictOnDelete();
        });

        Schema::create('recruitment_application_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id');
            $table->foreignId('form_version_id');
            $table->string('field_key');
            $table->json('answer_json');
            $table->timestamps();

            $table->unique(['application_id', 'field_key'], 'raa_application_field_uq');
            $table->index('form_version_id', 'raa_form_version_idx');

            $table->foreign('application_id', 'raa_application_fk')
                ->references('id')->on('recruitment_applications')->cascadeOnDelete();
            $table->foreign('form_version_id', 'raa_form_version_fk')
                ->references('id')->on('application_form_versions')->restrictOnDelete();
        });

        Schema::create('recruitment_applicant_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('applicant_id');
            $table->foreignId('application_id')->nullable();
            $table->string('file_type', 60);
            $table->string('scan_status', 60)->default('pending');
            $table->dateTime('uploaded_at');
            $table->timestamps();

            $table->index(['applicant_id', 'file_type'], 'raf_applicant_type_idx');
            $table->index('application_id', 'raf_application_idx');
            $table->index('scan_status', 'raf_scan_status_idx');

            $table->foreign('applicant_id', 'raf_applicant_fk')
                ->references('id')->on('recruitment_applicants')->restrictOnDelete();
            $table->foreign('application_id', 'raf_application_fk')
                ->references('id')->on('recruitment_applications')->nullOnDelete();
        });

        Schema::create('recruitment_tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 191);
            $table->string('color', 20)->nullable();
            $table->string('scope_key');
            $table->foreignId('created_by_user_id');
            $table->timestamps();

            $table->unique(['scope_key', 'name'], 'rt_scope_name_uq');
            $table->index('created_by_user_id', 'rt_creator_idx');

            $table->foreign('created_by_user_id', 'rt_creator_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('recruitment_application_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id');
            $table->foreignId('tag_id');
            $table->foreignId('created_by_user_id');
            $table->timestamps();

            $table->unique(['application_id', 'tag_id'], 'rat_application_tag_uq');
            $table->index('tag_id', 'rat_tag_idx');
            $table->index('created_by_user_id', 'rat_creator_idx');

            $table->foreign('application_id', 'rat_application_fk')
                ->references('id')->on('recruitment_applications')->cascadeOnDelete();
            $table->foreign('tag_id', 'rat_tag_fk')
                ->references('id')->on('recruitment_tags')->cascadeOnDelete();
            $table->foreign('created_by_user_id', 'rat_creator_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('recruitment_applicant_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('applicant_id');
            $table->foreignId('application_id')->nullable();
            $table->foreignId('user_id');
            $table->string('note_type');
            $table->text('body');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['applicant_id', 'created_at'], 'ran_applicant_created_idx');
            $table->index(['application_id', 'created_at'], 'ran_application_created_idx');
            $table->index('user_id', 'ran_user_idx');

            $table->foreign('applicant_id', 'ran_applicant_fk')
                ->references('id')->on('recruitment_applicants')->cascadeOnDelete();
            $table->foreign('application_id', 'ran_application_fk')
                ->references('id')->on('recruitment_applications')->nullOnDelete();
            $table->foreign('user_id', 'ran_user_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('recruitment_application_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id');
            $table->string('from_status', 60);
            $table->string('to_status', 60);
            $table->string('reason_code', 60);
            $table->text('reason_text')->nullable();
            $table->foreignId('decided_by_user_id');
            $table->dateTime('decided_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['application_id', 'decided_at'], 'rad_application_decided_idx');
            $table->index('decided_by_user_id', 'rad_decider_idx');
            $table->index('from_status', 'rad_from_status_idx');
            $table->index('to_status', 'rad_to_status_idx');

            $table->foreign('application_id', 'rad_application_fk')
                ->references('id')->on('recruitment_applications')->cascadeOnDelete();
            $table->foreign('decided_by_user_id', 'rad_decider_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_application_decisions');
        Schema::dropIfExists('recruitment_applicant_notes');
        Schema::dropIfExists('recruitment_application_tags');
        Schema::dropIfExists('recruitment_tags');
        Schema::dropIfExists('recruitment_applicant_files');
        Schema::dropIfExists('recruitment_application_answers');
        Schema::dropIfExists('recruitment_applications');
        Schema::dropIfExists('recruitment_applicants');
    }
};
