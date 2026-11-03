<?php

namespace App\Console\Commands;

use App\Models\NotificationOutboxEntry;
use App\Models\PushSubscription;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Daily clean-up: subscriptions the browser let expire or that have not
 * been seen for webpush.stale_after_days (an old phone, a wiped browser),
 * and outbox rows finished more than 30 days ago.
 */
class PrunePushData extends Command
{
    public const OUTBOX_KEEP_DAYS = 30;

    protected $signature = 'push:prune';

    protected $description = 'Remove expired or stale push subscriptions and old outbox rows';

    public function handle(): int
    {
        $staleBefore = now()->subDays(max(1, (int) config('webpush.stale_after_days', 120)));
        $outboxBefore = now()->subDays(self::OUTBOX_KEEP_DAYS);

        $expired = PushSubscription::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->delete();

        $stale = PushSubscription::query()
            ->where(fn (Builder $q) => $q->where('last_seen_at', '<', $staleBefore)
                ->orWhere(fn (Builder $q) => $q->whereNull('last_seen_at')->where('updated_at', '<', $staleBefore)))
            ->delete();

        $outbox = NotificationOutboxEntry::query()
            ->where(fn (Builder $q) => $q->where('sent_at', '<', $outboxBefore)->orWhere('failed_at', '<', $outboxBefore))
            ->delete();

        $this->info("Removed {$expired} expired and {$stale} stale subscription(s), {$outbox} outbox row(s).");

        return self::SUCCESS;
    }
}
