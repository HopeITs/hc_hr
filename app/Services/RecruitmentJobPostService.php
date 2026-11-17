<?php

namespace App\Services;

use App\Enums\RecruitmentEventType;
use App\Enums\RecruitmentRequestStatus;
use App\Exceptions\StaleRecruitmentDraftException;
use App\Models\HiringCampaign;
use App\Models\JobPost;
use App\Models\JobPostVersion;
use App\Models\RecruitmentRequestEvent;
use App\Models\User;
use DomainException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class RecruitmentJobPostService
{
    public const TEMPLATE = 'hope_center_hiring_v1';

    public function __construct(private readonly ActivityLogger $activityLogger) {}

    /** @return array{job_post: JobPost, version: JobPostVersion, fingerprint: string, values: array<string, string>} */
    public function openEditor(HiringCampaign $campaign, User $actor): array
    {
        return DB::transaction(function () use ($campaign, $actor): array {
            $campaign = $this->loadSource($campaign);
            $this->assertApprovedSource($campaign);
            $jobPost = JobPost::query()->firstOrCreate(
                ['campaign_id' => $campaign->getKey()],
                ['status' => 'draft', 'created_by_user_id' => $actor->getKey()],
            );
            $version = $this->editableVersion($jobPost);

            if (! $version) {
                $version = $jobPost->versions()->create($this->versionAttributes(1, $this->sourceValues($campaign), 'draft'));
            } else {
                $version = $this->stripMovedRoleDetails($version, $campaign);
            }

            return [
                'job_post' => $jobPost->fresh(),
                'version' => $version,
                'fingerprint' => $this->fingerprint($version),
                'values' => $this->valuesFromVersion($version),
            ];
        });
    }

    /** @return array<string, string> */
    public function sourceValues(HiringCampaign $campaign): array
    {
        $campaign = $this->loadSource($campaign);
        $this->assertApprovedSource($campaign);
        $vacancy = $campaign->vacancy;
        $request = $vacancy->createdFromRequest;
        $detail = $request->latestVersion?->positionDetail;
        $position = $vacancy->position ?? $detail?->position;
        $city = $request->scopeCity;

        if ($city?->slug === 'SYR') {
            $city = $position?->city ?? $position?->center?->city ?? $city;
        }

        $titleEn = $detail?->role_title_en ?? $position?->title_en ?? '';
        $titleAr = $detail?->role_title_ar ?? $position?->title_ar ?? $titleEn;

        return $this->normalize([
            'city' => $city?->name_en ?? $position?->city?->name_en ?? $position?->center?->city?->name_en ?? '',
            'program' => $detail?->program?->name ?? $position?->program?->name ?? 'Christian Hope Center',
            'job_title' => $titleEn,
            'title_en' => "We're Hiring — {$titleEn}",
            'title_ar' => "مطلوب متطوع — {$titleAr}",
            'body_en' => $this->generatedBody($detail, 'en'),
            'body_ar' => $this->generatedBody($detail, 'ar'),
        ]);
    }

    /** @param array<string, mixed> $values */
    public function saveDraft(JobPost $jobPost, array $values, ?int $expectedVersionId = null, ?string $expectedFingerprint = null): JobPostVersion
    {
        return DB::transaction(function () use ($jobPost, $values, $expectedVersionId, $expectedFingerprint): JobPostVersion {
            $lockedPost = $this->lockPost($jobPost);
            $this->assertApprovedSource($this->loadSource($lockedPost->campaign));
            $current = $this->editableVersion($lockedPost, true);
            if ($expectedVersionId !== null && $expectedFingerprint !== null) {
                $this->assertExpectedVersion($current, $expectedVersionId, $expectedFingerprint);
            }

            return $this->persistDraft($lockedPost, $this->normalize($values));
        });
    }

    /** @param array<string, mixed> $values */
    public function publish(JobPost $jobPost, array $values, User $actor, ?int $expectedVersionId = null, ?string $expectedFingerprint = null): JobPostVersion
    {
        return DB::transaction(function () use ($jobPost, $values, $actor, $expectedVersionId, $expectedFingerprint): JobPostVersion {
            $lockedPost = $this->lockPost($jobPost);
            $campaign = $this->loadSource($lockedPost->campaign);
            $this->assertApprovedSource($campaign);
            $values = $this->normalize($values);
            $editable = $this->editableVersion($lockedPost, true);

            if ($editable && $editable->status === 'published' && $this->sameValues($editable, $values)) {
                return $editable;
            }

            if ($expectedVersionId !== null && $expectedFingerprint !== null) {
                $this->assertExpectedVersion($editable, $expectedVersionId, $expectedFingerprint);
            }
            $draft = $this->persistDraft($lockedPost, $values);
            $draft->forceFill([
                ...$this->versionAttributes($draft->version_no, $values, 'published'),
                'published_at' => now(),
            ])->save();
            $lockedPost->forceFill(['status' => 'published', 'current_version_id' => $draft->getKey()])->save();

            $request = $campaign->vacancy->createdFromRequest;
            RecruitmentRequestEvent::query()->create([
                'recruitment_request_id' => $request->getKey(),
                'request_version_id' => $request->latestVersion?->getKey(),
                'event_type' => RecruitmentEventType::JobPostPublished,
                'actor_user_id' => $actor->getKey(),
                'actor_role' => $actor->myRole()?->name ?? 'unknown',
                'comment' => 'Job post version '.$draft->version_no.' published.',
                'meta' => [
                    'campaign_id' => $campaign->getKey(),
                    'job_post_id' => $lockedPost->getKey(),
                    'job_post_version_id' => $draft->getKey(),
                    'template' => self::TEMPLATE,
                ],
            ]);

            return $draft->fresh();
        });
    }

    public function unpublish(JobPost $jobPost, User $actor, int $expectedPublishedVersionId): JobPostVersion
    {
        return DB::transaction(function () use ($jobPost, $actor, $expectedPublishedVersionId): JobPostVersion {
            $lockedPost = $this->lockPost($jobPost);
            if ((int) $lockedPost->current_version_id !== $expectedPublishedVersionId) {
                throw new StaleRecruitmentDraftException;
            }

            $published = JobPostVersion::query()->findOrFail($expectedPublishedVersionId);
            $draft = $lockedPost->versions()->where('status', 'draft')->lockForUpdate()->latest('version_no')->first();
            $draft ??= $lockedPost->versions()->create(
                $this->versionAttributes(((int) $lockedPost->versions()->max('version_no')) + 1, $this->valuesFromVersion($published), 'draft'),
            );
            $lockedPost->forceFill(['status' => 'unpublished', 'current_version_id' => null])->save();
            $this->activityLogger->log('job_post_unpublished', $lockedPost, $actor, [
                'campaign_id' => $lockedPost->campaign_id,
                'published_version_id' => $published->getKey(),
                'draft_version_id' => $draft->getKey(),
            ], 'recruitment');

            return $draft->fresh();
        });
    }

    /** @return array<string, mixed> */
    public function publicSnapshot(HiringCampaign $campaign, ?int $versionId = null): array
    {
        $version = $versionId
            ? $campaign->jobPost?->versions()->whereKey($versionId)->where('status', 'published')->first()
            : $campaign->jobPost?->currentVersion;
        if (! $version || $version->status !== 'published') {
            throw new DomainException('This campaign does not have a published job post.');
        }

        $values = $this->valuesFromVersion($version);

        return [
            'template' => self::TEMPLATE,
            'city' => $values['city'],
            'program' => $values['program'],
            'job_title' => $values['job_title'],
            'outputs' => Arr::get($version->public_payload_json, 'outputs', []),
            'title' => ['en' => $values['title_en'], 'ar' => $values['title_ar']],
            'body' => ['en' => $values['body_en'], 'ar' => $values['body_ar']],
            'version' => $version->version_no,
            'published_at' => $version->published_at?->toIso8601String(),
        ];
    }

    public function fingerprint(JobPostVersion $version): string
    {
        return hash('sha256', json_encode([
            'id' => $version->getKey(),
            'values' => $this->valuesFromVersion($version),
            'status' => $version->status,
            'published_at' => $version->published_at?->toIso8601String(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string, string> */
    private function valuesFromVersion(JobPostVersion $version): array
    {
        return $this->normalize([
            'city' => Arr::get($version->public_payload_json, 'city', ''),
            'program' => Arr::get($version->public_payload_json, 'program', ''),
            'job_title' => Arr::get($version->public_payload_json, 'job_title', ''),
            'title_en' => $version->title_en,
            'title_ar' => $version->title_ar,
            'body_en' => $version->body_en,
            'body_ar' => $version->body_ar,
        ]);
    }

    /** @param array<string, string> $values */
    private function persistDraft(JobPost $jobPost, array $values): JobPostVersion
    {
        $draft = $jobPost->versions()->where('status', 'draft')->lockForUpdate()->latest('version_no')->first();
        $current = $jobPost->current_version_id ? JobPostVersion::query()->find($jobPost->current_version_id) : null;

        if (! $draft && $current && $this->sameValues($current, $values)) {
            return $current;
        }

        if ($draft) {
            $draft->forceFill($this->versionAttributes($draft->version_no, $values, 'draft'))->save();

            return $draft->fresh();
        }

        return $jobPost->versions()->create(
            $this->versionAttributes(((int) $jobPost->versions()->max('version_no')) + 1, $values, 'draft'),
        );
    }

    private function editableVersion(JobPost $jobPost, bool $lock = false): ?JobPostVersion
    {
        $query = $jobPost->versions()->where('status', 'draft')->latest('version_no');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first() ?? $jobPost->currentVersion;
    }

    private function assertExpectedVersion(?JobPostVersion $version, int $expectedId, string $expectedFingerprint): void
    {
        if (! $version || (int) $version->getKey() !== $expectedId || ! hash_equals($this->fingerprint($version), $expectedFingerprint)) {
            throw new StaleRecruitmentDraftException;
        }
    }

    private function lockPost(JobPost $jobPost): JobPost
    {
        return JobPost::query()->with('campaign')->lockForUpdate()->findOrFail($jobPost->getKey());
    }

    private function loadSource(HiringCampaign $campaign): HiringCampaign
    {
        return $campaign->loadMissing([
            'jobPost.currentVersion',
            'vacancy.position.program', 'vacancy.position.city', 'vacancy.position.center.city',
            'vacancy.createdFromRequest.scopeCity',
            'vacancy.createdFromRequest.latestVersion.positionDetail.program',
            'vacancy.createdFromRequest.latestVersion.positionDetail.position',
        ]);
    }

    private function assertApprovedSource(HiringCampaign $campaign): void
    {
        $request = $campaign->vacancy?->createdFromRequest;
        if (! $request || ! in_array((int) $request->status, [RecruitmentRequestStatus::Approved, RecruitmentRequestStatus::Applied], true)) {
            throw new DomainException('A job post can only be generated from an approved recruitment request.');
        }
    }

    /** @param array<string, mixed> $values @return array<string, string> */
    private function normalize(array $values): array
    {
        $jobTitle = trim((string) ($values['job_title'] ?? ''));
        $titleEn = trim((string) ($values['title_en'] ?? ''));
        $titleAr = trim((string) ($values['title_ar'] ?? ''));

        return [
            'city' => mb_strtoupper(trim((string) ($values['city'] ?? ''))),
            'program' => mb_strtoupper(trim((string) ($values['program'] ?? ''))),
            'job_title' => $jobTitle,
            // Headings are generated by the locked Hope Center template.
            // Keep a source-language Arabic title when one exists, while
            // making direct service calls just as safe as the editor.
            'title_en' => $titleEn !== '' ? $titleEn : "We're Hiring — {$jobTitle}",
            'title_ar' => $titleAr !== '' ? $titleAr : "مطلوب متطوع — {$jobTitle}",
            'body_en' => trim((string) ($values['body_en'] ?? '')),
            'body_ar' => trim((string) ($values['body_ar'] ?? '')),
        ];
    }

    /** @param array<string, string> $values @return array<string, mixed> */
    private function versionAttributes(int $versionNumber, array $values, string $status): array
    {
        return [
            'version_no' => $versionNumber,
            'title_en' => $values['title_en'], 'title_ar' => $values['title_ar'],
            'body_en' => $values['body_en'], 'body_ar' => $values['body_ar'],
            'public_payload_json' => [
                'template' => self::TEMPLATE,
                'city' => $values['city'], 'program' => $values['program'], 'job_title' => $values['job_title'],
                'outputs' => ['story' => ['width' => 2160, 'height' => 3840], 'post' => ['width' => 2160, 'height' => 2160]],
            ],
            'status' => $status,
        ];
    }

    /** @param array<string, string> $values */
    private function sameValues(JobPostVersion $version, array $values): bool
    {
        return $this->valuesFromVersion($version) === $values;
    }

    private function generatedBody(mixed $detail, string $locale): string
    {
        $labels = $locale === 'ar'
            ? ['required_skills' => 'المهارات المطلوبة', 'experience' => 'الخبرة المطلوبة']
            : ['required_skills' => 'Required skills', 'experience' => 'Required experience'];
        $sections = [];
        foreach (['required_skills'] as $field) {
            if (filled($detail?->{$field})) {
                $sections[] = $labels[$field]."\n".trim((string) $detail->{$field});
            }
        }
        if ($detail?->required_experience_years !== null) {
            $suffix = $locale === 'ar' ? ' سنوات' : ' years';
            $sections[] = $labels['experience']."\n".$detail->required_experience_years.$suffix;
        }

        return implode("\n\n", $sections);
    }

    private function stripMovedRoleDetails(JobPostVersion $version, HiringCampaign $campaign): JobPostVersion
    {
        if ($version->status !== 'draft') {
            return $version;
        }

        $detail = $campaign->vacancy?->createdFromRequest?->latestVersion?->positionDetail;
        $legacyEnglish = $this->legacyGeneratedBody($detail, 'en');
        $legacyArabic = $this->legacyGeneratedBody($detail, 'ar');
        if (trim((string) $version->body_en) !== trim($legacyEnglish) || trim((string) $version->body_ar) !== trim($legacyArabic)) {
            return $version;
        }

        $version->forceFill(['body_en' => '', 'body_ar' => ''])->save();

        return $version->fresh();
    }

    private function legacyGeneratedBody(mixed $detail, string $locale): string
    {
        $labels = $locale === 'ar'
            ? ['main_duties' => 'المهام الرئيسية', 'required_qualifications' => 'المؤهلات المطلوبة', 'required_skills' => 'المهارات المطلوبة', 'experience' => 'الخبرة المطلوبة']
            : ['main_duties' => 'Main duties', 'required_qualifications' => 'Required qualifications', 'required_skills' => 'Required skills', 'experience' => 'Required experience'];
        $sections = [];
        foreach (['main_duties', 'required_qualifications', 'required_skills'] as $field) {
            if (filled($detail?->{$field})) {
                $sections[] = $labels[$field]."\n".trim((string) $detail->{$field});
            }
        }
        if ($detail?->required_experience_years !== null) {
            $suffix = $locale === 'ar' ? ' سنوات' : ' years';
            $sections[] = $labels['experience']."\n".$detail->required_experience_years.$suffix;
        }

        return implode("\n\n", $sections);
    }
}
