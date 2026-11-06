<?php

namespace App\Notifications;

use App\Notifications\Contracts\BypassesPreferences;

/**
 * The bell's "send a test" (NotificationTester, target in_app). It is the
 * ordinary TestNotification, but it skips the preference listener: the
 * tester has already checked that in-app is on, and going through the
 * listener would also fan the test out to every phone and inbox.
 */
class PreferenceTestNotification extends TestNotification implements BypassesPreferences {}
