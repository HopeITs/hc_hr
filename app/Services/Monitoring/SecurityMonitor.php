<?php

namespace App\Services\Monitoring;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The one place monitoring events are written.
 *
 * An event is a structured line on the `security` log channel, with a level a
 * shipper or an alert can filter on. It is not the audit trail: who exported
 * what is a row in recruitment_privacy_audits. This records that something
 * unusual happened — a failure, a refusal, a burst.
 *
 * Three rules hold for every event:
 *
 *   - No personal data. Identifiers, reason codes, route names, the caller's
 *     IP. Never an email, a name, an answer, a filename or a link token.
 *   - It never throws. Monitoring that can break the request it watches would
 *     turn a spam wave into an outage.
 *   - It cannot be used to fill the disk. A repeated event is written at its
 *     1st, 10th, 100th... occurrence per key within a window, and bursts fire
 *     only when their counter crosses a threshold.
 */
class SecurityMonitor
{
    public const SUBMISSION_FAILED = 'submission.failed';

    public const UPLOAD_REJECTED = 'upload.rejected';

    public const SCAN_INFECTED = 'scan.infected';

    public const SCAN_FAILED = 'scan.failed';

    public const SCAN_GAVE_UP = 'scan.gave_up';

    public const RATE_LIMIT_HIT = 'rate_limit.hit';

    public const ACCESS_DENIED = 'access.denied';

    public const EXPORT_LARGE = 'export.large';

    public const EXPORT_REFUSED = 'export.refused';

    public const DOWNLOAD_REPEATED = 'download.repeated';

    public const PUBLIC_ABUSE = 'public.abuse_suspected';

    public const RETENTION_FAILED = 'retention.failed';

    /** Monolog level per event; a caller may pass a different one for a case. */
    private const LEVELS = [
        self::SUBMISSION_FAILED => 'info',
        self::UPLOAD_REJECTED => 'info',
        self::SCAN_INFECTED => 'critical',
        self::SCAN_FAILED => 'error',
        self::SCAN_GAVE_UP => 'error',
        self::RATE_LIMIT_HIT => 'warning',
        self::ACCESS_DENIED => 'warning',
        self::EXPORT_LARGE => 'warning',
        self::EXPORT_REFUSED => 'warning',
        self::DOWNLOAD_REPEATED => 'warning',
        self::PUBLIC_ABUSE => 'critical',
        self::RETENTION_FAILED => 'error',
    ];

    /** Used when config/recruitment-privacy.php does not supply a burst limit. */
    private const DEFAULT_LIMITS = [
        'downloads' => ['threshold' => 30, 'window' => 600],
        'submission_failures' => ['threshold' => 10, 'window' => 900],
        'token_probes' => ['threshold' => 15, 'window' => 600],
        'rate_limit_hits' => ['threshold' => 20, 'window' => 600],
    ];

    /** Submission failures a normal applicant causes by mistyping. */
    private const APPLICANT_MISTAKES = [
        'validation', 'missing_name', 'invalid_email', 'invalid_phone', 'generic', 'invalid_file', 'file_rejected',
    ];

    /** Rejections that say something about the file rather than the person's care. */
    private const SUSPICIOUS_UPLOADS = ['content_mismatch', 'executable'];

    /**
     * @param  array<string, mixed>  $context
     * @param  string|null  $dedupeKey  repeats of this event under this key are thinned out
     */
    public function record(string $event, array $context = [], ?string $dedupeKey = null, ?string $level = null): void
    {
        try {
            $occurrences = null;

            if ($dedupeKey !== null) {
                $occurrences = $this->tick("dedupe:{$event}:{$dedupeKey}", (int) config('recruitment-privacy.monitoring.dedupe_window', 300));

                if (! $this->isMilestone($occurrences)) {
                    return;
                }
            }

            $payload = array_filter(
                ['event' => $event, ...$this->requestContext(), ...$context, 'occurrences' => $occurrences],
                fn (mixed $value): bool => $value !== null,
            );

            $this->logger()->log($level ?? self::LEVELS[$event] ?? 'warning', "security.{$event}", $payload);
        } catch (Throwable $exception) {
            error_log('SecurityMonitor could not record '.$event.': '.$exception->getMessage());
        }
    }

    /**
     * Count one occurrence and record the event when the count reaches the
     * threshold — and again at every further multiple of it.
     *
     * The limit is read from config('recruitment-privacy.monitoring.{counter}')
     * inside the guard, falling back to DEFAULT_LIMITS: a missing or malformed
     * config key must degrade the monitor, never fail the request.
     *
     * @param  array{threshold: int, window: int}|null  $limit  overrides the configured one
     * @param  array<string, mixed>  $context
     */
    public function burst(string $event, string $counter, string $key, ?array $limit = null, array $context = []): bool
    {
        try {
            $limit ??= $this->limit($counter);
            $threshold = max(1, (int) $limit['threshold']);
            $count = $this->tick("burst:{$counter}:{$key}", (int) $limit['window']);

            if ($count < $threshold || $count % $threshold !== 0) {
                return false;
            }

            $this->record($event, [...$context, 'counter' => $counter, 'count' => $count, 'window_seconds' => (int) $limit['window']]);

            return true;
        } catch (Throwable $exception) {
            error_log('SecurityMonitor could not count '.$counter.': '.$exception->getMessage());

            return false;
        }
    }

    // --------------------------------------------
    // Public endpoint
    // --------------------------------------------

    /**
     * A refused public application. `invalid_request` is what a bot trips
     * (the honeypot, a field the form never had, a malformed submission key),
     * so it is written every time; a mistyped email is written thinned out.
     * Either way it counts toward the per-IP abuse burst.
     */
    public function submissionFailed(Request $request, string $reason, ?string $token = null): void
    {
        $ip = (string) $request->ip();
        $context = ['reason' => $reason, 'link' => $this->linkRef($token)];

        if (in_array($reason, self::APPLICANT_MISTAKES, true)) {
            $this->record(self::SUBMISSION_FAILED, $context, "{$ip}|{$reason}");
        } else {
            $this->record(
                self::SUBMISSION_FAILED,
                $context,
                level: $reason === 'server_error' ? 'error' : 'warning',
            );
        }

        $this->burst(self::PUBLIC_ABUSE, 'submission_failures', $ip, context: ['signal' => 'repeated_failed_submissions']);
    }

    /** A link token that resolves to nothing: a typo once, enumeration when it repeats. */
    public function tokenProbe(Request $request, ?string $token = null): void
    {
        $this->burst(
            self::PUBLIC_ABUSE,
            'token_probes',
            (string) $request->ip(),
            context: ['signal' => 'unknown_link_tokens', 'link' => $this->linkRef($token)],
        );
    }

    public function uploadRejected(string $reason, ?string $fileType = null): void
    {
        $suspicious = in_array($reason, self::SUSPICIOUS_UPLOADS, true);

        $this->record(
            self::UPLOAD_REJECTED,
            ['reason' => $reason, 'file_type' => $fileType],
            $suspicious ? null : (string) request()->ip().'|'.$reason,
            $suspicious ? 'warning' : 'info',
        );
    }

    // --------------------------------------------
    // Rate limits and denied access
    // --------------------------------------------

    /**
     * A 429. Every one on a careers route is also an abuse signal: those
     * limits sit well above what a person applying ever reaches.
     */
    public function rateLimitHit(Request $request): void
    {
        $route = $request->route()?->getName();

        $this->record(
            self::RATE_LIMIT_HIT,
            ['limited_route' => $route],
            (string) $request->ip().'|'.($route ?? $request->route()?->uri() ?? 'unmatched'),
        );

        if (is_string($route) && str_starts_with($route, 'careers.')) {
            $this->burst(
                self::PUBLIC_ABUSE,
                'rate_limit_hits',
                (string) $request->ip(),
                context: ['signal' => 'rate_limit_exceeded', 'limited_route' => $route],
            );
        }
    }

    /** A 403 anywhere in the application: a permission, a scope, a policy said no. */
    public function accessDenied(Request $request, Throwable $exception): void
    {
        $who = $request->user()?->getKey() ?? $request->ip();

        $this->record(
            self::ACCESS_DENIED,
            ['denied_route' => $request->route()?->getName() ?? $request->route()?->uri(), 'exception' => class_basename($exception)],
            "{$who}|".($request->route()?->uri() ?? 'unmatched'),
        );
    }

    // --------------------------------------------
    // Internals
    // --------------------------------------------

    /** @return array<string, mixed> */
    private function requestContext(): array
    {
        // A queued job or an artisan command has a request object too — the
        // console kernel binds a synthetic one, REMOTE_ADDR 127.0.0.1 — but no
        // route. Only a routed request has a client worth describing.
        $request = app()->bound('request') ? request() : null;
        $route = $request?->route();

        if ($route === null) {
            return [];
        }

        return [
            'ip' => $request->ip(),
            'user_id' => $request->user()?->getKey(),
            'method' => $request->method(),
            // The route's pattern, never the URL: a careers URL carries the link token.
            'route' => $route?->getName() ?? $route?->uri(),
            'user_agent' => Str::limit((string) $request->userAgent(), 200, ''),
        ];
    }

    /** A stable, non-reversible reference to a link token — enough to correlate, useless to replay. */
    private function linkRef(?string $token): ?string
    {
        return $token === null || $token === '' ? null : substr(hash('sha256', $token), 0, 12);
    }

    /** @return array{threshold: int, window: int} */
    private function limit(string $counter): array
    {
        $configured = config("recruitment-privacy.monitoring.{$counter}");

        if (is_array($configured) && isset($configured['threshold'], $configured['window'])) {
            return ['threshold' => (int) $configured['threshold'], 'window' => (int) $configured['window']];
        }

        return self::DEFAULT_LIMITS[$counter] ?? ['threshold' => 20, 'window' => 600];
    }

    private function tick(string $key, int $window): int
    {
        $key = "security-monitor:{$key}";

        Cache::add($key, 0, max(1, $window));

        return (int) Cache::increment($key);
    }

    /** 1, 10, 100, 1000... */
    private function isMilestone(int $occurrences): bool
    {
        if ($occurrences < 1) {
            return false;
        }

        while ($occurrences % 10 === 0) {
            $occurrences = intdiv($occurrences, 10);
        }

        return $occurrences === 1;
    }

    private function logger(): LoggerInterface
    {
        $channel = (string) config('recruitment-privacy.monitoring.channel', 'security');

        if (! is_array(config("logging.channels.{$channel}"))) {
            $channel = (string) config('logging.default');
        }

        return Log::channel($channel);
    }
}
