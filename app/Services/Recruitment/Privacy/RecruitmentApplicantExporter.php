<?php

namespace App\Services\Recruitment\Privacy;

use App\Exceptions\RecruitmentExportRefusedException;
use App\Models\City;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentPrivacyAudit;
use App\Models\User;
use App\Services\Monitoring\SecurityMonitor;
use App\Services\RecruitmentApplicationFilters;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Candidate data leaving the system as a file.
 *
 * prepare() is where every rule lives, in order: the explicit permission, a
 * filter that names nothing outside the caller's scope, the hourly limit, the
 * size cap. Whichever refuses is audited and reported to monitoring before the
 * exception leaves. What passes is audited *before* a byte is written, so an
 * audit that cannot be stored stops the export instead of following it.
 *
 * The rows come from RecruitmentApplicationFilters::query(), which starts at
 * RecruitmentApplication::visibleTo(): a filter can narrow what the caller may
 * already see, never widen it. Each chunk is scoped again as it is written.
 *
 * The file holds what the applicant list already shows an HR reader — identity,
 * application, consent — and no answers, notes, CV or evaluations.
 */
class RecruitmentApplicantExporter
{
    /** Filters RecruitmentApplicationFilters::query() understands. */
    private const FILTERS = [
        'campaign', 'status', 'city', 'tag', 'source', 'cv', 'previous', 'submitted_from', 'submitted_to', 'search',
    ];

    private const COLUMNS = [
        'application_id', 'applicant_id', 'full_name', 'email', 'phone', 'position', 'governorate',
        'status', 'submitted_at', 'source', 'privacy_notice_version', 'privacy_accepted_at', 'anonymized',
    ];

    public function __construct(
        private readonly RecruitmentApplicationFilters $filters,
        private readonly RecruitmentPrivacyAuditor $audit,
        private readonly SecurityMonitor $monitor,
    ) {}

    /**
     * @param  array<string, mixed>  $input  the request's filters, untrusted
     *
     * @throws RecruitmentExportRefusedException
     */
    public function prepare(User $user, array $input): PreparedExport
    {
        $filters = $this->normalizeFilters($input);

        $decision = Gate::forUser($user)->inspect('export', RecruitmentApplication::class);
        if ($decision->denied()) {
            $this->refuse($user, $filters, $decision->code() ?? 'denied', Response::HTTP_FORBIDDEN);
        }

        // A campaign or governorate outside the caller's reach is not an empty
        // result, it is an attempt: say so instead of quietly returning nothing.
        if ($this->reachesOutsideScope($user, $filters)) {
            $this->refuse($user, $filters, 'out_of_scope_filter', Response::HTTP_FORBIDDEN);
        }

        $limiter = "recruitment-export:{$user->getKey()}";
        if (RateLimiter::tooManyAttempts($limiter, (int) config('recruitment-privacy.export.max_per_hour'))) {
            $this->refuse($user, $filters, 'rate_limited', Response::HTTP_TOO_MANY_REQUESTS, __('recruitment-privacy.export.rate_limited'));
        }

        $query = $this->filters->query($user, $filters);
        $total = (clone $query)->count();
        $max = (int) config('recruitment-privacy.export.max_rows');

        if ($total === 0) {
            throw new RecruitmentExportRefusedException('empty', Response::HTTP_UNPROCESSABLE_ENTITY, __('recruitment-privacy.export.empty'));
        }

        if ($total > $max) {
            $this->refuse(
                $user, $filters, 'too_large', Response::HTTP_UNPROCESSABLE_ENTITY,
                __('recruitment-privacy.export.too_large', ['count' => $total, 'max' => $max]),
                $total,
            );
        }

        /** @var array<int, int> $ids */
        $ids = $query->reorder()->orderBy('recruitment_applications.id')->pluck('recruitment_applications.id')->map(fn ($id): int => (int) $id)->all();

        $this->audit->record(RecruitmentPrivacyAudit::ACTION_EXPORT, RecruitmentPrivacyAudit::OUTCOME_SUCCESS, $user, $filters, count($ids));
        RateLimiter::hit($limiter, 3600);

        if (count($ids) >= (int) config('recruitment-privacy.export.alert_rows')) {
            $this->monitor->record(SecurityMonitor::EXPORT_LARGE, ['records' => count($ids), 'filtered' => $filters !== []]);
        }

        return new PreparedExport($ids, $filters);
    }

    /** Streams the file. Call inside a streamed response, after prepare(). */
    public function write(User $user, PreparedExport $export): void
    {
        $out = fopen('php://output', 'w');
        $governorates = City::query()->get()->mapWithKeys(fn (City $city): array => [$city->getKey() => $city->name ?? $city->slug]);

        // Excel opens a CSV as the local ANSI codepage unless it sees a BOM.
        fwrite($out, "\xEF\xBB\xBF");
        $this->putRow($out, self::COLUMNS);

        foreach (array_chunk($export->ids, max(1, (int) config('recruitment-privacy.export.chunk'))) as $chunk) {
            RecruitmentApplication::query()
                ->visibleTo($user)
                ->whereIn('id', $chunk)
                ->with(['applicant', 'campaign.vacancy.position', 'campaign.vacancy.createdFromRequest:id,scope_city_id'])
                ->orderBy('id')
                ->get()
                ->each(function (RecruitmentApplication $application) use ($out, $governorates): void {
                    $applicant = $application->applicant;

                    $this->putRow($out, [
                        $application->getKey(),
                        $application->applicant_id,
                        $applicant?->full_name,
                        $applicant?->email_normalized,
                        $applicant?->phone_normalized,
                        $application->campaign?->vacancy?->position?->title,
                        $governorates->get($application->campaign?->vacancy?->createdFromRequest?->scope_city_id),
                        $application->status,
                        $application->submitted_at?->toIso8601String(),
                        $applicant?->source,
                        $application->privacy_notice_version,
                        $application->privacy_accepted_at?->toIso8601String(),
                        $applicant?->anonymized_at !== null ? 'yes' : 'no',
                    ]);
                });

            flush();
        }

        fclose($out);
    }

    /**
     * Only the filters the list understands, only scalars and lists of them,
     * nothing empty. What survives is what gets audited.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function normalizeFilters(array $input): array
    {
        $clean = [];

        foreach (self::FILTERS as $key) {
            $value = $input[$key] ?? null;

            $value = is_array($value)
                ? array_values(array_filter($value, fn (mixed $item): bool => is_scalar($item) && (string) $item !== ''))
                : (is_scalar($value) ? trim((string) $value) : null);

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $clean[$key] = is_array($value)
                ? array_map(fn (mixed $item): string => mb_substr((string) $item, 0, 100), $value)
                : mb_substr($value, 0, 100);
        }

        return $clean;
    }

    /** @param array<string, mixed> $filters */
    private function reachesOutsideScope(User $user, array $filters): bool
    {
        $allowed = [
            'campaign' => fn (): array => array_keys($this->filters->campaignOptions($user)),
            'city' => fn (): array => array_keys($this->filters->cityOptions($user)),
        ];

        foreach ($allowed as $key => $options) {
            if (! isset($filters[$key])) {
                continue;
            }

            $requested = collect((array) $filters[$key])
                ->filter(fn (mixed $id): bool => filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false)
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

            if (array_diff($requested, array_map('intval', $options())) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $filters
     *
     * @throws RecruitmentExportRefusedException
     */
    private function refuse(User $user, array $filters, string $reason, int $status, string $message = '', int $count = 0): never
    {
        $this->audit->record(
            RecruitmentPrivacyAudit::ACTION_EXPORT,
            RecruitmentPrivacyAudit::OUTCOME_REFUSED,
            $user,
            $filters,
            $count,
            reason: $reason,
        );

        $this->monitor->record(SecurityMonitor::EXPORT_REFUSED, ['reason' => $reason, 'records' => $count ?: null]);

        throw new RecruitmentExportRefusedException($reason, $status, $message);
    }

    /** @param resource $out */
    private function putRow($out, array $row): void
    {
        // The explicit empty escape character: PHP's default one corrupts a
        // field that ends in a backslash, and omitting it is deprecated.
        fputcsv($out, array_map($this->cell(...), $row), ',', '"', '');
    }

    /**
     * A spreadsheet runs a cell that starts with = + - @ as a formula, and
     * applicants choose their own names. Those get a leading apostrophe.
     * A phone number (+963 ...) starts with the same characters but can hold
     * nothing except digits and separators, so it is left as it is.
     */
    private function cell(mixed $value): string
    {
        $text = (string) ($value ?? '');

        if ($text !== '' && str_contains("=+-@\t\r", $text[0]) && preg_match('/^[+\-]?[\d\s().\-]+$/', $text) !== 1) {
            return "'".$text;
        }

        return $text;
    }
}
