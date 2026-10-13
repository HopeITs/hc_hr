<?php

namespace Tests\Feature\Recruitment;

use App\Models\ApplicationForm;
use App\Models\ApplicationFormVersion;
use App\Models\HiringCampaign;
use App\Models\JobPost;
use App\Models\JobPostVersion;
use App\Models\PublicRecruitmentLink;
use App\Models\RecruitmentApplicant;
use App\Models\RecruitmentApplicantFile;
use App\Models\RecruitmentApplicantNote;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentApplicationAnswer;
use App\Models\RecruitmentApplicationDecision;
use App\Models\RecruitmentApplicationTag;
use App\Models\RecruitmentCampaignInvite;
use App\Models\RecruitmentHiringConversion;
use App\Models\RecruitmentInterview;
use App\Models\RecruitmentInterviewEvaluation;
use App\Models\RecruitmentInterviewParticipant;
use App\Models\RecruitmentJobOffer;
use App\Models\RecruitmentJobOfferVersion;
use App\Models\RecruitmentRequest;
use App\Models\RecruitmentRequestComment;
use App\Models\RecruitmentRequestEvent;
use App\Models\RecruitmentRequestExternalConfirmation;
use App\Models\RecruitmentRequestPositionDetail;
use App\Models\RecruitmentRequestVersion;
use App\Models\RecruitmentTag;
use App\Models\RecruitmentWorkflowDefinition;
use App\Models\RecruitmentWorkflowDefinitionVersion;
use App\Models\RecruitmentWorkflowInstance;
use App\Models\RecruitmentWorkflowInstanceStep;
use App\Models\RecruitmentWorkflowStep;
use App\Models\Vacancy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\MediaLibrary\HasMedia;
use Tests\TestCase;

class RecruitmentModelLayerTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_recruitment_table_has_a_matching_model(): void
    {
        foreach ($this->tableModels() as $table => $modelClass) {
            $this->assertTrue(Schema::hasTable($table), "Missing recruitment table [{$table}].");
            $this->assertSame($table, (new $modelClass)->getTable(), "Incorrect table mapping for [{$modelClass}].");
        }

        $this->assertCount(33, $this->tableModels());
    }

    public function test_the_complete_model_graph_exposes_the_expected_relationships(): void
    {
        foreach ($this->relationships() as [$modelClass, $method, $relationClass, $relatedClass]) {
            $relation = (new $modelClass)->{$method}();

            $this->assertInstanceOf($relationClass, $relation, "Unexpected relation type for {$modelClass}::{$method}().");
            $this->assertInstanceOf($relatedClass, $relation->getRelated(), "Unexpected related model for {$modelClass}::{$method}().");
        }
    }

    public function test_json_dates_counters_and_sensitive_hashes_are_configured(): void
    {
        $this->assertSame('array', (new RecruitmentRequestVersion)->getCasts()['snapshot_json']);
        $this->assertSame('array', (new RecruitmentWorkflowDefinitionVersion)->getCasts()['rules_json']);
        $this->assertSame('array', (new JobPostVersion)->getCasts()['public_payload_json']);
        $this->assertSame('array', (new ApplicationFormVersion)->getCasts()['fields_json']);
        $this->assertSame('array', (new RecruitmentApplication)->getCasts()['consent_payload_json']);
        $this->assertSame('array', (new RecruitmentApplicationAnswer)->getCasts()['answer_json']);
        $this->assertSame('array', (new RecruitmentJobOfferVersion)->getCasts()['terms_json']);
        $this->assertSame('array', (new RecruitmentHiringConversion)->getCasts()['employee_payload_json']);

        $this->assertSame('datetime', (new RecruitmentApplication)->getCasts()['submitted_at']);
        $this->assertSame('datetime', (new RecruitmentInterview)->getCasts()['scheduled_at']);
        $this->assertSame('integer', (new Vacancy)->getCasts()['needed_count']);
        $this->assertSame('integer', (new RecruitmentHiringConversion)->getCasts()['attempt_count']);

        $this->assertContains('identity_key_hash', (new RecruitmentApplicant)->getHidden());
        $this->assertContains('submission_key_hash', (new RecruitmentApplication)->getHidden());
        $this->assertContains('token_hash', (new PublicRecruitmentLink)->getHidden());

        $this->assertNull((new RecruitmentRequestComment)->getUpdatedAtColumn());
        $this->assertNull((new RecruitmentApplicantNote)->getUpdatedAtColumn());
        $this->assertNull((new RecruitmentApplicationDecision)->getUpdatedAtColumn());
        $this->assertInstanceOf(HasMedia::class, new RecruitmentApplicantFile);
    }

    /** @return array<string, class-string> */
    private function tableModels(): array
    {
        return [
            'recruitment_requests' => RecruitmentRequest::class,
            'recruitment_request_versions' => RecruitmentRequestVersion::class,
            'recruitment_request_position_details' => RecruitmentRequestPositionDetail::class,
            'recruitment_request_comments' => RecruitmentRequestComment::class,
            'recruitment_request_external_confirmations' => RecruitmentRequestExternalConfirmation::class,
            'recruitment_workflow_definitions' => RecruitmentWorkflowDefinition::class,
            'recruitment_workflow_definition_versions' => RecruitmentWorkflowDefinitionVersion::class,
            'recruitment_workflow_steps' => RecruitmentWorkflowStep::class,
            'recruitment_workflow_instances' => RecruitmentWorkflowInstance::class,
            'recruitment_workflow_instance_steps' => RecruitmentWorkflowInstanceStep::class,
            'recruitment_request_events' => RecruitmentRequestEvent::class,
            'vacancies' => Vacancy::class,
            'hiring_campaigns' => HiringCampaign::class,
            'job_posts' => JobPost::class,
            'job_post_versions' => JobPostVersion::class,
            'application_forms' => ApplicationForm::class,
            'application_form_versions' => ApplicationFormVersion::class,
            'public_recruitment_links' => PublicRecruitmentLink::class,
            'recruitment_campaign_invites' => RecruitmentCampaignInvite::class,
            'recruitment_applicants' => RecruitmentApplicant::class,
            'recruitment_applications' => RecruitmentApplication::class,
            'recruitment_application_answers' => RecruitmentApplicationAnswer::class,
            'recruitment_applicant_files' => RecruitmentApplicantFile::class,
            'recruitment_tags' => RecruitmentTag::class,
            'recruitment_application_tags' => RecruitmentApplicationTag::class,
            'recruitment_applicant_notes' => RecruitmentApplicantNote::class,
            'recruitment_application_decisions' => RecruitmentApplicationDecision::class,
            'recruitment_interviews' => RecruitmentInterview::class,
            'recruitment_interview_participants' => RecruitmentInterviewParticipant::class,
            'recruitment_interview_evaluations' => RecruitmentInterviewEvaluation::class,
            'recruitment_job_offers' => RecruitmentJobOffer::class,
            'recruitment_job_offer_versions' => RecruitmentJobOfferVersion::class,
            'recruitment_hiring_conversions' => RecruitmentHiringConversion::class,
        ];
    }

    /** @return array<int, array{class-string, string, class-string, class-string}> */
    private function relationships(): array
    {
        return [
            [RecruitmentRequest::class, 'versions', HasMany::class, RecruitmentRequestVersion::class],
            [RecruitmentRequest::class, 'comments', HasMany::class, RecruitmentRequestComment::class],
            [RecruitmentRequest::class, 'externalConfirmations', HasMany::class, RecruitmentRequestExternalConfirmation::class],
            [RecruitmentRequest::class, 'workflowInstances', HasMany::class, RecruitmentWorkflowInstance::class],
            [RecruitmentRequest::class, 'events', HasMany::class, RecruitmentRequestEvent::class],
            [RecruitmentRequest::class, 'vacancy', HasOne::class, Vacancy::class],
            [RecruitmentRequestVersion::class, 'positionDetail', HasOne::class, RecruitmentRequestPositionDetail::class],
            [RecruitmentRequestVersion::class, 'workflowInstance', HasOne::class, RecruitmentWorkflowInstance::class],
            [RecruitmentRequestComment::class, 'request', BelongsTo::class, RecruitmentRequest::class],
            [RecruitmentRequestExternalConfirmation::class, 'request', BelongsTo::class, RecruitmentRequest::class],
            [RecruitmentWorkflowDefinition::class, 'versions', HasMany::class, RecruitmentWorkflowDefinitionVersion::class],
            [RecruitmentWorkflowDefinition::class, 'latestVersion', HasOne::class, RecruitmentWorkflowDefinitionVersion::class],
            [RecruitmentWorkflowDefinitionVersion::class, 'steps', HasMany::class, RecruitmentWorkflowStep::class],
            [RecruitmentWorkflowDefinitionVersion::class, 'workflowInstances', HasMany::class, RecruitmentWorkflowInstance::class],
            [RecruitmentWorkflowStep::class, 'instanceSteps', HasMany::class, RecruitmentWorkflowInstanceStep::class],
            [RecruitmentWorkflowInstance::class, 'definitionVersion', BelongsTo::class, RecruitmentWorkflowDefinitionVersion::class],
            [RecruitmentWorkflowInstance::class, 'steps', HasMany::class, RecruitmentWorkflowInstanceStep::class],
            [RecruitmentWorkflowInstanceStep::class, 'workflowStep', BelongsTo::class, RecruitmentWorkflowStep::class],
            [RecruitmentRequestEvent::class, 'workflowInstance', BelongsTo::class, RecruitmentWorkflowInstance::class],
            [Vacancy::class, 'campaigns', HasMany::class, HiringCampaign::class],
            [Vacancy::class, 'applications', HasMany::class, RecruitmentApplication::class],
            [HiringCampaign::class, 'jobPost', HasOne::class, JobPost::class],
            [HiringCampaign::class, 'applicationForm', HasOne::class, ApplicationForm::class],
            [HiringCampaign::class, 'invites', HasMany::class, RecruitmentCampaignInvite::class],
            [HiringCampaign::class, 'applications', HasMany::class, RecruitmentApplication::class],
            [JobPost::class, 'versions', HasMany::class, JobPostVersion::class],
            [JobPost::class, 'currentVersion', BelongsTo::class, JobPostVersion::class],
            [JobPostVersion::class, 'publicLinks', HasMany::class, PublicRecruitmentLink::class],
            [ApplicationForm::class, 'versions', HasMany::class, ApplicationFormVersion::class],
            [ApplicationForm::class, 'currentVersion', BelongsTo::class, ApplicationFormVersion::class],
            [ApplicationFormVersion::class, 'applications', HasMany::class, RecruitmentApplication::class],
            [PublicRecruitmentLink::class, 'applications', HasMany::class, RecruitmentApplication::class],
            [RecruitmentCampaignInvite::class, 'application', HasOne::class, RecruitmentApplication::class],
            [RecruitmentApplicant::class, 'applications', HasMany::class, RecruitmentApplication::class],
            [RecruitmentApplicant::class, 'files', HasMany::class, RecruitmentApplicantFile::class],
            [RecruitmentApplication::class, 'applicant', BelongsTo::class, RecruitmentApplicant::class],
            [RecruitmentApplication::class, 'answers', HasMany::class, RecruitmentApplicationAnswer::class],
            [RecruitmentApplication::class, 'tags', BelongsToMany::class, RecruitmentTag::class],
            [RecruitmentApplication::class, 'interviews', HasMany::class, RecruitmentInterview::class],
            [RecruitmentApplication::class, 'jobOffer', HasOne::class, RecruitmentJobOffer::class],
            [RecruitmentApplicationAnswer::class, 'formVersion', BelongsTo::class, ApplicationFormVersion::class],
            [RecruitmentApplicantFile::class, 'application', BelongsTo::class, RecruitmentApplication::class],
            [RecruitmentTag::class, 'applications', BelongsToMany::class, RecruitmentApplication::class],
            [RecruitmentApplicationTag::class, 'tag', BelongsTo::class, RecruitmentTag::class],
            [RecruitmentApplicantNote::class, 'applicant', BelongsTo::class, RecruitmentApplicant::class],
            [RecruitmentApplicationDecision::class, 'application', BelongsTo::class, RecruitmentApplication::class],
            [RecruitmentInterview::class, 'participants', HasMany::class, RecruitmentInterviewParticipant::class],
            [RecruitmentInterview::class, 'evaluations', HasMany::class, RecruitmentInterviewEvaluation::class],
            [RecruitmentInterviewParticipant::class, 'interview', BelongsTo::class, RecruitmentInterview::class],
            [RecruitmentInterviewEvaluation::class, 'interview', BelongsTo::class, RecruitmentInterview::class],
            [RecruitmentJobOffer::class, 'versions', HasMany::class, RecruitmentJobOfferVersion::class],
            [RecruitmentJobOffer::class, 'currentVersion', BelongsTo::class, RecruitmentJobOfferVersion::class],
            [RecruitmentJobOffer::class, 'hiringConversion', HasOne::class, RecruitmentHiringConversion::class],
            [RecruitmentJobOfferVersion::class, 'hiringConversions', HasMany::class, RecruitmentHiringConversion::class],
            [RecruitmentHiringConversion::class, 'employee', BelongsTo::class, \App\Models\Employee::class],
            [RecruitmentHiringConversion::class, 'contract', BelongsTo::class, \App\Models\Contract::class],
        ];
    }
}
