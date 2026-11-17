<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Internal notes are never hard-deleted and CVs are archived, not removed.
 *
 * A deleted note keeps its body and gains who removed it and why, so the audit
 * entry written alongside it always points at something that still exists.
 * An archived CV keeps its file and points at the note that justified it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recruitment_applicant_notes', function (Blueprint $table) {
            $table->boolean('is_sensitive')->default(false)->after('note_type');
            $table->timestamp('deleted_at')->nullable();
            $table->foreignId('deleted_by_user_id')->nullable();
            $table->text('deletion_reason')->nullable();

            $table->index('deleted_at', 'ran_deleted_idx');
            $table->foreign('deleted_by_user_id', 'ran_deleted_by_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });

        Schema::table('recruitment_applicant_files', function (Blueprint $table) {
            $table->dateTime('archived_at')->nullable()->after('uploaded_at');
            $table->foreignId('archived_by_user_id')->nullable()->after('archived_at');
            $table->foreignId('archive_note_id')->nullable()->after('archived_by_user_id');

            $table->index('archived_at', 'raf_archived_idx');
            $table->foreign('archived_by_user_id', 'raf_archived_by_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('archive_note_id', 'raf_archive_note_fk')
                ->references('id')->on('recruitment_applicant_notes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('recruitment_applicant_files', function (Blueprint $table) {
            $table->dropForeign('raf_archive_note_fk');
            $table->dropForeign('raf_archived_by_fk');
            $table->dropIndex('raf_archived_idx');
            $table->dropColumn(['archived_at', 'archived_by_user_id', 'archive_note_id']);
        });

        Schema::table('recruitment_applicant_notes', function (Blueprint $table) {
            $table->dropForeign('ran_deleted_by_fk');
            $table->dropIndex('ran_deleted_idx');
            $table->dropColumn(['is_sensitive', 'deleted_at', 'deleted_by_user_id', 'deletion_reason']);
        });
    }
};
