<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * C3: who may decide a leave request (DEC-05 revision, approval-source-contract.md §4.3).
     *
     * - decision_basis: how the decider was entitled: 'manager' (the requester's
     *   direct manager), 'on_behalf' (HR, while that manager is on accepted
     *   leave) or 'no_manager' (HR, the requester has no direct manager, C3-Q1).
     *   Null for decisions made before C3, which were not recorded.
     * - decided_on_behalf_of: the manager (employee id) HR stood in for.
     * - leave_request_notes: HR's optional note and the required objection
     *   note (G3, G5), visible to HR, the manager and the requester.
     *
     * Only columns and a new table are added (no change()), so SQLite does not
     * rebuild leave_requests, and existing rows are untouched. Rolling back
     * drops the two columns and the notes table; decisions themselves are not
     * affected.
     */
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->string('decision_basis', 16)->nullable()->after('approved_by');
            $table->unsignedBigInteger('decided_on_behalf_of')->nullable()->after('decision_basis');
        });

        Schema::create('leave_request_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_request_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('author_employee_id')->nullable();
            $table->string('author_role', 32)->nullable();
            // note: added with "Seen by HR"; objection: sent the request back to pending.
            $table->string('kind', 16);
            $table->text('body');
            $table->timestamps();

            $table->index(['leave_request_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_request_notes');

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn(['decision_basis', 'decided_on_behalf_of']);
        });
    }
};
