<?php

namespace App\Jobs;

use App\Models\NotificationOutboxEntry;
use App\Services\Notifications\OutboxDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sends outbox rows that were due the moment they were written (instant
 * frequency, or an urgent bypass). Deferred rows are picked up by
 * `notifications:dispatch-due` instead. Ids, not models: a row already sent
 * by the scheduler in the meantime is skipped by the dispatcher.
 */
class DeliverNotificationOutbox implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    /** @param  array<int, int>  $entryIds */
    public function __construct(public array $entryIds) {}

    public function handle(OutboxDispatcher $dispatcher): void
    {
        $dispatcher->deliver(
            NotificationOutboxEntry::query()->whereKey($this->entryIds)->with('user')->get(),
        );
    }
}
