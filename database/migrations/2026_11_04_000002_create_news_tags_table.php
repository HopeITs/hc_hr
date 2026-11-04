<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * News & Events: "Related To" (newsable_type / newsable_id, one entity per
 * item) is replaced by optional free tags, zero or more short labels such as
 * "Aleppo", "Study Zone" or "Training". Tags describe what an item is about
 * and never decide who sees it (that is the audience).
 *
 * newsable_* is kept as it was (no data is dropped); the app no longer reads
 * or writes it. On the run that creates the table, every item whose
 * newsable still resolves gets one tag: the entity's Arabic name when it has
 * one, its English name otherwise.
 */
return new class extends Migration
{
    /** newsable_type => [table, kind] where kind says how its name is read. */
    private const ENTITIES = [
        'App\\Models\\City' => ['cities', 'named'],
        'App\\Models\\Center' => ['centers', 'named'],
        'App\\Models\\Program' => ['programs', 'named'],
        'App\\Models\\Department' => ['departments', 'named'],
        'App\\Models\\Employee' => ['employees', 'person'],
    ];

    private const MAX_LENGTH = 60;

    public function up(): void
    {
        if (Schema::hasTable('news_tags')) {
            return;
        }

        Schema::create('news_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('news_id')->constrained('news')->cascadeOnDelete();
            $table->string('tag', self::MAX_LENGTH);
            $table->unique(['news_id', 'tag']);
            $table->index('tag');
        });

        $this->backfill();
    }

    /** One tag per item whose Related To entity still exists. */
    private function backfill(): void
    {
        DB::table('news')
            ->whereNotNull('newsable_type')
            ->whereNotNull('newsable_id')
            ->orderBy('id')
            ->select(['id', 'newsable_type', 'newsable_id'])
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    $tag = $this->entityName((string) $row->newsable_type, (int) $row->newsable_id);

                    if ($tag !== null) {
                        DB::table('news_tags')->insertOrIgnore([
                            'news_id' => $row->id,
                            'tag' => $tag,
                        ]);
                    }
                }
            });
    }

    /** The entity's Arabic name, else its English one; null when it is gone or unnamed. */
    private function entityName(string $type, int $id): ?string
    {
        [$table, $kind] = self::ENTITIES[$type] ?? [null, null];

        if ($table === null || ! Schema::hasTable($table)) {
            return null;
        }

        $entity = DB::table($table)->where('id', $id)->first();

        if ($entity === null) {
            return null;
        }

        $entity = (array) $entity;
        $read = fn (string ...$columns) => trim(implode(' ', array_map(
            fn (string $column) => trim((string) ($entity[$column] ?? '')),
            $columns,
        )));

        $candidates = $kind === 'person'
            ? [$read('first_name_ar', 'last_name_ar'), $read('first_name_en', 'last_name_en')]
            : [$read('name_ar'), $read('name'), $read('name_en')];

        foreach ($candidates as $name) {
            $name = trim((string) preg_replace('/\s+/u', ' ', $name));

            if ($name !== '') {
                return mb_substr($name, 0, self::MAX_LENGTH);
            }
        }

        return null;
    }

    public function down(): void
    {
        Schema::dropIfExists('news_tags');
    }
};
