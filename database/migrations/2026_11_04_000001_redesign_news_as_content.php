<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * News & Events redesign. The old table mixed six concepts in four columns:
 * newsable_* was used as both the subject AND the audience, and event_date
 * was required on every item. This migration separates them without
 * dropping anything:
 *
 *   type                news | announcement | event          (what it is)
 *   newsable_*          Related To: what it is ABOUT          (kept, meaning narrowed)
 *   audience_type +     who may see it                        (new)
 *   news_audience_targets
 *   status + published_at  draft | published | archived, and Publish At
 *   event_starts_at / event_ends_at  when the event happens   (event_date kept as its local date)
 *   display_until       expiration                            (kept)
 *   news_external_publications  future LinkedIn-style publishing (no integration yet)
 *
 * Existing rows keep exactly the visibility they had: their newsable target
 * becomes their audience, and stays their Related To.
 */
return new class extends Migration
{
    private const AUDIENCE_BY_TYPE = [
        'App\\Models\\City' => 'cities',
        'App\\Models\\Center' => 'centers',
        'App\\Models\\Program' => 'programs',
        'App\\Models\\Department' => 'departments',
        'App\\Models\\Employee' => 'employees',
    ];

    public function up(): void
    {
        Schema::table('news', function (Blueprint $table) {
            if (! Schema::hasColumn('news', 'type')) {
                $table->string('type', 20)->default('news')->after('id');
            }
            if (! Schema::hasColumn('news', 'status')) {
                $table->string('status', 20)->default('published')->after('type');
            }
            if (! Schema::hasColumn('news', 'audience_type')) {
                $table->string('audience_type', 20)->default('all')->after('newsable_id');
            }
            if (! Schema::hasColumn('news', 'event_starts_at')) {
                $table->dateTime('event_starts_at')->nullable()->after('event_date');
            }
            if (! Schema::hasColumn('news', 'event_ends_at')) {
                $table->dateTime('event_ends_at')->nullable()->after('event_starts_at');
            }
            if (! Schema::hasColumn('news', 'archived_at')) {
                $table->timestamp('archived_at')->nullable()->after('display_until');
            }
        });

        // event_date was made NOT NULL by 2026_07_09; a news article or an
        // announcement has no event. SQLite rebuilds the table on change(),
        // which is harmless here: nothing references news with ON DELETE.
        Schema::table('news', function (Blueprint $table) {
            $table->date('event_date')->nullable()->change();
        });

        Schema::table('news', function (Blueprint $table) {
            $table->index(['status', 'published_at'], 'news_status_published_index');
            $table->index(['type', 'event_date'], 'news_type_event_index');
        });

        if (! Schema::hasTable('news_audience_targets')) {
            Schema::create('news_audience_targets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('news_id')->constrained('news')->cascadeOnDelete();
                // The kind of target is the parent's audience_type.
                $table->unsignedBigInteger('target_id');
                $table->unique(['news_id', 'target_id']);
                $table->index('target_id');
            });
        }

        if (! Schema::hasTable('news_external_publications')) {
            Schema::create('news_external_publications', function (Blueprint $table) {
                $table->id();
                $table->foreignId('news_id')->constrained('news')->cascadeOnDelete();
                $table->string('platform', 40);            // e.g. linkedin
                $table->string('status', 20)->default('pending'); // pending | published | failed
                $table->string('external_id')->nullable();
                $table->string('external_url', 2048)->nullable();
                $table->timestamp('published_at')->nullable();
                $table->text('last_error')->nullable();
                $table->unsignedSmallInteger('attempts')->default(0);
                $table->timestamp('last_attempt_at')->nullable();
                $table->timestamps();
                $table->unique(['news_id', 'platform']);
            });
        }

        $this->backfill();
    }

    /** Existing rows: same visibility, a sensible type, a Publish At and an event start. */
    private function backfill(): void
    {
        DB::table('news')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $published = $row->published_at ?? $row->created_at ?? now();
                $publishedDate = substr((string) $published, 0, 10);

                // Every old item had to carry an event_date. One dated after
                // its publication was an announcement of an event; one dated
                // on or before it reported something that had happened.
                $isEvent = $row->event_date && $row->event_date > $publishedDate;

                $audience = self::AUDIENCE_BY_TYPE[$row->newsable_type] ?? 'all';

                DB::table('news')->where('id', $row->id)->update([
                    'type' => $isEvent ? 'event' : 'news',
                    'status' => 'published',
                    'published_at' => $published,
                    'event_starts_at' => $isEvent ? $row->event_date.' 00:00:00' : null,
                    // event_date is left as it was (no data is dropped); for a
                    // news item it is simply no longer read.
                    'audience_type' => $row->newsable_id ? $audience : 'all',
                ]);

                if ($audience !== 'all' && $row->newsable_id) {
                    DB::table('news_audience_targets')->insertOrIgnore([
                        'news_id' => $row->id,
                        'target_id' => $row->newsable_id,
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news_external_publications');
        Schema::dropIfExists('news_audience_targets');

        Schema::table('news', function (Blueprint $table) {
            $table->dropIndex('news_status_published_index');
            $table->dropIndex('news_type_event_index');
        });

        Schema::table('news', function (Blueprint $table) {
            foreach (['type', 'status', 'audience_type', 'event_starts_at', 'event_ends_at', 'archived_at'] as $column) {
                if (Schema::hasColumn('news', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
