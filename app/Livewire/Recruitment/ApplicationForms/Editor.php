<?php

namespace App\Livewire\Recruitment\ApplicationForms;

use App\Exceptions\StaleRecruitmentDraftException;
use App\Livewire\Concerns\WithFeedback;
use App\Models\ApplicationForm;
use App\Models\ApplicationFormVersion;
use App\Models\HiringCampaign;
use App\Services\RecruitmentApplicationFormService;
use DomainException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Editor extends Component
{
    use WithFeedback;

    public HiringCampaign $campaign;

    public ApplicationForm $applicationForm;

    /** @var array<int, array<string, mixed>> */
    public array $fields = [];

    public string $privacyNoticeVersion = 'v1';

    public string $previewLocale = 'en';

    public string $previewSection = 'vacancy';

    public string $expandedSection = 'vacancy';

    public string $customType = 'text';

    public string $customSection = 'personal';

    public string $customLabelEn = '';

    public string $customLabelAr = '';

    public string $customHelpEn = '';

    public string $customHelpAr = '';

    public string $customOptionsEn = '';

    public string $customOptionsAr = '';

    public bool $customRequired = false;

    public string $selectedTemplateVersionId = '';

    public int $versionId;

    public string $versionFingerprint = '';

    public int $versionNumber = 1;

    public string $versionStatus = 'draft';

    public ?int $publishedVersionNumber = null;

    public ?string $notice = null;

    public string $noticeType = 'success';

    public bool $hasConflict = false;

    /** Set by a successful publish, so the page can point at what comes next. */
    public bool $justPublished = false;

    public function mount(HiringCampaign $campaign, RecruitmentApplicationFormService $service): void
    {
        $existing = $campaign->applicationForm()->first();
        $existing ? Gate::authorize('manage', $existing) : Gate::authorize('create', [ApplicationForm::class, $campaign]);

        try {
            $this->applyState($service->openEditor($campaign, auth()->user()));
        } catch (DomainException $exception) {
            abort(409, $exception->getMessage());
        }
        $this->campaign = $campaign;
        $this->previewLocale = in_array(app()->getLocale(), ['en', 'ar'], true) ? app()->getLocale() : 'en';
        $this->previewSection = $this->firstUsedSection();
        $this->expandedSection = $this->previewSection;
    }

    public function addField(string $key): void
    {
        Gate::authorize('manage', $this->applicationForm);
        if (! isset(RecruitmentApplicationFormService::FIELD_CATALOG[$key]) || collect($this->fields)->contains('key', $key)) {
            return;
        }

        $definition = RecruitmentApplicationFormService::FIELD_CATALOG[$key];
        $this->fields[] = ['key' => $key, ...$definition];
        $this->previewSection = (string) $definition['section'];
        $this->expandedSection = $this->previewSection;
        $this->clearNotice();
    }

    public function addCustomField(RecruitmentApplicationFormService $service): void
    {
        Gate::authorize('manage', $this->applicationForm);
        $rules = [
            'customType' => ['required', Rule::in(array_keys(RecruitmentApplicationFormService::TYPE_CATALOG))],
            'customSection' => ['required', Rule::in(array_keys(RecruitmentApplicationFormService::SECTION_CATALOG))],
            'customLabelEn' => ['required', 'string', 'max:180'],
            'customLabelAr' => ['required', 'string', 'max:180'],
            'customHelpEn' => ['nullable', 'string', 'max:500'],
            'customHelpAr' => ['nullable', 'string', 'max:500'],
            'customRequired' => ['boolean'],
        ];
        if (in_array($this->customType, ['select', 'radio'], true)) {
            $rules['customOptionsEn'] = ['required', 'string', 'max:2000'];
            $rules['customOptionsAr'] = ['nullable', 'string', 'max:2000'];
        }
        $this->validate($rules);

        $this->fields[] = $service->makeCustomField(
            $this->customType,
            $this->customSection,
            $this->customLabelEn,
            $this->customLabelAr,
            $this->customHelpEn,
            $this->customHelpAr,
            $this->customRequired,
            $this->customOptions(),
        );
        $this->previewSection = $this->customSection;
        $this->expandedSection = $this->customSection;
        $this->resetCustomComposer();
        $this->clearNotice();
    }

    public function applyReferenceTemplate(RecruitmentApplicationFormService $service): void
    {
        Gate::authorize('manage', $this->applicationForm);
        $this->fields = $service->defaultFields();
        $this->previewSection = 'vacancy';
        $this->expandedSection = 'vacancy';
        $this->resetValidation();
        $this->notice = __('recruitment.application_form.template_applied');
        $this->noticeType = 'info';
        $this->hasConflict = false;
        $this->toast($this->notice, 'info');
    }

    public function applyPublishedTemplate(RecruitmentApplicationFormService $service): void
    {
        Gate::authorize('manage', $this->applicationForm);
        $this->validate([
            'selectedTemplateVersionId' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $template = $service->copyPublishedTemplate($this->campaign, (int) $this->selectedTemplateVersionId);
            $this->fields = $template['fields'];
            $this->privacyNoticeVersion = $template['privacy_notice_version'];
            $this->previewSection = $this->firstUsedSection();
            $this->expandedSection = $this->previewSection;
            $this->resetValidation();
            $this->notice = __('recruitment.application_form.reused_success');
            $this->noticeType = 'info';
            $this->hasConflict = false;
            $this->toast($this->notice, 'info');
        } catch (DomainException $exception) {
            $this->notice = __('recruitment.application_form.reuse_unavailable');
            $this->noticeType = 'error';
            $this->hasConflict = false;
            $this->toast($this->notice, 'error');
        }
    }

    public function removeField(int $index): void
    {
        Gate::authorize('manage', $this->applicationForm);
        if (! isset($this->fields[$index]) || ($this->fields[$index]['locked'] ?? true) === true) {
            return;
        }

        array_splice($this->fields, $index, 1);
        $this->previewSection = $this->firstUsedSection();
        $this->clearNotice();
    }

    public function moveField(int $index, int $direction): void
    {
        Gate::authorize('manage', $this->applicationForm);
        if (! isset($this->fields[$index]) || ! in_array($direction, [-1, 1], true)) {
            return;
        }

        $section = $this->fields[$index]['section'];
        $candidate = $index + $direction;
        while (isset($this->fields[$candidate]) && $this->fields[$candidate]['section'] !== $section) {
            $candidate += $direction;
        }
        if (! isset($this->fields[$candidate])) {
            return;
        }

        [$this->fields[$index], $this->fields[$candidate]] = [$this->fields[$candidate], $this->fields[$index]];
        $this->clearNotice();
    }

    public function addOption(int $fieldIndex): void
    {
        Gate::authorize('manage', $this->applicationForm);
        if (! isset($this->fields[$fieldIndex]) || ! in_array($this->fields[$fieldIndex]['type'], ['select', 'radio'], true)) {
            return;
        }

        $optionNumber = count($this->fields[$fieldIndex]['options'] ?? []) + 1;
        $this->fields[$fieldIndex]['options'][] = [
            'value' => 'option_'.$optionNumber,
            'label' => ['en' => 'Option '.$optionNumber, 'ar' => 'الخيار '.$optionNumber],
        ];
        $this->clearNotice();
    }

    public function removeOption(int $fieldIndex, int $optionIndex): void
    {
        Gate::authorize('manage', $this->applicationForm);
        if (! isset($this->fields[$fieldIndex]['options'][$optionIndex]) || count($this->fields[$fieldIndex]['options']) <= 1) {
            return;
        }

        array_splice($this->fields[$fieldIndex]['options'], $optionIndex, 1);
        $this->clearNotice();
    }

    public function setPreviewSection(string $section): void
    {
        if (isset(RecruitmentApplicationFormService::SECTION_CATALOG[$section])) {
            $this->previewSection = $section;
        }
    }

    public function toggleSection(string $section): void
    {
        if (! isset(RecruitmentApplicationFormService::SECTION_CATALOG[$section])) {
            return;
        }

        if ($this->expandedSection === $section) {
            $this->expandedSection = '';

            return;
        }

        $this->expandedSection = $section;
        $this->previewSection = $section;
    }

    public function setPreviewLocale(string $locale): void
    {
        if (in_array($locale, ['en', 'ar'], true)) {
            $this->previewLocale = $locale;
        }
    }

    public function saveDraft(RecruitmentApplicationFormService $service): void
    {
        Gate::authorize('manage', $this->applicationForm);
        $this->validateEditor();
        try {
            $version = $service->saveDraft(
                $this->applicationForm, $this->fields, $this->privacyNoticeVersion, $this->versionId, $this->versionFingerprint,
            );
            $this->syncVersion($version, $service);
            $this->success(__('recruitment.application_form.saved'));
        } catch (StaleRecruitmentDraftException) {
            $this->conflict();
        }
    }

    public function updating(string $name): void
    {
        // Editing the questions makes the "published" prompt out of date.
        if (str_starts_with($name, 'fields') || $name === 'privacyNoticeVersion') {
            $this->justPublished = false;
        }
    }

    public function publish(RecruitmentApplicationFormService $service): void
    {
        Gate::authorize('publish', $this->applicationForm);
        $this->validateEditor();
        try {
            $version = $service->publish(
                $this->applicationForm, $this->fields, $this->privacyNoticeVersion, auth()->user(), $this->versionId, $this->versionFingerprint,
            );
            $this->syncVersion($version, $service);
            $this->success(__('recruitment.application_form.published', ['version' => $version->version_no]));
            $this->justPublished = true;
        } catch (StaleRecruitmentDraftException) {
            $this->conflict();
        }
    }

    public function reloadLatest(RecruitmentApplicationFormService $service): void
    {
        Gate::authorize('manage', $this->applicationForm);
        $this->applyState($service->openEditor($this->campaign, auth()->user()));
        $this->previewSection = $this->firstUsedSection();
        $this->expandedSection = $this->previewSection;
        $this->hasConflict = false;
        $this->notice = __('recruitment.application_form.reloaded');
        $this->noticeType = 'info';
        $this->toast($this->notice, 'info');
    }

    public function render(RecruitmentApplicationFormService $service)
    {
        $used = array_column($this->fields, 'key');
        $sections = RecruitmentApplicationFormService::SECTION_CATALOG;

        return view('livewire.recruitment.application-forms.editor', [
            'nextStep' => $this->justPublished ? [
                'message' => __('recruitment.publishing.next_after_form'),
                'label' => __('recruitment.publishing.back'),
                'url' => route('recruitment.campaigns.show', ['campaignId' => $this->campaign->id]).'#step-link',
            ] : null,
            'availableFields' => collect(RecruitmentApplicationFormService::FIELD_CATALOG)->except($used),
            'sections' => $sections,
            'types' => RecruitmentApplicationFormService::TYPE_CATALOG,
            'formContext' => $service->formContext($this->campaign),
            'availableTemplates' => $service->availableTemplates($this->campaign),
        ]);
    }

    private function validateEditor(): void
    {
        $this->validate([
            'privacyNoticeVersion' => ['required', 'string', 'max:255'],
            'fields' => ['required', 'array', 'min:5', 'max:60'],
            'fields.*.label.en' => ['required', 'string', 'max:180'],
            'fields.*.label.ar' => ['required', 'string', 'max:180'],
            'fields.*.help.en' => ['nullable', 'string', 'max:500'],
            'fields.*.help.ar' => ['nullable', 'string', 'max:500'],
            'fields.*.required' => ['boolean'],
            'fields.*.section' => ['required', Rule::in(array_keys(RecruitmentApplicationFormService::SECTION_CATALOG))],
            'fields.*.type' => ['required', Rule::in(array_keys(RecruitmentApplicationFormService::TYPE_CATALOG))],
            'fields.*.options' => ['array', 'max:20'],
            'fields.*.options.*.label.en' => ['required_with:fields.*.options', 'string', 'max:120'],
            'fields.*.options.*.label.ar' => ['required_with:fields.*.options', 'string', 'max:120'],
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function customOptions(): array
    {
        if (! in_array($this->customType, ['select', 'radio'], true)) {
            return [];
        }

        $english = $this->lines($this->customOptionsEn);
        $arabic = $this->lines($this->customOptionsAr);

        return collect($english)
            ->take(20)
            ->values()
            ->map(fn (string $label, int $index): array => [
                'value' => 'option_'.($index + 1),
                'label' => ['en' => $label, 'ar' => $arabic[$index] ?? $label],
            ])
            ->all();
    }

    /** @return array<int, string> */
    private function lines(string $value): array
    {
        return collect(preg_split('/\R/u', $value) ?: [])
            ->map(fn (string $line): string => trim($line))
            ->filter()
            ->values()
            ->all();
    }

    private function resetCustomComposer(): void
    {
        $this->reset([
            'customLabelEn', 'customLabelAr', 'customHelpEn', 'customHelpAr',
            'customOptionsEn', 'customOptionsAr', 'customRequired',
        ]);
        $this->customType = 'text';
    }

    /** @param array<string, mixed> $state */
    private function applyState(array $state): void
    {
        $this->applicationForm = $state['application_form'];
        $this->fields = $state['fields'];
        $this->privacyNoticeVersion = $state['privacy_notice_version'];
        $this->versionFingerprint = $state['fingerprint'];
        $this->setVersionState($state['version']);
    }

    private function syncVersion(ApplicationFormVersion $version, RecruitmentApplicationFormService $service): void
    {
        $this->applicationForm->refresh();
        $this->fields = $service->normalizeFields($version->fields_json);
        $this->versionFingerprint = $service->fingerprint($version);
        $this->setVersionState($version);
        $this->hasConflict = false;
    }

    private function setVersionState(ApplicationFormVersion $version): void
    {
        $this->versionId = $version->getKey();
        $this->versionNumber = $version->version_no;
        $this->versionStatus = $version->status;
        $this->publishedVersionNumber = $this->applicationForm->fresh()->currentVersion?->version_no;
    }

    private function firstUsedSection(): string
    {
        foreach (array_keys(RecruitmentApplicationFormService::SECTION_CATALOG) as $section) {
            if (collect($this->fields)->contains('section', $section)) {
                return $section;
            }
        }

        return 'personal';
    }

    private function clearNotice(): void
    {
        $this->notice = null;
        $this->hasConflict = false;
    }

    private function success(string $message): void
    {
        $this->notice = $message;
        $this->noticeType = 'success';
        $this->hasConflict = false;
        $this->toast($message);
    }

    private function conflict(): void
    {
        $this->notice = __('recruitment.application_form.conflict');
        $this->noticeType = 'error';
        $this->hasConflict = true;
        $this->toast($this->notice, 'error');
    }
}
