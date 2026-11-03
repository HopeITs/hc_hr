<?php

namespace App\Notifications\Contracts;

/**
 * A notification that already went through the person's preferences (the
 * emails the outbox sends, the "send a test" checks), so
 * ApplyNotificationPreferences must leave it alone: applying the rules a
 * second time would cancel or delay what was just decided.
 */
interface BypassesPreferences {}
