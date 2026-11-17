<?php

namespace App\Services;

use App\Enums\RecruitmentEventType;
use App\Enums\RecruitmentRequestStatus;
use App\Exceptions\StaleRecruitmentDraftException;
use App\Models\ApplicationForm;
use App\Models\ApplicationFormVersion;
use App\Models\HiringCampaign;
use App\Models\Position;
use App\Models\RecruitmentRequestEvent;
use App\Models\User;
use DomainException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RecruitmentApplicationFormService
{
    /** @var array<string, array{label: array{en: string, ar: string}, description: array{en: string, ar: string}}> */
    public const SECTION_CATALOG = [
        'vacancy' => [
            'label' => ['en' => 'Vacancy details', 'ar' => 'تفاصيل الشاغر'],
            'description' => ['en' => 'Role information and preferred work location.', 'ar' => 'معلومات الدور ومكان العمل المفضّل.'],
        ],
        'personal' => [
            'label' => ['en' => 'Personal information', 'ar' => 'المعلومات الشخصية'],
            'description' => ['en' => 'Basic contact information for this application.', 'ar' => 'معلومات التواصل الأساسية لهذا الطلب.'],
        ],
        'education' => [
            'label' => ['en' => 'Education & experience', 'ar' => 'التعليم والخبرة'],
            'description' => ['en' => 'Education, experience level, and relevant tasks.', 'ar' => 'التعليم ومستوى الخبرة والمهام ذات الصلة.'],
        ],
        'language' => [
            'label' => ['en' => 'Language', 'ar' => 'اللغة'],
            'description' => ['en' => 'Language proficiency needed for the role.', 'ar' => 'مستوى اللغة المطلوب للدور.'],
        ],
        'files' => [
            'label' => ['en' => 'Required files', 'ar' => 'الملفات المطلوبة'],
            'description' => ['en' => 'CV and optional supporting documents.', 'ar' => 'السيرة الذاتية والوثائق الداعمة الاختيارية.'],
        ],
        'salary' => [
            'label' => ['en' => 'Salary', 'ar' => 'الراتب'],
            'description' => ['en' => 'Applicant salary expectations.', 'ar' => 'توقعات المتقدم للراتب.'],
        ],
        'declaration' => [
            'label' => ['en' => 'Declaration', 'ar' => 'الإقرار'],
            'description' => ['en' => 'Privacy consent and response preferences.', 'ar' => 'الموافقة على الخصوصية وتفضيلات استلام الرد.'],
        ],
    ];

    /** @var array<string, array{label: array{en: string, ar: string}}> */
    public const TYPE_CATALOG = [
        'text' => ['label' => ['en' => 'Short answer', 'ar' => 'إجابة قصيرة']],
        'textarea' => ['label' => ['en' => 'Paragraph', 'ar' => 'فقرة']],
        'email' => ['label' => ['en' => 'Email', 'ar' => 'بريد إلكتروني']],
        'tel' => ['label' => ['en' => 'Phone', 'ar' => 'رقم هاتف']],
        'number' => ['label' => ['en' => 'Number', 'ar' => 'رقم']],
        'date' => ['label' => ['en' => 'Date', 'ar' => 'تاريخ']],
        'url' => ['label' => ['en' => 'Link', 'ar' => 'رابط']],
        'select' => ['label' => ['en' => 'Dropdown', 'ar' => 'قائمة منسدلة']],
        'radio' => ['label' => ['en' => 'Multiple choice', 'ar' => 'اختيار من متعدد']],
        'checkbox' => ['label' => ['en' => 'Checkbox', 'ar' => 'مربع اختيار']],
        'file' => ['label' => ['en' => 'File upload', 'ar' => 'رفع ملف']],
    ];

    /** @var array<string, array<string, mixed>> */
    public const FIELD_CATALOG = [
        'vacancy_city' => [
            'type' => 'select', 'required' => true, 'locked' => false, 'section' => 'vacancy',
            'label' => ['en' => 'Applying for a vacancy in the city', 'ar' => 'التقدم لشاغر في مدينة'],
            'help' => ['en' => '', 'ar' => ''],
            'options' => [
                ['value' => 'aleppo', 'label' => ['en' => 'Aleppo', 'ar' => 'حلب']],
                ['value' => 'homs', 'label' => ['en' => 'Homs', 'ar' => 'حمص']],
                ['value' => 'damascus', 'label' => ['en' => 'Damascus', 'ar' => 'دمشق']],
                ['value' => 'latakia', 'label' => ['en' => 'Latakia', 'ar' => 'اللاذقية']],
                ['value' => 'tartus', 'label' => ['en' => 'Tartus', 'ar' => 'طرطوس']],
            ],
        ],
        'full_name' => [
            'type' => 'text', 'required' => true, 'locked' => true, 'section' => 'personal',
            'label' => ['en' => 'Full name', 'ar' => 'الاسم الكامل'], 'help' => ['en' => '', 'ar' => ''],
        ],
        'email' => [
            'type' => 'email', 'required' => true, 'locked' => true, 'section' => 'personal',
            'label' => ['en' => 'Email address', 'ar' => 'البريد الإلكتروني'], 'help' => ['en' => '', 'ar' => ''],
        ],
        'phone' => [
            'type' => 'tel', 'required' => true, 'locked' => true, 'section' => 'personal',
            'label' => ['en' => 'Mobile number', 'ar' => 'رقم الموبايل'], 'help' => ['en' => '', 'ar' => ''],
        ],
        'city' => [
            'type' => 'text', 'required' => true, 'locked' => false, 'section' => 'personal',
            'label' => ['en' => 'Residence', 'ar' => 'مكان الإقامة'], 'help' => ['en' => '', 'ar' => ''],
        ],
        'date_of_birth' => [
            'type' => 'date', 'required' => true, 'locked' => false, 'section' => 'personal',
            'label' => ['en' => 'Date of birth', 'ar' => 'تاريخ الميلاد'], 'help' => ['en' => '', 'ar' => ''],
        ],
        'marital_status' => [
            'type' => 'radio', 'required' => true, 'locked' => false, 'section' => 'personal',
            'label' => ['en' => 'Marital status', 'ar' => 'الحالة الاجتماعية'], 'help' => ['en' => '', 'ar' => ''],
            'options' => [
                ['value' => 'single', 'label' => ['en' => 'Single', 'ar' => 'أعزب/عزباء']],
                ['value' => 'married', 'label' => ['en' => 'Married', 'ar' => 'متزوج/ة']],
            ],
        ],
        'education_level' => [
            'type' => 'radio', 'required' => true, 'locked' => false, 'section' => 'education',
            'label' => ['en' => 'Education', 'ar' => 'المستوى التعليمي'], 'help' => ['en' => '', 'ar' => ''],
            'options' => [
                ['value' => 'institute', 'label' => ['en' => 'Institute', 'ar' => 'معهد']],
                ['value' => 'university', 'label' => ['en' => 'University', 'ar' => 'جامعة']],
                ['value' => 'master', 'label' => ['en' => 'Master', 'ar' => 'ماجستير']],
                ['value' => 'doctorate', 'label' => ['en' => 'Doctorate', 'ar' => 'دكتوراه']],
            ],
        ],
        'experience_field' => [
            'type' => 'radio', 'required' => true, 'locked' => false, 'section' => 'education',
            'label' => ['en' => 'You have experience working in the field of', 'ar' => 'لديك خبرة في مجال'], 'help' => ['en' => '', 'ar' => ''],
            'options' => [
                ['value' => 'it', 'label' => ['en' => 'IT', 'ar' => 'تقنية المعلومات']],
                ['value' => 'programming', 'label' => ['en' => 'Programming', 'ar' => 'البرمجة']],
                ['value' => 'both', 'label' => ['en' => 'Both', 'ar' => 'كلاهما']],
            ],
        ],
        'experience_years' => [
            'type' => 'radio', 'required' => true, 'locked' => false, 'section' => 'education',
            'label' => ['en' => 'How many years of relevant experience do you have?', 'ar' => 'كم سنة من الخبرة ذات الصلة لديك؟'],
            'help' => ['en' => '', 'ar' => ''],
            'options' => [
                ['value' => 'less_than_1', 'label' => ['en' => 'Less than 1 year', 'ar' => 'أقل من سنة']],
                ['value' => '1_3', 'label' => ['en' => '1-3 years', 'ar' => '1-3 سنوات']],
                ['value' => '3_5', 'label' => ['en' => '3-5 years', 'ar' => '3-5 سنوات']],
                ['value' => '5_plus', 'label' => ['en' => '5+ years', 'ar' => 'أكثر من 5 سنوات']],
            ],
        ],
        'experience' => [
            'type' => 'textarea', 'required' => true, 'locked' => false, 'section' => 'education',
            'label' => ['en' => 'Tasks explanation', 'ar' => 'شرح المهام والخبرات'], 'help' => ['en' => '', 'ar' => ''],
        ],
        'education' => [
            'type' => 'textarea', 'required' => false, 'locked' => false, 'section' => 'education',
            'label' => ['en' => 'Education details', 'ar' => 'تفاصيل التعليم'], 'help' => ['en' => '', 'ar' => ''],
        ],
        'motivation' => [
            'type' => 'textarea', 'required' => false, 'locked' => false, 'section' => 'education',
            'label' => ['en' => 'Why are you interested in this role?', 'ar' => 'لماذا أنت مهتم بهذا الدور؟'], 'help' => ['en' => '', 'ar' => ''],
        ],
        'english_level' => [
            'type' => 'radio', 'required' => true, 'locked' => false, 'section' => 'language',
            'label' => ['en' => 'How do you rate your English language?', 'ar' => 'كيف تقيّم مستواك في اللغة الإنجليزية؟'],
            'help' => ['en' => '', 'ar' => ''],
            'options' => [
                ['value' => 'a1_a2', 'label' => ['en' => 'A1 - A2', 'ar' => 'A1 - A2']],
                ['value' => 'b1_b2', 'label' => ['en' => 'B1 - B2', 'ar' => 'B1 - B2']],
                ['value' => 'c1_c2', 'label' => ['en' => 'C1 - C2', 'ar' => 'C1 - C2']],
            ],
        ],
        'cv' => [
            'type' => 'file', 'required' => true, 'locked' => true, 'section' => 'files',
            'label' => ['en' => 'CV', 'ar' => 'السيرة الذاتية'],
            'help' => ['en' => 'PDF or Word document, maximum 10 MB.', 'ar' => 'ملف PDF أو Word، بحد أقصى 10 ميغابايت.'],
            'accept' => ['pdf', 'doc', 'docx'], 'multiple' => false, 'max_files' => 1,
        ],
        'photo' => [
            'type' => 'file', 'required' => false, 'locked' => false, 'section' => 'files',
            'label' => ['en' => 'Photo', 'ar' => 'الصورة الشخصية'],
            'help' => ['en' => 'JPG or PNG, maximum 10 MB.', 'ar' => 'ملف JPG أو PNG، بحد أقصى 10 ميغابايت.'],
            'accept' => ['jpg', 'jpeg', 'png'], 'multiple' => false, 'max_files' => 1,
        ],
        'portfolio' => [
            'type' => 'file', 'required' => false, 'locked' => false, 'section' => 'files',
            'label' => ['en' => 'Portfolio (if available)', 'ar' => 'نماذج الأعمال (إن وجدت)'],
            'help' => ['en' => 'Up to 5 PDF or image files.', 'ar' => 'حتى 5 ملفات PDF أو صور.'],
            'accept' => ['pdf', 'jpg', 'jpeg', 'png'], 'multiple' => true, 'max_files' => 5,
        ],
        'work_links' => [
            'type' => 'url', 'required' => false, 'locked' => false, 'section' => 'files',
            'label' => ['en' => 'Work link (if available)', 'ar' => 'رابط أعمال سابقة (إن وجد)'], 'help' => ['en' => '', 'ar' => ''],
        ],
        'recommendations' => [
            'type' => 'file', 'required' => false, 'locked' => false, 'section' => 'files',
            'label' => ['en' => 'Recommendations (if available)', 'ar' => 'التوصيات (إن وجدت)'],
            'help' => ['en' => 'Up to 5 PDF or image files.', 'ar' => 'حتى 5 ملفات PDF أو صور.'],
            'accept' => ['pdf', 'jpg', 'jpeg', 'png'], 'multiple' => true, 'max_files' => 5,
        ],
        'expected_salary' => [
            'type' => 'number', 'required' => false, 'locked' => false, 'section' => 'salary',
            'label' => ['en' => 'Expected salary', 'ar' => 'الراتب المتوقع'], 'help' => ['en' => '', 'ar' => ''],
        ],
        'privacy_consent' => [
            'type' => 'checkbox', 'required' => true, 'locked' => true, 'section' => 'declaration',
            'label' => [
                'en' => 'I consent to the collection and processing of my personal data for recruitment purposes only.',
                'ar' => 'أوافق على جمع ومعالجة بياناتي الشخصية لأغراض التوظيف فقط.',
            ],
            'help' => [
                'en' => 'Your information will be kept confidential and accessed only by authorized personnel.',
                'ar' => 'ستبقى معلوماتك سرية ولن يصل إليها إلا الأشخاص المخولون.',
            ],
        ],
        'response_copy' => [
            'type' => 'checkbox', 'required' => false, 'locked' => false, 'section' => 'declaration',
            'label' => ['en' => 'Send me a copy of my responses.', 'ar' => 'أرسل لي نسخة من إجاباتي.'], 'help' => ['en' => '', 'ar' => ''],
        ],
    ];

    /** @var array<int, string> */
    private const SAFE_UPLOAD_EXTENSIONS = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];

    /** @return array{application_form: ApplicationForm, version: ApplicationFormVersion, fingerprint: string, fields: array<int, array<string, mixed>>, privacy_notice_version: string} */
    public function openEditor(HiringCampaign $campaign, User $actor): array
    {
        return DB::transaction(function () use ($campaign, $actor): array {
            $campaign = $this->loadSource($campaign);
            $this->assertApprovedSource($campaign);
            $form = ApplicationForm::query()->firstOrCreate(
                ['campaign_id' => $campaign->getKey()],
                ['status' => 'draft', 'created_by_user_id' => $actor->getKey()],
            );
            $version = $this->editableVersion($form);
            if (! $version) {
                $version = $form->versions()->create([
                    'version_no' => 1,
                    'fields_json' => $this->defaultFields(),
                    'privacy_notice_version' => 'v1',
                    'status' => 'draft',
                ]);
            }

            return $this->state($form->fresh(), $version);
        });
    }

    /** @param array<int, array<string, mixed>> $fields */
    public function saveDraft(ApplicationForm $form, array $fields, string $privacyNoticeVersion, int $expectedVersionId, string $expectedFingerprint): ApplicationFormVersion
    {
        return DB::transaction(function () use ($form, $fields, $privacyNoticeVersion, $expectedVersionId, $expectedFingerprint): ApplicationFormVersion {
            $lockedForm = $this->lockForm($form);
            $this->assertApprovedSource($this->loadSource($lockedForm->campaign));
            $editable = $this->editableVersion($lockedForm, true);
            $this->assertExpectedVersion($editable, $expectedVersionId, $expectedFingerprint);

            return $this->persistDraft($lockedForm, $this->normalizeFields($fields), $this->normalizePrivacyVersion($privacyNoticeVersion));
        });
    }

    /** @param array<int, array<string, mixed>> $fields */
    public function publish(ApplicationForm $form, array $fields, string $privacyNoticeVersion, User $actor, int $expectedVersionId, string $expectedFingerprint): ApplicationFormVersion
    {
        return DB::transaction(function () use ($form, $fields, $privacyNoticeVersion, $actor, $expectedVersionId, $expectedFingerprint): ApplicationFormVersion {
            $lockedForm = $this->lockForm($form);
            $campaign = $this->loadSource($lockedForm->campaign);
            $this->assertApprovedSource($campaign);
            $fields = $this->normalizeFields($fields);
            $privacyNoticeVersion = $this->normalizePrivacyVersion($privacyNoticeVersion);
            $editable = $this->editableVersion($lockedForm, true);

            if ($editable && $editable->status === 'published' && $this->sameDefinition($editable, $fields, $privacyNoticeVersion)) {
                return $editable;
            }

            $this->assertExpectedVersion($editable, $expectedVersionId, $expectedFingerprint);
            $draft = $this->persistDraft($lockedForm, $fields, $privacyNoticeVersion);
            $draft->forceFill(['status' => 'published', 'published_at' => now()])->save();
            $lockedForm->forceFill(['status' => 'published', 'current_version_id' => $draft->getKey()])->save();

            $request = $campaign->vacancy->createdFromRequest;
            RecruitmentRequestEvent::query()->create([
                'recruitment_request_id' => $request->getKey(),
                'request_version_id' => $request->latestVersion?->getKey(),
                'event_type' => RecruitmentEventType::FormVersionPublished,
                'actor_user_id' => $actor->getKey(),
                'actor_role' => $actor->myRole()?->name ?? 'unknown',
                'comment' => 'Application form version '.$draft->version_no.' published.',
                'meta' => [
                    'campaign_id' => $campaign->getKey(),
                    'application_form_id' => $lockedForm->getKey(),
                    'application_form_version_id' => $draft->getKey(),
                ],
            ]);

            return $draft->fresh();
        });
    }

    /** @return array<string, mixed> */
    public function publicSchema(HiringCampaign $campaign, ?int $versionId = null): array
    {
        $form = $campaign->applicationForm;
        $version = $versionId
            ? $form?->versions()->whereKey($versionId)->where('status', 'published')->first()
            : $form?->currentVersion;

        if (! $version || $version->status !== 'published') {
            throw new DomainException('This campaign does not have the requested published application form.');
        }

        $fields = $this->normalizeFields($version->fields_json);

        return [
            'version' => $version->version_no,
            'privacy_notice_version' => (string) $version->privacy_notice_version,
            'sections' => $this->publicSections($fields),
            'fields' => array_map(fn (array $field): array => Arr::only($field, [
                'key', 'type', 'required', 'label', 'help', 'section', 'options', 'accept', 'multiple', 'max_files',
            ]), $fields),
        ];
    }

    /** @return array<string, mixed> */
    public function formContext(HiringCampaign $campaign): array
    {
        $campaign = $campaign->loadMissing([
            'vacancy.position',
            'vacancy.createdFromRequest.latestVersion.positionDetail',
        ]);
        $detail = $campaign->vacancy?->createdFromRequest?->latestVersion?->positionDetail;
        $position = $campaign->vacancy?->position;

        return [
            'title' => [
                'en' => $detail?->role_title_en ?: $position?->title_en ?: '',
                'ar' => $detail?->role_title_ar ?: $position?->title_ar ?: '',
            ],
            'weekly_hours' => $detail?->weekly_hours,
            'deadline' => $campaign->ends_at?->toDateString(),
            'responsibilities' => $this->localizedDetailText($detail, 'main_duties'),
            'qualifications' => $this->localizedDetailText($detail, 'required_qualifications'),
        ];
    }

    /**
     * Published forms for the same position profile can be reused across cities.
     * Only public form metadata is returned; applicant and campaign data stay private.
     *
     * @return array<int, array<string, mixed>>
     */
    public function availableTemplates(HiringCampaign $campaign): array
    {
        $campaign->loadMissing(['applicationForm', 'vacancy.position']);
        $targetPosition = $campaign->vacancy?->position;

        if (! $targetPosition) {
            return [];
        }

        return ApplicationForm::query()
            ->where('campaign_id', '!=', $campaign->getKey())
            ->whereNotNull('current_version_id')
            ->whereHas('currentVersion', fn ($query) => $query->where('status', 'published'))
            ->with([
                'currentVersion',
                'campaign.vacancy.position.city',
                'campaign.vacancy.position.center.city',
            ])
            ->latest('updated_at')
            ->limit(200)
            ->get()
            ->filter(function (ApplicationForm $form) use ($targetPosition): bool {
                return $this->samePositionProfile(
                    $targetPosition,
                    $form->campaign?->vacancy?->position,
                );
            })
            ->take(50)
            ->map(function (ApplicationForm $form): array {
                $version = $form->currentVersion;
                $position = $form->campaign?->vacancy?->position;
                $city = $position?->city ?? $position?->center?->city;

                return [
                    'id' => $version->getKey(),
                    'title' => [
                        'en' => (string) ($position?->title_en ?? ''),
                        'ar' => (string) ($position?->title_ar ?? ''),
                    ],
                    'city' => [
                        'en' => (string) ($city?->name_en ?? ''),
                        'ar' => (string) ($city?->name_ar ?? ''),
                    ],
                    'version' => $version->version_no,
                    'published_at' => $version->published_at?->toDateString(),
                ];
            })
            ->values()
            ->all();
    }

    /** @return array{fields: array<int, array<string, mixed>>, privacy_notice_version: string} */
    public function copyPublishedTemplate(HiringCampaign $campaign, int $versionId): array
    {
        $campaign->loadMissing('vacancy.position');
        $version = ApplicationFormVersion::query()
            ->with([
                'applicationForm.campaign.vacancy.position',
            ])
            ->find($versionId);

        $sourceForm = $version?->applicationForm;
        $sourceCampaign = $sourceForm?->campaign;
        if (
            ! $version
            || $version->status !== 'published'
            || (int) $sourceForm?->current_version_id !== $versionId
            || (int) $sourceCampaign?->getKey() === (int) $campaign->getKey()
            || ! $this->samePositionProfile($campaign->vacancy?->position, $sourceCampaign?->vacancy?->position)
        ) {
            throw new DomainException('The selected published application form is not available for this position.');
        }

        return [
            'fields' => $this->normalizeFields($version->fields_json),
            'privacy_notice_version' => $this->normalizePrivacyVersion((string) $version->privacy_notice_version),
        ];
    }

    /** @param array<string, mixed> $answers @return array<string, mixed> */
    public function validateSubmission(ApplicationFormVersion $version, array $answers): array
    {
        if ($version->status !== 'published') {
            throw new DomainException('Applications must use a published form version.');
        }

        $fields = $this->normalizeFields($version->fields_json);
        $keys = array_column($fields, 'key');
        $rules = ['answers' => ['required', 'array:'.implode(',', $keys)]];
        $attributes = [];

        foreach ($fields as $field) {
            $path = 'answers.'.$field['key'];
            $label = $field['label'][app()->getLocale()] ?? '';
            $attributes[$path] = $attributes[$path.'.*'] = $label !== '' ? $label : $field['label']['en'];
            $presence = $field['required'] ? ['required'] : ['nullable'];
            $options = array_column($field['options'], 'value');

            if ($field['type'] === 'file' && $field['multiple']) {
                // An untouched `name="x[]"` file input still posts one empty
                // part, which arrives as [null]; that means "no files".
                if (is_array($answers[$field['key']] ?? null)) {
                    $answers[$field['key']] = array_values(array_filter(
                        $answers[$field['key']],
                        fn (mixed $upload): bool => $upload !== null,
                    )) ?: null;
                }

                $rules[$path] = [...$presence, 'array', 'max:'.$field['max_files']];
                $rules[$path.'.*'] = ['file', 'mimes:'.implode(',', $field['accept']), 'max:10240'];

                continue;
            }

            $rules[$path] = match (true) {
                $field['key'] === 'phone' => [...$presence, 'regex:/\A09[0-9]{8}\z/'],
                $field['key'] === 'date_of_birth' => [...$presence, 'date_format:Y-m-d', 'before:today'],
                $field['type'] === 'email' => [...$presence, 'email:rfc', 'max:191'],
                $field['type'] === 'tel' => [...$presence, 'string', 'max:40'],
                $field['type'] === 'number' => [...$presence, 'numeric', 'min:0'],
                $field['type'] === 'date' => [...$presence, 'date'],
                $field['type'] === 'url' => [...$presence, 'url:http,https', 'max:2048'],
                in_array($field['type'], ['select', 'radio'], true) => [...$presence, Rule::in($options)],
                $field['type'] === 'checkbox' => $field['required'] ? ['accepted'] : ['nullable', 'boolean'],
                $field['type'] === 'file' => [...$presence, 'file', 'mimes:'.implode(',', $field['accept']), 'max:10240'],
                $field['type'] === 'textarea' => [...$presence, 'string', 'max:5000'],
                default => [...$presence, 'string', 'max:255'],
            };
        }

        return Validator::make(['answers' => $answers], $rules, [
            'answers.phone.regex' => __('careers.errors.invalid_phone'),
            'answers.date_of_birth.before' => __('careers.errors.invalid_birth_date'),
            'answers.date_of_birth.date_format' => __('careers.errors.invalid_birth_date'),
        ], $attributes)->validate()['answers'];
    }

    /** @return array<int, array<string, mixed>> */
    public function defaultFields(): array
    {
        return $this->normalizeFields(array_map(
            fn (string $key): array => ['key' => $key, ...self::FIELD_CATALOG[$key]],
            array_keys(self::FIELD_CATALOG),
        ));
    }

    /** @return array<string, mixed> */
    public function makeCustomField(
        string $type,
        string $section,
        string $labelEn,
        string $labelAr,
        string $helpEn = '',
        string $helpAr = '',
        bool $required = false,
        array $options = [],
    ): array {
        return $this->normalizeFields([[
            'key' => 'custom_'.Str::lower(Str::random(12)),
            'type' => $type,
            'section' => $section,
            'required' => $required,
            'locked' => false,
            'label' => ['en' => $labelEn, 'ar' => $labelAr],
            'help' => ['en' => $helpEn, 'ar' => $helpAr],
            'options' => $options,
        ]])[0];
    }

    public function fingerprint(ApplicationFormVersion $version): string
    {
        return hash('sha256', json_encode([
            'id' => $version->getKey(), 'fields' => $this->normalizeFields($version->fields_json),
            'privacy_notice_version' => $version->privacy_notice_version, 'status' => $version->status,
            'published_at' => $version->published_at?->toIso8601String(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<int, array<string, mixed>> $fields @return array<int, array<string, mixed>> */
    public function normalizeFields(array $fields): array
    {
        $normalized = [];
        foreach ($fields as $field) {
            $key = Str::lower(trim((string) ($field['key'] ?? '')));
            $catalog = self::FIELD_CATALOG[$key] ?? null;
            $isCustom = preg_match('/^custom_[a-z0-9_]{8,64}$/', $key) === 1;

            if ((! $catalog && ! $isCustom) || isset($normalized[$key])) {
                continue;
            }

            $type = $catalog['type'] ?? (string) ($field['type'] ?? 'text');
            $type = isset(self::TYPE_CATALOG[$type]) ? $type : 'text';
            $section = (string) ($field['section'] ?? ($catalog['section'] ?? 'personal'));
            $section = isset(self::SECTION_CATALOG[$section]) ? $section : 'personal';
            $locked = (bool) ($catalog['locked'] ?? false);
            $required = $locked
                ? (bool) ($catalog['required'] ?? true)
                : (bool) ($field['required'] ?? ($catalog['required'] ?? false));
            $fallbackOptions = $catalog['options'] ?? [];
            $options = in_array($type, ['select', 'radio'], true)
                ? $this->normalizeOptions((array) ($field['options'] ?? $fallbackOptions), $fallbackOptions)
                : [];
            $accept = $type === 'file'
                ? $this->normalizeAccept((array) ($field['accept'] ?? ($catalog['accept'] ?? self::SAFE_UPLOAD_EXTENSIONS)))
                : [];

            $normalized[$key] = [
                'key' => $key,
                'type' => $type,
                'required' => $required,
                'locked' => $locked,
                'section' => $section,
                'label' => $this->localizedText((array) ($field['label'] ?? []), (array) ($catalog['label'] ?? []), 180),
                'help' => $this->localizedText((array) ($field['help'] ?? []), (array) ($catalog['help'] ?? []), 500),
                'options' => $options,
                'accept' => $accept,
                'multiple' => $type === 'file' && (bool) ($field['multiple'] ?? ($catalog['multiple'] ?? false)),
                'max_files' => $type === 'file'
                    ? min(5, max(1, (int) ($field['max_files'] ?? ($catalog['max_files'] ?? 1))))
                    : 1,
            ];
        }

        foreach (self::FIELD_CATALOG as $key => $catalog) {
            if (($catalog['locked'] ?? false) && ! isset($normalized[$key])) {
                $type = (string) $catalog['type'];
                $normalized[$key] = [
                    'key' => $key,
                    'type' => $type,
                    'required' => (bool) $catalog['required'],
                    'locked' => true,
                    'section' => (string) $catalog['section'],
                    'label' => $this->localizedText((array) $catalog['label'], [], 180),
                    'help' => $this->localizedText((array) ($catalog['help'] ?? []), [], 500),
                    'options' => in_array($type, ['select', 'radio'], true)
                        ? $this->normalizeOptions((array) ($catalog['options'] ?? []), [])
                        : [],
                    'accept' => $type === 'file'
                        ? $this->normalizeAccept((array) ($catalog['accept'] ?? self::SAFE_UPLOAD_EXTENSIONS))
                        : [],
                    'multiple' => $type === 'file' && (bool) ($catalog['multiple'] ?? false),
                    'max_files' => $type === 'file' ? (int) ($catalog['max_files'] ?? 1) : 1,
                ];
            }
        }

        return array_values($normalized);
    }

    /** @param array<int, array<string, mixed>> $fields @return array<int, array<string, mixed>> */
    private function publicSections(array $fields): array
    {
        $used = array_flip(array_column($fields, 'section'));

        return collect(self::SECTION_CATALOG)
            ->filter(fn (array $section, string $key): bool => isset($used[$key]))
            ->map(fn (array $section, string $key): array => ['key' => $key, ...$section])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function state(ApplicationForm $form, ApplicationFormVersion $version): array
    {
        return [
            'application_form' => $form, 'version' => $version, 'fingerprint' => $this->fingerprint($version),
            'fields' => $this->normalizeFields($version->fields_json),
            'privacy_notice_version' => (string) ($version->privacy_notice_version ?: 'v1'),
        ];
    }

    /** @param array<int, array<string, mixed>> $fields */
    private function persistDraft(ApplicationForm $form, array $fields, string $privacyVersion): ApplicationFormVersion
    {
        $draft = $form->versions()->where('status', 'draft')->lockForUpdate()->latest('version_no')->first();
        $current = $form->current_version_id ? ApplicationFormVersion::query()->find($form->current_version_id) : null;
        if (! $draft && $current && $this->sameDefinition($current, $fields, $privacyVersion)) {
            return $current;
        }
        if ($draft) {
            $draft->forceFill(['fields_json' => $fields, 'privacy_notice_version' => $privacyVersion])->save();

            return $draft->fresh();
        }

        return $form->versions()->create([
            'version_no' => ((int) $form->versions()->max('version_no')) + 1,
            'fields_json' => $fields, 'privacy_notice_version' => $privacyVersion, 'status' => 'draft',
        ]);
    }

    private function editableVersion(ApplicationForm $form, bool $lock = false): ?ApplicationFormVersion
    {
        $query = $form->versions()->where('status', 'draft')->latest('version_no');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first() ?? $form->currentVersion;
    }

    private function assertExpectedVersion(?ApplicationFormVersion $version, int $expectedId, string $expectedFingerprint): void
    {
        if (! $version || (int) $version->getKey() !== $expectedId || ! hash_equals($this->fingerprint($version), $expectedFingerprint)) {
            throw new StaleRecruitmentDraftException;
        }
    }

    private function lockForm(ApplicationForm $form): ApplicationForm
    {
        return ApplicationForm::query()->with('campaign')->lockForUpdate()->findOrFail($form->getKey());
    }

    private function loadSource(HiringCampaign $campaign): HiringCampaign
    {
        return $campaign->loadMissing(['applicationForm.currentVersion', 'vacancy.createdFromRequest.latestVersion']);
    }

    private function assertApprovedSource(HiringCampaign $campaign): void
    {
        $request = $campaign->vacancy?->createdFromRequest;
        if (! $request || ! in_array((int) $request->status, [RecruitmentRequestStatus::Approved, RecruitmentRequestStatus::Applied], true)) {
            throw new DomainException('An application form can only be generated from an approved recruitment request.');
        }
    }

    /** @param array<int, array<string, mixed>> $fields */
    private function sameDefinition(ApplicationFormVersion $version, array $fields, string $privacyVersion): bool
    {
        return $this->normalizeFields($version->fields_json) === $fields
            && (string) $version->privacy_notice_version === $privacyVersion;
    }

    private function normalizePrivacyVersion(string $value): string
    {
        $value = mb_substr(trim($value), 0, 255);

        return $value === '' ? 'v1' : $value;
    }

    /** @return array{en: string, ar: string} */
    private function localizedDetailText(mixed $detail, string $field): array
    {
        if (! $detail) {
            return ['en' => '', 'ar' => ''];
        }

        $raw = $detail->{$field} ?? '';
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
        if (is_array($decoded)) {
            $fallback = trim((string) ($decoded['en'] ?? $decoded['ar'] ?? ''));

            return [
                'en' => trim((string) ($decoded['en'] ?? $fallback)),
                'ar' => trim((string) ($decoded['ar'] ?? $fallback)),
            ];
        }

        $fallback = trim((string) $raw);

        return [
            'en' => trim((string) ($detail->{$field.'_en'} ?? $fallback)),
            'ar' => trim((string) ($detail->{$field.'_ar'} ?? $fallback)),
        ];
    }

    private function samePositionProfile(?Position $target, ?Position $source): bool
    {
        if (! $target || ! $source) {
            return false;
        }

        if ($target->is($source)) {
            return true;
        }

        $sameTitle = collect(['title_en', 'title_ar'])->contains(function (string $attribute) use ($target, $source): bool {
            $targetTitle = Str::lower(trim((string) $target->{$attribute}));
            $sourceTitle = Str::lower(trim((string) $source->{$attribute}));

            return $targetTitle !== '' && $targetTitle === $sourceTitle;
        });

        return $sameTitle
            && (int) $target->department_id === (int) $source->department_id
            && (int) $target->job_level_id === (int) $source->job_level_id;
    }

    /** @param array<string, mixed> $value @param array<string, mixed> $fallback @return array{en: string, ar: string} */
    private function localizedText(array $value, array $fallback, int $limit): array
    {
        return [
            'en' => mb_substr(trim((string) ($value['en'] ?? $fallback['en'] ?? '')), 0, $limit),
            'ar' => mb_substr(trim((string) ($value['ar'] ?? $fallback['ar'] ?? '')), 0, $limit),
        ];
    }

    /** @param array<int, mixed> $options @param array<int, mixed> $fallback @return array<int, array<string, mixed>> */
    private function normalizeOptions(array $options, array $fallback): array
    {
        if ($options === []) {
            $options = $fallback;
        }

        $normalized = [];
        foreach (array_slice($options, 0, 20) as $index => $option) {
            if (! is_array($option)) {
                continue;
            }
            $label = $this->localizedText((array) ($option['label'] ?? []), [], 120);
            if ($label['en'] === '' && $label['ar'] === '') {
                continue;
            }
            $value = Str::lower(trim((string) ($option['value'] ?? '')));
            $value = preg_replace('/[^a-z0-9_\-]/', '_', $value) ?: 'option_'.($index + 1);
            $value = mb_substr($value, 0, 80);
            if (isset($normalized[$value])) {
                $value .= '_'.($index + 1);
            }
            $normalized[$value] = ['value' => $value, 'label' => $label];
        }

        return array_values($normalized);
    }

    /** @param array<int, mixed> $accept @return array<int, string> */
    private function normalizeAccept(array $accept): array
    {
        $safe = array_values(array_intersect(
            self::SAFE_UPLOAD_EXTENSIONS,
            array_map(fn (mixed $extension): string => Str::lower(trim((string) $extension)), $accept),
        ));

        return $safe === [] ? self::SAFE_UPLOAD_EXTENSIONS : $safe;
    }
}
