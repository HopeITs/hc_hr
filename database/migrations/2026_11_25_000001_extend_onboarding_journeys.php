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
 * Constraint and index names are written out: with a table prefix (hchr_) the
 * generated ones pass MySQL's 64-character limit. Every step checks what is
 * already there, so the migration can be run again after a run that stopped
 * half way (MySQL does not roll DDL back).
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
        $columns = [
            'manager_orientation_scheduled_for' => fn (Blueprint $table) => $table->date('manager_orientation_scheduled_for')->nullable()->after('orientation_completed_by'),
            'manager_orientation_scheduled_by' => fn (Blueprint $table) => $table->unsignedBigInteger('manager_orientation_scheduled_by')->nullable()->after('manager_orientation_scheduled_for'),
            'manager_orientation_completed_at' => fn (Blueprint $table) => $table->timestamp('manager_orientation_completed_at')->nullable()->after('manager_orientation_scheduled_by'),
            'manager_orientation_completed_by' => fn (Blueprint $table) => $table->unsignedBigInteger('manager_orientation_completed_by')->nullable()->after('manager_orientation_completed_at'),
        ];

        foreach ($columns as $name => $add) {
            if (! Schema::hasColumn('onboarding_journeys', $name)) {
                Schema::table('onboarding_journeys', fn (Blueprint $table) => $add($table));
            }
        }

        $this->foreign('onboarding_journeys', 'manager_orientation_scheduled_by', 'onb_journeys_mgr_sched_by_fk', 'users');
        $this->foreign('onboarding_journeys', 'manager_orientation_completed_by', 'onb_journeys_mgr_done_by_fk', 'users');

        if (! Schema::hasColumn('checklist_items', 'onboarding_group')) {
            Schema::table('checklist_items', function (Blueprint $table) {
                $table->string('onboarding_group', 20)->default('other')->after('order');
            });
        }

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

        if (! Schema::hasColumn('policy_documents', 'required_for_onboarding')) {
            Schema::table('policy_documents', function (Blueprint $table) {
                $table->boolean('required_for_onboarding')->default(false)->after('synced_at');
            });
        }

        if (! Schema::hasTable('policy_acknowledgements')) {
            Schema::create('policy_acknowledgements', function (Blueprint $table) {
                $table->id();
                $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
                $table->foreignId('policy_document_id')->constrained('policy_documents')->cascadeOnDelete();
                $table->timestamp('acknowledged_at');
                // The version read: a later change to the document is visible against it.
                $table->timestamp('document_modified_at')->nullable();
                $table->timestamps();

                $table->unique(['employee_id', 'policy_document_id'], 'policy_ack_employee_document_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_acknowledgements');

        if (Schema::hasColumn('policy_documents', 'required_for_onboarding')) {
            Schema::table('policy_documents', function (Blueprint $table) {
                $table->dropColumn('required_for_onboarding');
            });
        }

        if (Schema::hasColumn('checklist_items', 'onboarding_group')) {
            Schema::table('checklist_items', function (Blueprint $table) {
                $table->dropColumn('onboarding_group');
            });
        }

        foreach ([
            'manager_orientation_scheduled_by' => 'onb_journeys_mgr_sched_by_fk',
            'manager_orientation_completed_by' => 'onb_journeys_mgr_done_by_fk',
        ] as $column => $foreign) {
            if (Schema::hasColumn('onboarding_journeys', $column)) {
                Schema::table('onboarding_journeys', function (Blueprint $table) use ($foreign) {
                    if ($this->hasForeign('onboarding_journeys', $foreign)) {
                        $table->dropForeign($foreign);
                    }
                });
            }
        }

        foreach (['manager_orientation_scheduled_for', 'manager_orientation_scheduled_by', 'manager_orientation_completed_at', 'manager_orientation_completed_by'] as $column) {
            if (Schema::hasColumn('onboarding_journeys', $column)) {
                Schema::table('onboarding_journeys', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }

    /** Add the foreign key under a short name, unless it is already there. */
    private function foreign(string $table, string $column, string $name, string $references): void
    {
        if ($this->hasForeign($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column, $name, $references) {
            $blueprint->foreign($column, $name)->references('id')->on($references)->nullOnDelete();
        });
    }

    private function hasForeign(string $table, string $name): bool
    {
        // The prefix is already in the names the schema reports, but not in ours.
        $prefix = DB::getTablePrefix();

        return collect(Schema::getForeignKeys($table))
            ->contains(fn (array $foreign) => in_array($foreign['name'], [$name, $prefix.$name], true));
    }
};
