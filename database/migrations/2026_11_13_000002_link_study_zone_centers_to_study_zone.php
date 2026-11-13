<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Links every Study Zone and Science Zone center (Science Zone is Study Zone
 * too) to the Study Zone program, so its volunteers — whose positions have
 * no center — take those centers' weekend and hours for leave
 * (LeaveRequestPricing) without HR ticking each one by hand.
 *
 * Matched by name, since ids differ between databases: the program by its
 * slug "SZ" (else its name), the centers by an English name starting with
 * "Study Zone" / "Science Zone" or an Arabic one starting with "ستادي زون".
 * Inactive centers are linked too; pricing reads active ones only. A link
 * that already exists (set on the center dialog) is left as it is, and
 * nothing happens where the program is missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $programId = DB::table('programs')->where('slug', 'SZ')->value('id')
            ?? DB::table('programs')->where('name', 'Study Zone')->value('id');

        if ($programId === null) {
            return;
        }

        $centerIds = DB::table('centers')
            ->where(fn ($query) => $query
                ->where('name_en', 'like', 'Study Zone%')
                ->orWhere('name_en', 'like', 'Science Zone%')
                ->orWhere('name_ar', 'like', 'ستادي زون%'))
            ->pluck('id');

        DB::table('center_program')->insertOrIgnore(
            $centerIds->map(fn ($centerId) => ['center_id' => $centerId, 'program_id' => $programId])->all(),
        );
    }

    /**
     * Left in place: some links may have been set by hand before this ran,
     * and they cannot be told apart. HR unticks a center on its dialog.
     */
    public function down(): void {}
};
