<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Onboarding journey, track 7 follow-up:
 *
 *  - the electronics are handed over by IT of the volunteer's city; when the
 *    city has several IT people HR picks one per volunteer, kept here;
 *  - policy_documents.onboarding_kind: "policy" is read and agreed, "review" is
 *    a document made by another team (the Media guideline, by the Media Team)
 *    that the volunteer only has to review. Same acknowledgement table.
 *
 * Additive. Existing documents stay "policy". Rollback drops both columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('onboarding_journeys', function (Blueprint $table) {
            $table->foreignId('electronics_deliverer_id')->nullable()->after('card_handed_over_by')->constrained('employees')->nullOnDelete();
        });

        Schema::table('policy_documents', function (Blueprint $table) {
            $table->string('onboarding_kind', 10)->default('policy')->after('required_for_onboarding');
        });
    }

    public function down(): void
    {
        Schema::table('policy_documents', function (Blueprint $table) {
            $table->dropColumn('onboarding_kind');
        });

        Schema::table('onboarding_journeys', function (Blueprint $table) {
            $table->dropConstrainedForeignId('electronics_deliverer_id');
        });
    }
};
