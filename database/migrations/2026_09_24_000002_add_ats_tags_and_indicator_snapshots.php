<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tags are soft-deleted so an assignment is never lost with its tag: a
 * deleted tag disappears from every screen, and restoring it brings its
 * assignments back with the original author and date.
 *
 * Indicator snapshots store the numbers only together with the criteria
 * version they were computed under, and every version's definition is kept,
 * so any saved figure can still be explained after the criteria change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recruitment_tags', function (Blueprint $table) {
            $table->foreignId('updated_by_user_id')->nullable()->after('created_by_user_id');
            $table->timestamp('deleted_at')->nullable();
            $table->foreignId('deleted_by_user_id')->nullable();

            $table->index('deleted_at', 'rt_deleted_idx');
            $table->foreign('updated_by_user_id', 'rt_updater_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('deleted_by_user_id', 'rt_deleted_by_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });

        // Filtering by tag reads (tag_id -> application_id); the unique index
        // leads with application_id and cannot serve it.
        Schema::table('recruitment_application_tags', function (Blueprint $table) {
            $table->index(['tag_id', 'application_id'], 'rat_tag_application_idx');
        });

        Schema::create('recruitment_indicator_criteria_versions', function (Blueprint $table) {
            $table->id();
            $table->string('version', 64);
            $table->json('definition_json');
            $table->timestamp('created_at')->useCurrent();

            $table->unique('version', 'ricv_version_uq');
        });

        Schema::create('recruitment_application_indicator_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id');
            $table->string('criteria_version', 64);
            $table->unsignedTinyInteger('met_count');
            $table->unsignedTinyInteger('assessed_count');
            $table->json('indicators_json');
            $table->dateTime('computed_at');
            $table->foreignId('computed_by_user_id')->nullable();
            $table->timestamps();

            $table->unique('application_id', 'rais_application_uq');
            $table->index('criteria_version', 'rais_criteria_idx');

            $table->foreign('application_id', 'rais_application_fk')
                ->references('id')->on('recruitment_applications')->cascadeOnDelete();
            $table->foreign('criteria_version', 'rais_criteria_fk')
                ->references('version')->on('recruitment_indicator_criteria_versions')->restrictOnDelete();
            $table->foreign('computed_by_user_id', 'rais_computed_by_fk')
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_application_indicator_snapshots');
        Schema::dropIfExists('recruitment_indicator_criteria_versions');

        Schema::table('recruitment_application_tags', function (Blueprint $table) {
            $table->dropIndex('rat_tag_application_idx');
        });

        Schema::table('recruitment_tags', function (Blueprint $table) {
            $table->dropForeign('rt_deleted_by_fk');
            $table->dropForeign('rt_updater_fk');
            $table->dropIndex('rt_deleted_idx');
            $table->dropColumn(['updated_by_user_id', 'deleted_at', 'deleted_by_user_id']);
        });
    }
};
