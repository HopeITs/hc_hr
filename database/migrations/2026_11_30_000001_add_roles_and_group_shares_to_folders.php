<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Folders shared the Google Drive way (decided 2026-11-24): each person or group
 * a folder is shared with has a role, viewer or editor; a group (a department, a
 * city, a mailing list ...) is kept as a group, so who joins it later has access
 * and who leaves loses it; and a subfolder takes its parent's access.
 *
 * Until now a subfolder took a copy of its parent's list when it was made, and its
 * owner could narrow that list afterwards. Inheriting would give those people their
 * access back, so every existing subfolder whose list is narrower than its parent's
 * keeps its own list only (inherits_access = false). Nobody gains access here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('folder_user', function (Blueprint $table) {
            // Everyone who had access could only view and download; the owner is folders.user_id.
            $table->string('role', 10)->default('viewer')->after('user_id');
        });

        Schema::table('folders', function (Blueprint $table) {
            $table->boolean('inherits_access')->default(true)->after('is_shared');
        });

        Schema::create('folder_group_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('folder_id')->constrained()->cascadeOnDelete();
            // department, job_level, program, center, city, mail_list (FolderAccess::GROUP_TYPES).
            $table->string('group_type', 20);
            $table->unsignedBigInteger('group_id');
            $table->string('role', 10)->default('viewer');
            $table->foreignId('shared_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['folder_id', 'group_type', 'group_id']);
            $table->index(['group_type', 'group_id']);
        });

        $members = DB::table('folder_user')->get(['folder_id', 'user_id'])
            ->groupBy('folder_id')
            ->map(fn ($rows) => $rows->pluck('user_id')->map(fn ($id) => (int) $id)->all());

        DB::table('folders')->whereNotNull('parent_id')->orderBy('id')->get(['id', 'parent_id'])
            ->each(function ($folder) use ($members): void {
                $parent = $members->get($folder->parent_id, []);
                $own = $members->get($folder->id, []);

                if (array_diff($parent, $own) !== []) {
                    DB::table('folders')->where('id', $folder->id)->update(['inherits_access' => false]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('folder_group_shares');

        Schema::table('folders', function (Blueprint $table) {
            $table->dropColumn('inherits_access');
        });

        Schema::table('folder_user', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
