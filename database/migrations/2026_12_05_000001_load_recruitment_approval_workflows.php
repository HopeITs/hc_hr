<?php

use App\Models\User;
use Database\Seeders\RecruitmentWorkflowSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Load the recruitment approval workflows on databases that never got them.
 *
 * They were only created by RecruitmentWorkflowSeeder, which runs with a full
 * db:seed and nowhere else, so a database brought up by migrations alone (as
 * hr_db was, P1 run 2026-11-27) had none: every recruitment request stopped
 * at the draft with "no approved approval workflow for this request type".
 *
 * The seeder skips a request type that already has an active definition, so
 * this changes nothing where they exist. It needs a user to record as the
 * creator; a database with no users yet (a fresh install, the test suite)
 * gets them later from DatabaseSeeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! User::query()->exists()) {
            return;
        }

        (new RecruitmentWorkflowSeeder)->run();
    }

    /**
     * Nothing is removed: requests already in flight point at these
     * definitions, and a database that had them before this migration
     * cannot be told apart from one that got them here.
     */
    public function down(): void {}
};
