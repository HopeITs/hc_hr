<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Onboarding journey, extended (meeting 19/11, track 7):
 *
 *  - the direct manager's own orientation session (programs, how work runs),
 *    with the same schedule-then-record rule as HR's session;
 *  - checklist_items.onboarding_group, which says which delivery step an item
 *    belongs to (electronics | card | after_probation | other | exit), set once
 *    by name and idempotently; a "Mouse" item is added when it is missing;
 *  - policy_documents.required_for_onboarding and policy_acknowledgements, the
 *    volunteer's "read and agree" per required document.
 *
 * Additive. Existing journeys keep their rows; a completed one stays completed
 * (OnboardingJourneys never reopens it on its own). Rollback drops what is
 * added here and nothing else reads it.
 */
return new class extends Migration
{
    /** Item name => group. Anything else stays "other"; order-3 items are "exit". */
    private const GROUPS = [
        'Email' => 'electronics',
        'Email Signature' => 'electronics',
        'Laptop' => 'electronics',
        'Mouse' => 'electronics',
        'Mobile' => 'electronics',
        'SIM' => 'electronics',
        'ID Cartoon' => 'card',
        'ID Plastic' => 'card',
        'Mug' => 'after_probation',
    ];

    public function up(): void
    {
        Schema::table('onboarding_journeys', function (Blueprint $table) {
            $table->date('manager_orientation_scheduled_for')->nullable()->after('orientation_completed_by');
            $table->foreignId('manager_orientation_scheduled_by')->nullable()->after('manager_orientation_scheduled_for')->constrained('users')->nullOnDelete();
            $table->timestamp('manager_orientation_completed_at')->nullable()->after('manager_orientation_scheduled_by');
            $table->foreignId('manager_orientation_completed_by')->nullable()->after('manager_orientation_completed_at')->constrained('users')->nullOnDelete();
        });

        Schema::table('checklist_items', function (Blueprint $table) {
            $table->string('onboarding_group', 20)->default('other')->after('order');
        });

        foreach (self::GROUPS as $name => $group) {
            DB::table('checklist_items')->where('name', $name)->update(['onboarding_group' => $group]);
        }

        // The return items (order 3) belong to the exit, whatever their name.
        DB::table('checklist_items')->where('order', 3)->update(['onboarding_group' => 'exit']);

        if (! DB::table('checklist_items')->where('name', 'Mouse')->exists()) {
            DB::table('checklist_items')->insert([
                'name' => 'Mouse',
                'order' => 1,
                'onboarding_group' => 'electronics',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('policy_documents', function (Blueprint $table) {
            $table->boolean('required_for_onboarding')->default(false)->after('synced_at');
        });

        Schema::create('policy_acknowledgements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('policy_document_id')->constrained('policy_documents')->cascadeOnDelete();
            $table->timestamp('acknowledged_at');
            // The version read: a later change to the document is visible against it.
            $table->timestamp('document_modified_at')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'policy_document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_acknowledgements');

        Schema::table('policy_documents', function (Blueprint $table) {
            $table->dropColumn('required_for_onboarding');
        });

        Schema::table('checklist_items', function (Blueprint $table) {
            $table->dropColumn('onboarding_group');
        });

        Schema::table('onboarding_journeys', function (Blueprint $table) {
            $table->dropConstrainedForeignId('manager_orientation_scheduled_by');
            $table->dropConstrainedForeignId('manager_orientation_completed_by');
            $table->dropColumn(['manager_orientation_scheduled_for', 'manager_orientation_completed_at']);
        });
    }
};
