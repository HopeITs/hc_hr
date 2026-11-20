<?php

namespace App\Services\Chat;

use RuntimeException;

/**
 * Separate keys for the chat's file encryption and its search index, both
 * derived from APP_KEY (HKDF-SHA256 with a purpose label), so one key never
 * does two jobs and nothing new has to be configured or backed up.
 *
 * Losing or changing APP_KEY makes existing messages, files and the search
 * index unreadable. That is accepted: the chat is not an official channel.
 */
class ChatKeys
{
    public const FILES = 'files';

    public const SEARCH = 'search';

    /** @var array<string, string> */
    private array $derived = [];

    public function for(string $purpose): string
    {
        return $this->derived[$purpose] ??= hash_hkdf('sha256', $this->appKey(), 32, 'hc-hr chat '.$purpose);
    }

    private function appKey(): string
    {
        $key = (string) config('app.key');

        if (str_starts_with($key, 'base64:')) {
            $key = (string) base64_decode(substr($key, 7), true);
        }

        if ($key === '') {
            throw new RuntimeException('APP_KEY is not set; chat encryption needs it.');
        }

        return $key;
    }
}
