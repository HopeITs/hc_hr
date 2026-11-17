<?php

namespace App\Mail;

use App\Models\RecruitmentApplication;
use App\Services\RecruitmentApplicationFormService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class RecruitmentResponseReceiptMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly RecruitmentApplication $application) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('recruitment-applicant.response_receipt.subject', [
                'position' => $this->positionTitle(),
            ], $this->preferredLocale()),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.recruitment.response-receipt',
            with: [
                'locale' => $this->preferredLocale(),
                'name' => $this->application->applicant?->full_name,
                'position' => $this->positionTitle(),
                'submittedAt' => $this->submittedAt(),
                'responses' => $this->responses(),
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }

    private function preferredLocale(): string
    {
        $locale = data_get($this->application->consent_payload_json, 'locale', 'en');

        return in_array($locale, ['en', 'ar'], true) ? $locale : 'en';
    }

    private function positionTitle(): string
    {
        $position = $this->application->campaign?->vacancy?->position;
        $locale = $this->preferredLocale();

        return (string) ($locale === 'ar'
            ? ($position?->title_ar ?: $position?->title_en)
            : ($position?->title_en ?: $position?->title_ar));
    }

    private function submittedAt(): string
    {
        $submittedAt = $this->application->submitted_at ?? $this->application->created_at;

        return $submittedAt instanceof Carbon
            ? $submittedAt->copy()->setTimezone(config('app.timezone'))->locale($this->preferredLocale())->translatedFormat('d M Y, H:i')
            : (string) $submittedAt;
    }

    /** @return array<int, array{label: string, value: string}> */
    private function responses(): array
    {
        $locale = $this->preferredLocale();
        $fields = app(RecruitmentApplicationFormService::class)->normalizeFields(
            (array) ($this->application->formVersion?->fields_json ?? []),
        );
        $answers = $this->application->answers->keyBy('field_key');
        $files = $this->application->files->groupBy('file_type');
        $responses = [];

        foreach ($fields as $field) {
            if (in_array($field['key'], ['privacy_consent', 'response_copy'], true)) {
                continue;
            }

            $value = $field['type'] === 'file'
                ? $files->get($field['key'], collect())->map(fn ($file): string => (string) ($file->getFirstMedia('document')?->name ?: __('recruitment-applicant.response_receipt.file_uploaded', [], $locale)))->implode(', ')
                : $this->formatAnswer($field, $answers->get($field['key'])?->answer_json['value'] ?? null, $locale);

            $responses[] = [
                'label' => (string) ($field['label'][$locale] ?: $field['label']['en']),
                'value' => $value !== '' ? $value : __('recruitment-applicant.response_receipt.no_answer', [], $locale),
            ];
        }

        return $responses;
    }

    /** @param array<string, mixed> $field */
    private function formatAnswer(array $field, mixed $value, string $locale): string
    {
        if (is_bool($value)) {
            return $value
                ? __('recruitment-applicant.response_receipt.yes', [], $locale)
                : __('recruitment-applicant.response_receipt.no', [], $locale);
        }

        if (is_array($value)) {
            return collect($value)->map(fn (mixed $item): string => (string) $item)->implode(', ');
        }

        if (in_array($field['type'], ['select', 'radio'], true)) {
            $option = collect($field['options'])->firstWhere('value', $value);

            return (string) ($option['label'][$locale] ?? $option['label']['en'] ?? $value ?? '');
        }

        return trim((string) ($value ?? ''));
    }
}
