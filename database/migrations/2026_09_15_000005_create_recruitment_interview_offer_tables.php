<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_interviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id');
            $table->string('interview_type', 60);
            $table->string('status', 60)->default('scheduled');
            $table->dateTime('scheduled_at');
            $table->string('location_type', 60);
            $table->string('location_value', 191)->nullable();
            $table->foreignId('created_by_user_id');
            $table->timestamps();

            $table->index(['application_id', 'scheduled_at'], 'ri_application_scheduled_idx');
            $table->index(['status', 'scheduled_at'], 'ri_status_scheduled_idx');
            $table->index('scheduled_at', 'ri_scheduled_idx');
            $table->index('created_by_user_id', 'ri_creator_idx');

            $table->foreign('application_id', 'ri_application_fk')
                ->references('id')->on('recruitment_applications')->restrictOnDelete();
            $table->foreign('created_by_user_id', 'ri_creator_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('recruitment_interview_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('interview_id');
            $table->foreignId('user_id');
            $table->string('role', 60);
            $table->string('notification_status', 60)->default('pending');
            $table->timestamps();

            $table->unique(['interview_id', 'user_id'], 'rip_interview_user_uq');
            $table->index(['user_id', 'notification_status'], 'rip_user_notification_idx');

            $table->foreign('interview_id', 'rip_interview_fk')
                ->references('id')->on('recruitment_interviews')->cascadeOnDelete();
            $table->foreign('user_id', 'rip_user_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('recruitment_interview_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('interview_id');
            $table->foreignId('reviewer_user_id');
            $table->text('pros')->nullable();
            $table->text('cons')->nullable();
            $table->text('notes')->nullable();
            $table->string('recommendation');
            $table->dateTime('submitted_at');
            $table->timestamps();

            $table->unique(['interview_id', 'reviewer_user_id'], 'rie_interview_reviewer_uq');
            $table->index('reviewer_user_id', 'rie_reviewer_idx');
            $table->index('submitted_at', 'rie_submitted_idx');

            $table->foreign('interview_id', 'rie_interview_fk')
                ->references('id')->on('recruitment_interviews')->cascadeOnDelete();
            $table->foreign('reviewer_user_id', 'rie_reviewer_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('recruitment_job_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id');
            $table->foreignId('vacancy_id');
            $table->foreignId('position_id');
            $table->string('status', 60)->default('draft');
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->foreignId('created_by_user_id');
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('accepted_at')->nullable();
            $table->dateTime('rejected_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();

            $table->unique('application_id', 'rjo_application_uq');
            $table->unique('current_version_id', 'rjo_current_version_uq');
            $table->index(['vacancy_id', 'status'], 'rjo_vacancy_status_idx');
            $table->index('position_id', 'rjo_position_idx');
            $table->index('created_by_user_id', 'rjo_creator_idx');
            $table->index('status', 'rjo_status_idx');
            $table->index('expires_at', 'rjo_expires_idx');

            $table->foreign('application_id', 'rjo_application_fk')
                ->references('id')->on('recruitment_applications')->restrictOnDelete();
            $table->foreign('vacancy_id', 'rjo_vacancy_fk')
                ->references('id')->on('vacancies')->restrictOnDelete();
            $table->foreign('position_id', 'rjo_position_fk')
                ->references('id')->on('positions')->restrictOnDelete();
            $table->foreign('created_by_user_id', 'rjo_creator_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('recruitment_job_offer_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_offer_id');
            $table->unsignedInteger('version_no');
            $table->foreignId('contract_type_id')->nullable();
            $table->integer('salary_amount')->nullable();
            $table->unsignedTinyInteger('currency')->nullable();
            $table->unsignedTinyInteger('salary_per')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->integer('weekly_hours')->nullable();
            $table->unsignedInteger('probation_days')->nullable();
            $table->json('terms_json')->nullable();
            $table->string('status', 60)->default('draft');
            $table->foreignId('created_by_user_id');
            $table->dateTime('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['job_offer_id', 'version_no'], 'rjov_offer_version_uq');
            $table->index(['job_offer_id', 'status'], 'rjov_offer_status_idx');
            $table->index('contract_type_id', 'rjov_contract_type_idx');
            $table->index('created_by_user_id', 'rjov_creator_idx');
            $table->index('status', 'rjov_status_idx');

            $table->foreign('job_offer_id', 'rjov_job_offer_fk')
                ->references('id')->on('recruitment_job_offers')->cascadeOnDelete();
            $table->foreign('contract_type_id', 'rjov_contract_type_fk')
                ->references('id')->on('contract_types')->restrictOnDelete();
            $table->foreign('created_by_user_id', 'rjov_creator_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('recruitment_hiring_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_offer_id');
            $table->foreignId('job_offer_version_id');
            $table->foreignId('applicant_id');
            $table->foreignId('employee_id')->nullable();
            $table->foreignId('contract_id')->nullable();
            $table->json('employee_payload_json');
            $table->string('status', 60)->default('pending');
            $table->text('failure_reason')->nullable();
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->dateTime('started_at');
            $table->foreignId('converted_by_user_id');
            $table->dateTime('converted_at')->nullable();
            $table->timestamps();

            $table->unique('job_offer_id', 'rhc_job_offer_uq');
            $table->unique('contract_id', 'rhc_contract_uq');
            $table->index('job_offer_version_id', 'rhc_offer_version_idx');
            $table->index('applicant_id', 'rhc_applicant_idx');
            $table->index('employee_id', 'rhc_employee_idx');
            $table->index('converted_by_user_id', 'rhc_converter_idx');
            $table->index(['status', 'started_at'], 'rhc_status_started_idx');

            $table->foreign('job_offer_id', 'rhc_job_offer_fk')
                ->references('id')->on('recruitment_job_offers')->restrictOnDelete();
            $table->foreign('job_offer_version_id', 'rhc_offer_version_fk')
                ->references('id')->on('recruitment_job_offer_versions')->restrictOnDelete();
            $table->foreign('applicant_id', 'rhc_applicant_fk')
                ->references('id')->on('recruitment_applicants')->restrictOnDelete();
            $table->foreign('employee_id', 'rhc_employee_fk')
                ->references('id')->on('employees')->restrictOnDelete();
            $table->foreign('contract_id', 'rhc_contract_fk')
                ->references('id')->on('contracts')->restrictOnDelete();
            $table->foreign('converted_by_user_id', 'rhc_converter_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_hiring_conversions');
        Schema::dropIfExists('recruitment_job_offer_versions');
        Schema::dropIfExists('recruitment_job_offers');
        Schema::dropIfExists('recruitment_interview_evaluations');
        Schema::dropIfExists('recruitment_interview_participants');
        Schema::dropIfExists('recruitment_interviews');
    }
};
