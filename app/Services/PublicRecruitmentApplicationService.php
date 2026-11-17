<?php

namespace App\Services;

use App\Enums\RecruitmentApplicationStatus;
use App\Exceptions\PublicRecruitmentSubmissionException;
use App\Exceptions\UnsafeRecruitmentFileException;
use App\Jobs\SendRecruitmentResponseReceipt;
use App\Models\HiringCampaign;
use App\Models\PublicRecruitmentLink;
use App\Models\RecruitmentApplicant;
use App\Models\RecruitmentApplicantFile;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentCampaignInvite;
use App\Models\User;
use App\Services\Monitoring\SecurityMonitor;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class PublicRecruitmentApplicationService
{
    public function __construct(
        private readonly PublicRecruitmentLinkService $links,
        private readonly RecruitmentApplicationFormService $forms,
        private readonly RecruitmentApplicantFileService $files,
        private readonly InternalRecruitmentApplicantService $internal,
        private readonly RecruitmentApplicantIdentityService $identity,
    ) {}

    /**
     * `$actor` is whoever is signed in. It only matters for an internal
     * campaign, which accepts staff alone and files the application under
     * their work account whatever email was posted.
     *
     * @param  array<string, mixed>  $input
     * @return array{application: RecruitmentApplication, idempotent: bool}
     */
    public function submit(
        string $token,
        array $input,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?User $actor = null,
    ): array {
        $this->assertAllowedEnvelope($input);

        $submissionKey = Str::lower(trim((string) ($input['submission_key'] ?? '')));
        if (preg_match('/^[a-f0-9]{64}$/', $submissionKey) !== 1) {
            throw PublicRecruitmentSubmissionException::because('invalid_request');
        }

        $submissionHash = hash('sha256', $submissionKey);
        $resolvedLink = $this->links->submissionLink($token);
        $existing = RecruitmentApplication::query()
            ->where('submission_key_hash', $submissionHash)
            ->first();

        if ($existing) {
            if ((int) $existing->campaign_id !== (int) $resolvedLink->campaign_id
                || (int) $existing->public_link_id !== (int) $resolvedLink->getKey()) {
                throw PublicRecruitmentSubmissionException::because('invalid_request');
            }

            return ['application' => $existing, 'idempotent' => true];
        }

        $answers = $input['answers'] ?? null;
        if (! is_array($answers)) {
            throw PublicRecruitmentSubmissionException::because('invalid_request');
        }

        if (array_key_exists('phone', $answers) && is_string($answers['phone'])) {
            $answers['phone'] = en_digits(trim($answers['phone']));
        }

        $employee = null;
        if ($resolvedLink->campaign->isInternal()) {
            $employee = $this->internal->employeeFor($actor);
            if (! $employee) {
                throw new PublicRecruitmentSubmissionException;
            }

            $answers['email'] = $this->internal->email($actor);
        }

        try {
            $validated = $this->forms->validateSubmission($resolvedLink->formVersion, $answers);
        } catch (ValidationException $exception) {
            throw PublicRecruitmentSubmissionException::because('validation', $exception->errors());
        }

        $fields = $this->forms->normalizeFields($resolvedLink->formVersion->fields_json);
        $fieldsByKey = collect($fields)->keyBy('key');
        $email = $this->links->normalizeEmail((string) ($validated['email'] ?? ''));
        $fullName = trim((string) ($validated['full_name'] ?? ''));
        $phone = $this->normalizePhone((string) ($validated['phone'] ?? ''));
        $cityId = filter_var($input['city_id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]) ?: null;

        if ($fullName === '') {
            throw PublicRecruitmentSubmissionException::because('missing_name');
        }
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw PublicRecruitmentSubmissionException::because('invalid_email');
        }
        if (preg_match('/\A09[0-9]{8}\z/', $phone) !== 1) {
            throw PublicRecruitmentSubmissionException::because('invalid_phone');
        }

        $this->assertAgeEligible($resolvedLink->campaign, $validated['date_of_birth'] ?? null);

        try {
            $result = DB::transaction(function () use (
                $token,
                $resolvedLink,
                $submissionHash,
                $validated,
                $fieldsByKey,
                $email,
                $fullName,
                $phone,
                $cityId,
                $input,
                $ipAddress,
                $userAgent,
                $employee,
            ): array {
                $campaign = HiringCampaign::query()
                    ->lockForUpdate()
                    ->findOrFail($resolvedLink->campaign_id);

                $link = PublicRecruitmentLink::query()
                    ->with([
                        'campaign',
                        'jobPostVersion.jobPost',
                        'formVersion.applicationForm',
                    ])
                    ->whereKey($resolvedLink->getKey())
                    ->where('token_hash', hash('sha256', $token))
                    ->lockForUpdate()
                    ->first();

                if (! $link || (int) $link->campaign_id !== (int) $campaign->getKey()) {
                    throw PublicRecruitmentSubmissionException::because('link_changed');
                }

                $this->links->assertSubmissionLink($link);

                $existingByKey = RecruitmentApplication::query()
                    ->where('submission_key_hash', $submissionHash)
                    ->lockForUpdate()
                    ->first();
                if ($existingByKey) {
                    if ((int) $existingByKey->campaign_id !== (int) $campaign->getKey()
                        || (int) $existingByKey->public_link_id !== (int) $link->getKey()) {
                        throw PublicRecruitmentSubmissionException::because('invalid_request');
                    }

                    return ['application' => $existingByKey, 'idempotent' => true];
                }

                if (RecruitmentApplication::query()
                    ->where('campaign_id', $campaign->getKey())
                    ->where('email_normalized', $email)
                    ->exists()) {
                    // Generic on purpose: a specific reason would tell anyone
                    // holding the link which emails have already applied.
                    throw PublicRecruitmentSubmissionException::because('generic');
                }

                $invite = $this->resolveInvite($campaign, $email);
                $identityHash = hash_hmac('sha256', $email, (string) config('app.key'));
                RecruitmentApplicant::query()->insertOrIgnore([
                    'full_name' => $fullName,
                    'email_normalized' => $email,
                    'phone_normalized' => $phone,
                    'city_id' => $cityId,
                    'identity_key_hash' => $identityHash,
                    'source' => $employee ? 'internal' : 'public_link',
                    'last_seen_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $applicant = RecruitmentApplicant::query()
                    ->where('identity_key_hash', $identityHash)
                    ->lockForUpdate()
                    ->firstOrFail();
                // Only a signed-in member of staff has proven the email is
                // theirs. Anyone else may fill blanks on a record that already
                // exists but never rewrite it: the record is the person's, and
                // this form cannot tell the person from someone typing their
                // address. (A row inserted just above has only its consent
                // blank, so it is filled in the same pass.) Each application
                // keeps its own answers and consent either way.
                $applicant->forceFill([
                    ...$this->identity->writableFields($applicant, [
                        'full_name' => $fullName,
                        'phone_normalized' => $phone,
                        'city_id' => $cityId,
                        'privacy_notice_version' => $link->formVersion->privacy_notice_version,
                        'privacy_accepted_at' => now(),
                    ], verified: $employee !== null),
                    'last_seen_at' => now(),
                    // A new application restarts the retention clock, but never
                    // undoes an erasure request: that date was set to the
                    // moment of the request and the sweep will not restore it.
                    ...($applicant->erasure_requested_at === null ? ['retention_until' => null] : []),
                    // Signing in proved who they are; keep the link to their record.
                    ...($employee ? [
                        'employee_id' => $employee->getKey(),
                        'identity_verified_at' => $applicant->identity_verified_at ?? now(),
                    ] : []),
                ])->save();

                $application = RecruitmentApplication::query()->create([
                    'applicant_id' => $applicant->getKey(),
                    'campaign_id' => $campaign->getKey(),
                    'vacancy_id' => $campaign->vacancy_id,
                    'form_version_id' => $link->form_version_id,
                    'public_link_id' => $link->getKey(),
                    'campaign_invite_id' => $invite?->getKey(),
                    'submission_key_hash' => $submissionHash,
                    'email_normalized' => $email,
                    'status' => RecruitmentApplicationStatus::Submitted,
                    'privacy_notice_version' => $link->formVersion->privacy_notice_version,
                    'privacy_accepted_at' => now(),
                    'consent_payload_json' => [
                        'privacy_consent' => true,
                        'response_copy' => (bool) ($validated['response_copy'] ?? false),
                        'locale' => in_array($input['locale'] ?? null, ['en', 'ar'], true) ? $input['locale'] : 'en',
                    ],
                    'submitted_at' => now(),
                    'ip_address' => $ipAddress,
                    'user_agent' => Str::limit((string) $userAgent, 2000, ''),
                ]);

                foreach ($validated as $key => $answer) {
                    $field = $fieldsByKey->get($key);
                    if (! is_array($field) || $field['type'] === 'file') {
                        continue;
                    }

                    $application->answers()->create([
                        'form_version_id' => $link->form_version_id,
                        'field_key' => $key,
                        'answer_json' => ['value' => $answer],
                    ]);
                }

                $storedFiles = [];
                try {
                    foreach ($validated as $key => $answer) {
                        $field = $fieldsByKey->get($key);
                        if (! is_array($field) || $field['type'] !== 'file' || $answer === null) {
                            continue;
                        }

                        $uploads = is_array($answer) ? $answer : [$answer];
                        foreach ($uploads as $upload) {
                            if (! $upload instanceof UploadedFile) {
                                throw PublicRecruitmentSubmissionException::because('invalid_file');
                            }
                            $storedFiles[] = $this->files->store($application, $upload, $key, [
                                'extensions' => $field['accept'],
                                'max_files' => $field['max_files'],
                            ]);
                        }
                    }

                    if ($invite) {
                        $invite->forceFill([
                            'status' => RecruitmentCampaignInvite::STATUS_USED,
                            'used_at' => now(),
                        ])->save();
                    }
                } catch (Throwable $exception) {
                    foreach ($storedFiles as $storedFile) {
                        if ($storedFile instanceof RecruitmentApplicantFile) {
                            $storedFile->delete();
                        }
                    }

                    throw $exception;
                }

                return ['application' => $application->fresh(), 'idempotent' => false];
            }, 3);

            if (! $result['idempotent'] && data_get($result['application']->consent_payload_json, 'response_copy', false)) {
                rescue(fn () => SendRecruitmentResponseReceipt::dispatch($result['application']->getKey()));
            }

            return $result;
        } catch (PublicRecruitmentSubmissionException $exception) {
            throw $exception;
        } catch (ValidationException $exception) {
            throw PublicRecruitmentSubmissionException::because('validation', $exception->errors());
        } catch (UnsafeRecruitmentFileException $exception) {
            app(SecurityMonitor::class)->uploadRejected($exception->reason);

            throw PublicRecruitmentSubmissionException::because('file_rejected', ['file' => [$exception->getMessage()]]);
        } catch (Throwable $exception) {
            report($exception);

            throw PublicRecruitmentSubmissionException::because('server_error');
        }
    }

    /** @param array<string, mixed> $input */
    private function assertAllowedEnvelope(array $input): void
    {
        $allowed = ['_token', 'answers', 'city_id', 'locale', 'website', 'submission_key'];
        if (array_diff(array_keys($input), $allowed) !== [] || ! empty($input['website'])) {
            throw PublicRecruitmentSubmissionException::because('invalid_request');
        }
    }

    private function resolveInvite(HiringCampaign $campaign, string $email): ?RecruitmentCampaignInvite
    {
        if (! $campaign->isInvitationOnly()) {
            return null;
        }

        $invite = RecruitmentCampaignInvite::query()
            ->where('campaign_id', $campaign->getKey())
            ->where('email_normalized', $email)
            ->whereIn('status', [RecruitmentCampaignInvite::STATUS_PENDING, RecruitmentCampaignInvite::STATUS_SENT])
            ->lockForUpdate()
            ->first();

        if (! $invite) {
            // Generic for the same reason as a duplicate email: it must not
            // reveal who holds an invitation, or whether it was used.
            throw PublicRecruitmentSubmissionException::because('generic');
        }

        return $invite;
    }

    private function normalizePhone(string $phone): string
    {
        $phone = trim(en_digits($phone));

        return Str::limit($phone, 50, '');
    }

    private function assertAgeEligible(HiringCampaign $campaign, mixed $dateOfBirth): void
    {
        if ($campaign->minimum_age === null && $campaign->maximum_age === null) {
            return;
        }

        if (! is_string($dateOfBirth) || $dateOfBirth === '') {
            throw PublicRecruitmentSubmissionException::because('age_not_eligible');
        }

        try {
            $birthDate = CarbonImmutable::createFromFormat('Y-m-d', $dateOfBirth);
            $age = $birthDate === false ? null : $birthDate->age;
        } catch (Throwable) {
            throw PublicRecruitmentSubmissionException::because('age_not_eligible');
        }

        if ($age === null
            || ($campaign->minimum_age !== null && $age < $campaign->minimum_age)
            || ($campaign->maximum_age !== null && $age > $campaign->maximum_age)) {
            throw PublicRecruitmentSubmissionException::because('age_not_eligible');
        }
    }
}
