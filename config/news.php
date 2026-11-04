<?php

return [
    /*
     * External platforms news can be published to, as classes implementing
     * App\Services\News\Publishing\ExternalPublisher. None is integrated yet;
     * a LinkedIn integration would be added here once it exists. With this
     * list empty the News pages show LinkedIn as a future capability only.
     */
    'external_publishers' => [],

    // How many items the dashboard news tile lists, and how far ahead the
    // upcoming events tile looks (days).
    'dashboard_news_limit' => 8,
    'upcoming_events_days' => 30,
];
