<?php

namespace App\Services\News\Publishing;

use App\Models\News;

/**
 * One external platform (LinkedIn, ...). Nothing implements this yet: an
 * integration registers itself in config('news.external_publishers') and
 * ExternalPublishing only offers a platform whose publisher isConfigured().
 */
interface ExternalPublisher
{
    /** The platform key stored in news_external_publications.platform. */
    public function platform(): string;

    public function label(): string;

    /** Credentials present and usable on this host. */
    public function isConfigured(): bool;

    /**
     * Publish $news and return the platform's id and URL.
     *
     * @return array{external_id: string, external_url: ?string}
     *
     * @throws \Throwable on failure; the caller records the error for a retry.
     */
    public function publish(News $news): array;
}
