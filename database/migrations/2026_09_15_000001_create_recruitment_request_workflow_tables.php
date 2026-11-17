<?php

use App\Enums\RecruitmentCommentType;
use App\Enums\RecruitmentRequestMode;
use App\Enums\RecruitmentRequestStatus;
use App\Enums\WorkflowInstanceStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_number', 50);
            $table->foreignId('requester_user_id');
            $table->unsignedTinyInteger('request_type');
            $table->unsignedTinyInteger('mode')->default(RecruitmentRequestMode::Normal);
            $table->unsignedTinyInteger('status')->default(RecruitmentRequestStatus::Draft);
            $table->foreignId('scope_city_id')->nullable();
            $table->foreignId('target_position_id')->nullable();
            $table->unsignedBigInteger('target_vacancy_id')->nullable();
            $table->json('draft_payload_json')->nullable();
            $table->string('actual_requester_name', 191)->nullable();
            $table->foreignId('actual_requester_position_id')->nullable();
            $table->text('direct_open_reason')->nullable();
            $table->foreignId('direct_opened_by_user_id')->nullable();
            $table->dateTime('direct_opened_at')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('applied_at')->nullable();
            $table->timestamps();

            $table->unique('request_number', 'rr_request_number_uq');
            $table->index(['requester_user_id', 'status'], 'rr_requester_status_idx');
            $table->index(['scope_city_id', 'status'], 'rr_scope_status_idx');
            $table->index(['target_position_id', 'status'], 'rr_position_status_idx');
            $table->index(['target_vacancy_id', 'status'], 'rr_vacancy_status_idx');
            $table->index('request_type', 'rr_request_type_idx');
            $table->index('status', 'rr_status_idx');
            $table->index('submitted_at', 'rr_submitted_idx');
            $table->index('actual_requester_position_id', 'rr_actual_position_idx');
            $table->index('direct_opened_by_user_id', 'rr_direct_opener_idx');

            $table->foreign('requester_user_id', 'rr_requester_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('scope_city_id', 'rr_scope_city_fk')
                ->references('id')->on('cities')->restrictOnDelete();
            $table->foreign('target_position_id', 'rr_target_position_fk')
                ->references('id')->on('positions')->restrictOnDelete();
            $table->foreign('actual_requester_position_id', 'rr_actual_position_fk')
                ->references('id')->on('positions')->nullOnDelete();
            $table->foreign('direct_opened_by_user_id', 'rr_direct_opener_fk')
                ->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('recruitment_request_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id');
            $table->unsignedInteger('version_no');
            $table->json('snapshot_json');
            $table->foreignId('created_by_user_id');
            $table->dateTime('submitted_at');
            $table->timestamps();

            $table->unique(['request_id', 'version_no'], 'rrv_request_version_uq');
            $table->index('created_by_user_id', 'rrv_creator_idx');
            $table->index('submitted_at', 'rrv_submitted_idx');

            $table->foreign('request_id', 'rrv_request_fk')
                ->references('id')->on('recruitment_requests')->restrictOnDelete();
            $table->foreign('created_by_user_id', 'rrv_creator_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('recruitment_request_position_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_version_id');
            $table->foreignId('position_id')->nullable();
            $table->foreignId('program_id')->nullable();
            $table->foreignId('center_id')->nullable();
            $table->foreignId('department_id')->nullable();
            $table->foreignId('job_level_id')->nullable();
            $table->foreignId('contract_type_id')->nullable();
            $table->foreignId('direct_supervisor_position_id')->nullable();
            $table->integer('current_headcount')->nullable();
            $table->integer('requested_headcount')->nullable();
            $table->text('recruitment_reason')->nullable();
            $table->text('other_reason')->nullable();
            $table->string('role_title_en')->nullable();
            $table->string('role_title_ar')->nullable();
            $table->date('volunteer_period_start_date')->nullable();
            $table->date('volunteer_period_end_date')->nullable();
            $table->date('expected_start_date')->nullable();
            $table->integer('weekly_hours')->nullable();
            $table->string('priority', 60)->nullable();
            $table->string('volunteer_type', 60)->nullable();
            $table->integer('salary_amount')->nullable();
            $table->unsignedTinyInteger('currency')->nullable();
            $table->unsignedTinyInteger('salary_per')->nullable();
            $table->text('main_duties')->nullable();
            $table->text('required_qualifications')->nullable();
            $table->integer('required_experience_years')->nullable();
            $table->text('required_skills')->nullable();
            $table->text('detailed_explanation')->nullable();
            $table->boolean('role_description_ready')->default(false);
            $table->boolean('finance_consulted')->default(false);
            $table->boolean('org_chart_updated')->default(false);
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->unique('request_version_id', 'rrpd_request_version_uq');
            $table->index('position_id', 'rrpd_position_idx');
            $table->index('program_id', 'rrpd_program_idx');
            $table->index('center_id', 'rrpd_center_idx');
            $table->index('department_id', 'rrpd_department_idx');
            $table->index('job_level_id', 'rrpd_job_level_idx');
            $table->index('contract_type_id', 'rrpd_contract_type_idx');
            $table->index('direct_supervisor_position_id', 'rrpd_supervisor_idx');

            $table->foreign('request_version_id', 'rrpd_request_version_fk')
                ->references('id')->on('recruitment_request_versions')->cascadeOnDelete();
            $table->foreign('position_id', 'rrpd_position_fk')
                ->references('id')->on('positions')->restrictOnDelete();
            $table->foreign('program_id', 'rrpd_program_fk')
                ->references('id')->on('programs')->restrictOnDelete();
            $table->foreign('center_id', 'rrpd_center_fk')
                ->references('id')->on('centers')->restrictOnDelete();
            $table->foreign('department_id', 'rrpd_department_fk')
                ->references('id')->on('departments')->restrictOnDelete();
            $table->foreign('job_level_id', 'rrpd_job_level_fk')
                ->references('id')->on('job_levels')->restrictOnDelete();
            $table->foreign('contract_type_id', 'rrpd_contract_type_fk')
                ->references('id')->on('contract_types')->restrictOnDelete();
            $table->foreign('direct_supervisor_position_id', 'rrpd_supervisor_fk')
                ->references('id')->on('positions')->restrictOnDelete();
        });

        Schema::create('recruitment_request_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id');
            $table->foreignId('request_version_id')->nullable();
            $table->foreignId('user_id');
            $table->unsignedTinyInteger('comment_type')->default(RecruitmentCommentType::General);
            $table->text('body');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['request_id', 'created_at'], 'rrc_request_created_idx');
            $table->index(['request_version_id', 'created_at'], 'rrc_version_created_idx');
            $table->index('user_id', 'rrc_user_idx');

            $table->foreign('request_id', 'rrc_request_fk')
                ->references('id')->on('recruitment_requests')->restrictOnDelete();
            $table->foreign('request_version_id', 'rrc_version_fk')
                ->references('id')->on('recruitment_request_versions')->nullOnDelete();
            $table->foreign('user_id', 'rrc_user_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('recruitment_request_external_confirmations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recruitment_request_id');
            $table->unsignedTinyInteger('step_key')->nullable();
            $table->boolean('is_generated')->default(false);
            $table->string('confirmer_name', 191)->nullable();
            $table->foreignId('confirmer_user_id')->nullable();
            $table->foreignId('confirmer_position_id')->nullable();
            $table->unsignedTinyInteger('decision')->nullable();
            $table->unsignedTinyInteger('method')->nullable();
            $table->dateTime('confirmed_at')->nullable();
            $table->text('not_applicable_reason')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by_user_id');
            $table->timestamps();

            $table->unique(
                ['recruitment_request_id', 'step_key'],
                'rrec_request_step_uq',
            );
            $table->index(
                ['recruitment_request_id', 'created_at'],
                'rrec_request_created_idx',
            );
            $table->index('confirmer_user_id', 'rrec_confirmer_user_idx');
            $table->index('confirmer_position_id', 'rrec_confirmer_position_idx');
            $table->index('recorded_by_user_id', 'rrec_recorder_idx');
            $table->index('decision', 'rrec_decision_idx');

            $table->foreign('recruitment_request_id', 'rrec_request_fk')
                ->references('id')->on('recruitment_requests')->restrictOnDelete();
            $table->foreign('confirmer_user_id', 'rrec_confirmer_user_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('confirmer_position_id', 'rrec_confirmer_position_fk')
                ->references('id')->on('positions')->nullOnDelete();
            $table->foreign('recorded_by_user_id', 'rrec_recorder_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('recruitment_workflow_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 191);
            $table->unsignedTinyInteger('request_type');
            $table->boolean('is_active')->default(false);
            $table->foreignId('created_by_user_id');
            $table->timestamps();

            $table->index(['request_type', 'is_active'], 'rwd_type_active_idx');
            $table->index('created_by_user_id', 'rwd_creator_idx');

            $table->foreign('created_by_user_id', 'rwd_creator_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('recruitment_workflow_definition_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('definition_id');
            $table->unsignedInteger('version_no');
            $table->json('rules_json');
            $table->boolean('is_active')->default(false);
            $table->dateTime('published_at')->nullable();
            $table->timestamps();

            $table->unique(['definition_id', 'version_no'], 'rwdv_definition_version_uq');
            $table->index(['definition_id', 'is_active'], 'rwdv_definition_active_idx');
            $table->index('published_at', 'rwdv_published_idx');

            $table->foreign('definition_id', 'rwdv_definition_fk')
                ->references('id')->on('recruitment_workflow_definitions')->restrictOnDelete();
        });

        Schema::create('recruitment_workflow_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('definition_version_id');
            $table->unsignedInteger('step_order');
            $table->unsignedTinyInteger('step_key');
            $table->string('role_key', 60);
            $table->unsignedTinyInteger('scope_rule');
            $table->json('required_condition_json')->nullable();
            $table->timestamps();

            $table->unique(['definition_version_id', 'step_order'], 'rws_version_order_uq');
            $table->unique(['definition_version_id', 'step_key'], 'rws_version_key_uq');

            $table->foreign('definition_version_id', 'rws_definition_version_fk')
                ->references('id')->on('recruitment_workflow_definition_versions')->cascadeOnDelete();
        });

        Schema::create('recruitment_workflow_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id');
            $table->foreignId('request_version_id');
            $table->foreignId('definition_version_id');
            $table->unsignedTinyInteger('status')->default(WorkflowInstanceStatus::Running);
            $table->unsignedInteger('current_step_order')->default(1);
            $table->dateTime('started_at');
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->unique('request_version_id', 'rwi_request_version_uq');
            $table->index(['request_id', 'status'], 'rwi_request_status_idx');
            $table->index('definition_version_id', 'rwi_definition_version_idx');
            $table->index('status', 'rwi_status_idx');

            $table->foreign('request_id', 'rwi_request_fk')
                ->references('id')->on('recruitment_requests')->restrictOnDelete();
            $table->foreign('request_version_id', 'rwi_request_version_fk')
                ->references('id')->on('recruitment_request_versions')->restrictOnDelete();
            $table->foreign('definition_version_id', 'rwi_definition_version_fk')
                ->references('id')->on('recruitment_workflow_definition_versions')->restrictOnDelete();
        });

        Schema::create('recruitment_workflow_instance_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_instance_id');
            $table->foreignId('workflow_step_id');
            $table->string('assigned_role', 60);
            $table->unsignedTinyInteger('assigned_scope_type')->nullable();
            $table->unsignedBigInteger('assigned_scope_id')->nullable();
            $table->foreignId('assigned_user_id')->nullable();
            $table->foreignId('acted_by_user_id')->nullable();
            $table->unsignedTinyInteger('decision')->nullable();
            $table->text('decision_reason')->nullable();
            $table->dateTime('acted_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['workflow_instance_id', 'workflow_step_id'],
                'rwis_instance_step_uq',
            );
            $table->index('workflow_step_id', 'rwis_workflow_step_idx');
            $table->index(['assigned_user_id', 'decision'], 'rwis_assignee_decision_idx');
            $table->index(['assigned_scope_type', 'assigned_scope_id'], 'rwis_scope_idx');
            $table->index('assigned_scope_id', 'rwis_scope_id_idx');
            $table->index('decision', 'rwis_decision_idx');
            $table->index('acted_by_user_id', 'rwis_actor_idx');

            $table->foreign('workflow_instance_id', 'rwis_instance_fk')
                ->references('id')->on('recruitment_workflow_instances')->cascadeOnDelete();
            $table->foreign('workflow_step_id', 'rwis_workflow_step_fk')
                ->references('id')->on('recruitment_workflow_steps')->restrictOnDelete();
            $table->foreign('assigned_user_id', 'rwis_assignee_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('acted_by_user_id', 'rwis_actor_fk')
                ->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('recruitment_request_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recruitment_request_id');
            $table->foreignId('request_version_id')->nullable();
            $table->unsignedTinyInteger('event_type');
            $table->foreignId('workflow_instance_id')->nullable();
            $table->foreignId('workflow_step_id')->nullable();
            $table->unsignedTinyInteger('from_status')->nullable();
            $table->unsignedTinyInteger('to_status')->nullable();
            $table->unsignedTinyInteger('decision')->nullable();
            $table->foreignId('actor_user_id')->nullable();
            $table->string('actor_role', 60);
            $table->unsignedTinyInteger('scope_type')->nullable();
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->text('comment')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(
                ['recruitment_request_id', 'created_at'],
                'rre_request_created_idx',
            );
            $table->index('request_version_id', 'rre_request_version_idx');
            $table->index('event_type', 'rre_event_type_idx');
            $table->index('workflow_instance_id', 'rre_workflow_instance_idx');
            $table->index('workflow_step_id', 'rre_workflow_step_idx');
            $table->index('from_status', 'rre_from_status_idx');
            $table->index('to_status', 'rre_to_status_idx');
            $table->index('decision', 'rre_decision_idx');
            $table->index(['scope_type', 'scope_id'], 'rre_scope_idx');
            $table->index('scope_id', 'rre_scope_id_idx');
            $table->index('actor_user_id', 'rre_actor_idx');

            $table->foreign('recruitment_request_id', 'rre_request_fk')
                ->references('id')->on('recruitment_requests')->restrictOnDelete();
            $table->foreign('request_version_id', 'rre_request_version_fk')
                ->references('id')->on('recruitment_request_versions')->nullOnDelete();
            $table->foreign('workflow_instance_id', 'rre_workflow_instance_fk')
                ->references('id')->on('recruitment_workflow_instances')->nullOnDelete();
            $table->foreign('workflow_step_id', 'rre_workflow_step_fk')
                ->references('id')->on('recruitment_workflow_steps')->nullOnDelete();
            $table->foreign('actor_user_id', 'rre_actor_fk')
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_request_events');
        Schema::dropIfExists('recruitment_workflow_instance_steps');
        Schema::dropIfExists('recruitment_workflow_instances');
        Schema::dropIfExists('recruitment_workflow_steps');
        Schema::dropIfExists('recruitment_workflow_definition_versions');
        Schema::dropIfExists('recruitment_workflow_definitions');
        Schema::dropIfExists('recruitment_request_external_confirmations');
        Schema::dropIfExists('recruitment_request_comments');
        Schema::dropIfExists('recruitment_request_position_details');
        Schema::dropIfExists('recruitment_request_versions');
        Schema::dropIfExists('recruitment_requests');
    }
};
