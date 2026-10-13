<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * contract_types ids 2, 4 and 6 (Partial Annual / Trial Partial / the
     * second service-agreement row) are redundant now that `type` and
     * `place` live on contracts itself (see the 2026_09_14 migration this
     * one follows): every contract pointing at one of them is remapped to
     * its category-mate (1, 3, 5) before the redundant rows are dropped,
     * and the three survivors are renamed to the category names the
     * composer now uses everywhere.
     *
     * Consolidating destroys which of the two original rows a contract used
     * to point at, so down() cannot restore the exact original assignment —
     * it recreates 2, 4 and 6 as best-effort duplicates of 1, 3 and 5 (same
     * names) without reassigning any contract back to them, the same
     * best-effort tradeoff the 2026_09_14 migration already accepted.
     */
    private const MERGES = [2 => 1, 4 => 3, 6 => 5];

    private const FINAL_NAMES = [
        1 => ['name_en' => 'Volunteer Contract', 'name_ar' => 'عقد تطوّع'],
        3 => ['name_en' => 'Trial Volunteer Contract', 'name_ar' => 'عقد تطوّع تجريبي'],
        5 => ['name_en' => 'Specific Voluntary Service Contract', 'name_ar' => 'عقد تقديم خدمة تطوعية محددة'],
    ];

    public function up(): void
    {
        foreach (self::MERGES as $from => $to) {
            DB::table('contracts')->where('contract_type_id', $from)->update(['contract_type_id' => $to]);
        }

        DB::table('contract_types')->whereIn('id', array_keys(self::MERGES))->delete();

        foreach (self::FINAL_NAMES as $id => $names) {
            DB::table('contract_types')->where('id', $id)->update($names);
        }

        Schema::table('contracts', function (Blueprint $table) {
            $table->unsignedTinyInteger('schedule_mode')->default(1)->after('place');
            $table->unsignedTinyInteger('work_day_from')->nullable()->after('work_hours_to');
            $table->unsignedTinyInteger('work_day_to')->nullable()->after('work_day_from');
            $table->boolean('show_days_per_week')->default(false)->after('work_day_to');
            $table->boolean('show_hours_per_week')->default(false)->after('show_days_per_week');
            $table->unsignedTinyInteger('flex_days_per_week')->nullable()->after('show_hours_per_week');
            $table->unsignedSmallInteger('flex_hours_per_week')->nullable()->after('flex_days_per_week');
            $table->unsignedTinyInteger('flex_hours_per_day')->nullable()->after('flex_hours_per_week');
            $table->boolean('flex_varies_by_requirements')->default(false)->after('flex_hours_per_day');
            $table->boolean('flex_coordinated_with_manager')->default(false)->after('flex_varies_by_requirements');
            $table->string('flex_custom_en')->nullable()->after('flex_coordinated_with_manager');
            $table->string('flex_custom_ar')->nullable()->after('flex_custom_en');
            $table->unsignedTinyInteger('pay_mode')->default(1)->after('salary_per');
            $table->unsignedTinyInteger('session_duration')->nullable()->after('pay_mode');
        });

        Schema::create('contract_pay_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->string('condition_en');
            $table->string('condition_ar');
            $table->decimal('amount', 10, 2);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_pay_tiers');

        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn([
                'schedule_mode', 'work_day_from', 'work_day_to',
                'show_days_per_week', 'show_hours_per_week',
                'flex_days_per_week', 'flex_hours_per_week', 'flex_hours_per_day',
                'flex_varies_by_requirements', 'flex_coordinated_with_manager',
                'flex_custom_en', 'flex_custom_ar',
                'pay_mode', 'session_duration',
            ]);
        });

        foreach (self::MERGES as $droppedId => $survivorId) {
            $survivor = DB::table('contract_types')->where('id', $survivorId)->first();

            if ($survivor) {
                DB::table('contract_types')->insert([
                    'id' => $droppedId,
                    'name_en' => $survivor->name_en,
                    'name_ar' => $survivor->name_ar,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
};
