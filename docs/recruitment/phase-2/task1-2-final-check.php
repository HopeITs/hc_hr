<?php

/*
 * Phase 2 Tasks 1 and 2 — requirements check on the real MySQL database,
 * through Team 1's real submission path (PublicRecruitmentApplicationService,
 * behind POST /careers/{token}/applications). Every line of the spec gets a
 * PASS / FAIL. Everything runs in one transaction that is ROLLED BACK; mail is
 * faked and the queue runs inline, so nothing is sent and nothing is left.
 *
 *   C:/laragon/bin/php/php-8.5.11/php.exe artisan tinker --execute="require 'docs/recruitment/phase-2/task1-2-final-check.php';"
 */

use App\Enums\HiringCampaignStatus;
use App\Enums\RecruitmentApplicationStatus as Status;
use App\Enums\RecruitmentCampaignLinkMode as Mode;
use App\Exceptions\PublicRecruitmentSubmissionException;
use App\Mail\RecruitmentResponseReceiptMail;
use App\Models\ApplicationForm;
use App\Models\ApplicationFormVersion;
use App\Models\HiringCampaign;
use App\Models\JobPost;
use App\Models\JobPostVersion;
use App\Models\Position;
use App\Models\PublicRecruitmentLink;
use App\Models\RecruitmentApplicant;
use App\Models\RecruitmentApplicantFile;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentApplicationAnswer;
use App\Models\RecruitmentCampaignInvite;
use App\Models\RecruitmentRequest;
use App\Models\User;
use App\Models\Vacancy;
use App\Services\PublicRecruitmentApplicationService;
use App\Services\RecruitmentApplicationFormService;
use App\Services\RecruitmentApplicationStatusService;
use App\Services\RecruitmentCampaignInviteService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

config(['queue.default' => 'sync']);
Mail::fake();

$pass = 0;
$fail = 0;
$check = function (string $label, bool $ok, string $extra = '') use (&$pass, &$fail): void {
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  PASS ' : '  FAIL ').$label.($extra !== '' ? "  [{$extra}]" : '').PHP_EOL;
};
// The reason code Team 1's service refused with, or '' when it was accepted.
$refusal = function (callable $attempt): string {
    try {
        $attempt();

        return '';
    } catch (PublicRecruitmentSubmissionException $e) {
        return $e->reason ?: 'generic';
    }
};

$forms = app(RecruitmentApplicationFormService::class);
$public = app(PublicRecruitmentApplicationService::class);
$hr = User::findOrFail(11);      // lana.abdo (HR-SYR)
$judy = User::findOrFail(3);     // manager: no applicant permission
$position = Position::query()->where('is_active', true)->firstOrFail();

// Every standard field except the vacancy city, whose choices come from the
// campaign's own vacancy rather than from the standard list.
$fields = array_values(array_filter($forms->defaultFields(), fn ($f) => $f['key'] !== 'vacancy_city'));

// A campaign built the way Team 1's screens build one.
$makeCampaign = function (string $mode) use ($hr, $position, $fields): array {
    $request = RecruitmentRequest::create(['request_number' => 'RR-T12-'.uniqid(), 'requester_user_id' => $hr->id, 'request_type' => 1, 'mode' => 1, 'status' => 5, 'target_position_id' => $position->id]);
    $vacancy = Vacancy::create(['position_id' => $position->id, 'created_from_request_id' => $request->id, 'status' => 'open', 'needed_count' => 1, 'open_at' => now()]);
    $campaign = HiringCampaign::create(['vacancy_id' => $vacancy->id, 'status' => HiringCampaignStatus::Open, 'link_mode' => $mode, 'created_by_user_id' => $hr->id]);
    $post = JobPost::create(['campaign_id' => $campaign->id, 'status' => 'published', 'created_by_user_id' => $hr->id]);
    $postVersion = JobPostVersion::create(['job_post_id' => $post->id, 'version_no' => 1, 'title_en' => 'Check role', 'title_ar' => 'دور فحص', 'body_en' => 'b', 'body_ar' => 'b', 'public_payload_json' => [], 'status' => 'published', 'published_at' => now()]);
    $post->update(['current_version_id' => $postVersion->id]);
    $form = ApplicationForm::create(['campaign_id' => $campaign->id, 'status' => 'published', 'created_by_user_id' => $hr->id]);
    $formVersion = ApplicationFormVersion::create(['application_form_id' => $form->id, 'version_no' => 1, 'fields_json' => $fields, 'privacy_notice_version' => 'v1', 'status' => 'published', 'published_at' => now()]);
    $form->update(['current_version_id' => $formVersion->id]);
    $token = bin2hex(random_bytes(32));
    $link = PublicRecruitmentLink::create(['campaign_id' => $campaign->id, 'job_post_version_id' => $postVersion->id, 'form_version_id' => $formVersion->id, 'token_hash' => hash('sha256', $token), 'status' => PublicRecruitmentLink::STATUS_ACTIVE, 'expires_at' => now()->addDays(10), 'created_by_user_id' => $hr->id]);

    return [$campaign->fresh(), $link->fresh(), $token];
};
$pdf = function (): UploadedFile {
    $path = tempnam(sys_get_temp_dir(), 'cv').'.pdf';
    file_put_contents($path, "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");

    return new UploadedFile($path, 'cv.pdf', 'application/pdf', null, true);
};
// Valid answers for the standard form.
$answers = function (string $email, string $phone = '0944000111', array $o = []) use ($fields, $pdf): array {
    $a = [];
    foreach ($fields as $f) {
        $a[$f['key']] = match (true) {
            $f['key'] === 'email' => $email,
            $f['key'] === 'phone' => $phone,
            $f['key'] === 'full_name' => 'Check Applicant',
            $f['type'] === 'date' => '1995-01-01',
            $f['type'] === 'number' => '300',
            $f['type'] === 'url' => 'https://example.com',
            in_array($f['type'], ['select', 'radio'], true) => $f['options'][0]['value'],
            $f['type'] === 'checkbox' => $f['required'] ? '1' : '0',
            $f['type'] === 'file' => $f['required'] ? $pdf() : null,
            default => 'Answer text',
        };
    }

    return array_filter([...$a, ...$o], fn ($v) => $v !== null);
};
$submit = fn (string $token, array $answers, ?string $key = null, array $extra = [], string $locale = 'en') => $public->submit(
    $token, ['answers' => $answers, 'submission_key' => $key ?? bin2hex(random_bytes(32)), 'locale' => $locale] + $extra, '127.0.0.1', 'task1-2-check',
);

// Stored uploads outlive the rollback: remember what was there.
$roots = [storage_path('app/private/recruitment-quarantine'), storage_path('app/private/recruitment-files')];
$listFiles = fn () => collect($roots)->filter(fn ($d) => is_dir($d))
    ->flatMap(fn ($d) => iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS))))
    ->map(fn ($f) => $f->getPathname())->values()->all();
$filesBefore = $listFiles();

DB::beginTransaction();

try {
    auth()->login($hr);
    [$campaign, $link, $token] = $makeCampaign(Mode::PublicOpen);
    [$campaign2, , $token2] = $makeCampaign(Mode::PublicOpen);

    echo 'Task 1 — نطاق التنفيذ والمطلوب تنفيذه'.PHP_EOL;
    $first = $submit($token, $answers('Check.One@Example.COM'))['application'];
    $applicant = $first->applicant;
    $check('Applicant (the person) and Application (one submission) are separate models', $applicant instanceof RecruitmentApplicant && $first instanceof RecruitmentApplication && $applicant->id !== null);
    $check('Application linked to Campaign, Vacancy and Form Version', $first->campaign->is($campaign) && (int) $first->vacancy_id === (int) $campaign->vacancy_id
        && (int) $first->form_version_id === (int) $link->form_version_id);
    $check('Answers saved against that form version', $first->answers()->count() > 0
        && $first->answers()->where('form_version_id', '!=', $link->form_version_id)->doesntExist());
    $check('Status is a controlled value, starting at "submitted"', $first->status === Status::Submitted && in_array($first->status, Status::values(), true));
    $check('Status moves only through the status service', rescue(fn () => $first->forceFill(['status' => Status::Hired])->save() && false, true, false)
        && in_array(Status::Shortlisted, app(RecruitmentApplicationStatusService::class)->targets($first->fresh()), true));
    $check('Relationships work both ways (applicant ⇄ applications ⇄ campaign)', $applicant->applications()->whereKey($first->id)->exists() && $campaign->applications()->whereKey($first->id)->exists());
    $idx = fn (string $t) => collect(Schema::getIndexes($t))->map(fn ($i) => implode('+', $i['columns']))->all();
    $check('Indexes for campaign / status / email / phone', count(array_intersect(['campaign_id+status', 'status', 'email_normalized'], $idx('recruitment_applications'))) === 3
        && in_array('phone_normalized', $idx('recruitment_applicants'), true) && in_array('phone_match_key', $idx('recruitment_applicants'), true));
    $check('Invites table: name, email, campaign, status, sent date', Schema::hasColumns('recruitment_campaign_invites', ['full_name', 'email_normalized', 'campaign_id', 'status', 'sent_at']));

    echo PHP_EOL.'Task 1 — قواعد العمل والحالات الاستثنائية'.PHP_EOL;
    $again = $submit($token2, $answers('check.one@example.com'))['application'];
    $check('Same person on two campaigns: one applicant, two applications', (int) $again->applicant_id === (int) $first->applicant_id && $again->id !== $first->id);
    $check('Same person twice on the same campaign: refused', $refusal(fn () => $submit($token, $answers('CHECK.ONE@example.com'))) === 'generic');
    $twin = $submit($token, $answers('another.person@example.com', '0955000222'))['application'];
    $check('Two people with the same name: two applicants (no merge on name)', (int) $twin->applicant_id !== (int) $first->applicant_id && $twin->applicant->full_name === $applicant->full_name);
    $samePhone = $submit($token, $answers('different.email@example.com', '0944000111'))['application'];
    $check('Different email, same phone: flagged as a possible duplicate, not merged', (int) $samePhone->applicant_id !== (int) $first->applicant_id
        && $samePhone->applicant->fresh()->possible_duplicate_of_applicant_id !== null);
    $newPhone = $submit($token2, $answers('another.person@example.com', '0966000333'))['application'];
    $check('Same email, different phone: the same applicant', (int) $newPhone->applicant_id === (int) $twin->applicant_id);
    $check('Email normalized (capitals)', $first->email_normalized === 'check.one@example.com');
    $spaced = $refusal(fn () => $submit($token2, $answers('  spaced@example.com  ')));
    $check('Email with spaces around it accepted and trimmed (spec edge case)', $spaced === '', $spaced !== '' ? "refused: {$spaced} — Team 1's validateSubmission checks the email before trimming it" : '');

    echo PHP_EOL.'Task 1 — invitation-only campaigns'.PHP_EOL;
    [$inviteCampaign, , $inviteToken] = $makeCampaign(Mode::InvitationOnly);
    $invites = app(RecruitmentCampaignInviteService::class);
    $invites->add($hr, $inviteCampaign, 'Invited Person', ' Invited@Example.com ');
    $invite = RecruitmentCampaignInvite::query()->where('campaign_id', $inviteCampaign->id)->sole();
    $check('HR adds a name and email to the invite list (email normalized)', $invite->email_normalized === 'invited@example.com' && $invite->full_name === 'Invited Person');
    $dup = rescue(fn () => DB::table('recruitment_campaign_invites')->insert(['campaign_id' => $inviteCampaign->id, 'full_name' => 'X', 'email_normalized' => 'invited@example.com', 'status' => 'pending', 'created_by_user_id' => $hr->id, 'created_at' => now(), 'updated_at' => now()]) && false, true, false);
    $check('The same email is never invited twice to one campaign', $dup);
    $check('Only authorized HR manages invites', Gate::forUser($hr)->allows('manageForCampaign', [RecruitmentCampaignInvite::class, $inviteCampaign]) && Gate::forUser($judy)->denies('manageForCampaign', [RecruitmentCampaignInvite::class, $inviteCampaign]));
    $html = app()->handle(Request::create("/careers/{$inviteToken}", 'GET'))->getContent();
    $check('The invite list is never shown on the public page', ! str_contains($html, 'invited@example.com') && ! str_contains($html, 'Invited Person'));
    $filesBeforeUninvited = RecruitmentApplicantFile::query()->count();
    $uninvited = $refusal(fn () => $submit($inviteToken, $answers('stranger@example.com')));
    $check('Someone not invited cannot apply, and no CV of theirs is stored', $uninvited === 'generic' && RecruitmentApplicantFile::query()->count() === $filesBeforeUninvited);
    $invited = $submit($inviteToken, $answers('INVITED@Example.com'))['application'];
    $check('The invited person (other capitals) applies, linked to the invite', (int) $invited->campaign_invite_id === (int) $invite->id && $invite->fresh()->status === RecruitmentCampaignInvite::STATUS_USED);
    $check('The invite cannot be used a second time', $refusal(fn () => $submit($inviteToken, $answers('invited@example.com'))) === 'generic');
    $invites->add($hr, $inviteCampaign, 'Cancelled Person', 'cancelled@example.com');
    $cancelled = RecruitmentCampaignInvite::query()->where('email_normalized', 'cancelled@example.com')->sole();
    $invites->cancel($hr, $cancelled);
    $check('HR cancelled the invite after the link went out: refused, same generic answer', $refusal(fn () => $submit($inviteToken, $answers('cancelled@example.com'))) === $uninvited);
    $invites->add($hr, $inviteCampaign, 'Typo Person', 'typo@exampel.com');
    $typo = RecruitmentCampaignInvite::query()->where('email_normalized', 'typo@exampel.com')->sole();
    // HR entered a wrong email: cancel it, add the right one.
    $invites->cancel($hr, $typo);
    $fixed = $invites->add($hr, $inviteCampaign, 'Typo Person', 'typo@example.com');
    $check('HR corrects a wrong email on an invite', $refusal(fn () => $submit($inviteToken, $answers('typo@exampel.com'))) === 'generic'
        && (int) $submit($inviteToken, $answers('typo@example.com'))['application']->campaign_invite_id === (int) $fixed->id);

    echo PHP_EOL.'Task 1 — سلامة البيانات'.PHP_EOL;
    $check('No answers without an application', rescue(fn () => DB::table('recruitment_application_answers')->insert(['application_id' => 99999999, 'form_version_id' => $link->form_version_id, 'field_key' => 'x', 'answer_json' => '{}', 'created_at' => now(), 'updated_at' => now()]) && false, true, false));
    $check('No application without a valid campaign', rescue(fn () => DB::table('recruitment_applications')->insert(['applicant_id' => $applicant->id, 'campaign_id' => 99999999, 'vacancy_id' => $campaign->vacancy_id, 'form_version_id' => $link->form_version_id, 'public_link_id' => $link->id, 'email_normalized' => 'x@example.com', 'status' => 'submitted', 'submitted_at' => now()]) && false, true, false));

    echo PHP_EOL.'Task 2 — قواعد العمل'.PHP_EOL;
    $check('Campaign comes from the token, never from the input', $refusal(fn () => $submit($token, $answers('ci@example.com'), null, ['campaign_id' => $campaign2->id])) === 'invalid_request');
    $check('Internal fields refused (status, score)', $refusal(fn () => $submit($token, $answers('st@example.com'), null, ['status' => 'hired'])) === 'invalid_request'
        && $refusal(fn () => $submit($token, $answers('sc@example.com'), null, ['score' => 99])) === 'invalid_request');
    $check('An answer that is not on the form is refused', $refusal(fn () => $submit($token, $answers('xf@example.com', '0944', ['secret_field' => 'x']))) !== '');
    $key = bin2hex(random_bytes(32));
    $once = $submit($token, $answers('retry@example.com'), $key);
    $twice = $submit($token, $answers('retry@example.com'), $key);
    $check('Double submit / retry after a timeout: one application', $twice['idempotent'] && $twice['application']->is($once['application'])
        && RecruitmentApplication::query()->where('email_normalized', 'retry@example.com')->count() === 1);
    $check('A submission without an idempotency key is refused', $refusal(fn () => $public->submit($token, ['answers' => $answers('nokey@example.com'), 'locale' => 'en'])) === 'invalid_request');
    $check('A required answer missing is refused', $refusal(fn () => $submit($token, array_diff_key($answers('miss@example.com'), ['full_name' => 1]))) !== '');
    $check('An oversized answer is refused', $refusal(fn () => $submit($token, $answers('big@example.com', '0944000999', ['motivation' => str_repeat('x', 200000)]))) !== '');
    $campaign2->forceFill(['status' => HiringCampaignStatus::Closed])->save();
    $check('A closed campaign refuses', $refusal(fn () => $submit($token2, $answers('closed@example.com'))) === 'campaign_closed');
    $campaign2->forceFill(['status' => HiringCampaignStatus::Open, 'ends_at' => now()->subMinute()])->save();
    $check('An ended campaign refuses', $refusal(fn () => $submit($token2, $answers('ended@example.com'))) !== '');
    $campaign2->forceFill(['status' => HiringCampaignStatus::Disabled, 'ends_at' => null])->save();
    $check('A disabled campaign refuses', $refusal(fn () => $submit($token2, $answers('disabled@example.com'))) !== '');
    $newForm = ApplicationFormVersion::create(['application_form_id' => $link->formVersion->application_form_id, 'version_no' => 2, 'fields_json' => $fields, 'privacy_notice_version' => 'v2', 'status' => 'published', 'published_at' => now()]);
    $afterChange = $submit($token, $answers('formchange@example.com'))['application'];
    $check('The form changed after the link was made: the application keeps the link\'s version', (int) $afterChange->form_version_id === (int) $link->form_version_id && (int) $afterChange->form_version_id !== (int) $newForm->id);

    echo PHP_EOL.'Task 2 — الأمان والصلاحيات'.PHP_EOL;
    $submitRoute = Route::getRoutes()->getByName('careers.submit');
    $check('Public endpoint, no sign-in, rate limited', ! in_array('auth', $submitRoute->gatherMiddleware(), true) && in_array('throttle:careers-submit', $submitRoute->gatherMiddleware(), true));
    $check('Honeypot: a filled "website" field is refused', $refusal(fn () => $submit($token, $answers('bot@example.com'), null, ['website' => 'http://spam'])) === 'invalid_request');
    $check('Public errors are generic (no internal ids, no stack)', collect(['generic', 'invalid_request', 'server_error', 'campaign_closed'])
        ->every(fn ($r) => ! preg_match('/SQL|Exception|\bid\b|stack/i', (string) __("careers.errors.{$r}"))));
    $check('No answer tells whether an email is invited (uninvited = cancelled = used)', $uninvited === 'generic');
    $check('Applicant data only for authorized HR in scope', Gate::forUser($hr)->allows('view', $first->fresh()) && Gate::forUser($judy)->denies('view', $first->fresh()));

    echo PHP_EOL.'Task 2 — سلامة البيانات'.PHP_EOL;
    $before = [RecruitmentApplicant::query()->count(), RecruitmentApplication::query()->count(), RecruitmentApplicationAnswer::query()->count()];
    $refusal(fn () => $submit($token, $answers('badcv@example.com', '0944000777', ['cv' => UploadedFile::fake()->create('cv.exe', 10)])));
    $check('A failed submission leaves nothing partial', $before === [RecruitmentApplicant::query()->count(), RecruitmentApplication::query()->count(), RecruitmentApplicationAnswer::query()->count()]);
    $check('Submission audit entry, without personal data', DB::table('activity_log')->where('description', 'recruitment_application_submitted')->where('subject_id', $first->id)->where('properties', 'not like', '%check.one%')->exists());

    echo PHP_EOL.'Task 2 — "Send me a copy of my responses" (Team 1: SendRecruitmentResponseReceipt)'.PHP_EOL;
    // Queued after the submission's own transaction; the queue runs inline here.
    $copy = $submit($token, $answers('copy@example.com', '0944000888', ['response_copy' => '1']), null, [], 'ar')['application'];
    $receipts = fn (string $to) => Mail::sent(RecruitmentResponseReceiptMail::class, fn ($m) => $m->hasTo($to));
    $check('Ticked: one receipt, to that address, for that application', $receipts('copy@example.com')->count() === 1
        && str_contains($receipts('copy@example.com')->first()->render(), 'Check Applicant'));
    $check('The receipt follows the language the candidate used', data_get($copy->consent_payload_json, 'locale') === 'ar');
    $check('Not ticked: no receipt', $receipts('check.one@example.com')->isEmpty());
    $sameKey = bin2hex(random_bytes(32));
    $submit($token, $answers('copy.retry@example.com', '0944000866', ['response_copy' => '1']), $sameKey);
    $submit($token, $answers('copy.retry@example.com', '0944000866', ['response_copy' => '1']), $sameKey);
    $check('A retried submission (same key) sends one receipt, not two', $receipts('copy.retry@example.com')->count() === 1);

    echo PHP_EOL.'Task 2 — the test path is gone, the real one serves'.PHP_EOL;
    $check('GET /careers/{token} is the real form (200)', app()->handle(Request::create("/careers/{$token}", 'GET'))->getStatusCode() === 200);
    $check('GET /careers-test/{token} no longer exists (404)', app()->handle(Request::create("/careers-test/{$token}", 'GET'))->getStatusCode() === 404);
} finally {
    DB::rollBack();
    $leftovers = array_diff($listFiles(), $filesBefore);
    array_map('unlink', $leftovers);
}

echo PHP_EOL."RESULT: {$pass} passed, {$fail} failed".PHP_EOL;
echo 'Rolled back; '.count($leftovers).' uploaded test file(s) removed; check applications left: '.RecruitmentApplication::query()->where('user_agent', 'task1-2-check')->count().PHP_EOL;
