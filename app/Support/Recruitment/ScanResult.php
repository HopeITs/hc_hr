<?php

namespace App\Support\Recruitment;

/**
 * What the antivirus said about one file.
 *
 * Three outcomes, kept apart on purpose: "clean" releases the file, "infected"
 * locks it for good, and "error" means the scanner could not answer — which
 * says nothing about the file, so it stays in quarantine and is tried again.
 */
final class ScanResult
{
    public const CLEAN = 'clean';

    public const INFECTED = 'infected';

    public const ERROR = 'error';

    private function __construct(
        public readonly string $outcome,
        public readonly ?string $signature,
        public readonly string $engine,
        public readonly ?string $message = null,
        /** The file itself cannot be scanned (too large, unreadable): retrying will not help. */
        public readonly bool $permanent = false,
    ) {}

    public static function clean(string $engine): self
    {
        return new self(self::CLEAN, null, $engine);
    }

    public static function infected(string $engine, string $signature): self
    {
        return new self(self::INFECTED, $signature, $engine);
    }

    public static function error(string $engine, string $message, bool $permanent = false): self
    {
        return new self(self::ERROR, null, $engine, $message, $permanent);
    }

    public function isClean(): bool
    {
        return $this->outcome === self::CLEAN;
    }

    public function isInfected(): bool
    {
        return $this->outcome === self::INFECTED;
    }

    /** @return array<string, scalar|null> stored on the media row; no file content */
    public function metadata(): array
    {
        return [
            'engine' => $this->engine,
            'outcome' => $this->outcome,
            'signature' => $this->signature,
            'message' => $this->message,
            'scanned_at' => now()->toIso8601String(),
        ];
    }
}
