<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The reviewer recommendation is now yes / maybe / no. Current evaluations
 * that carry a retired "strong" value fold into the plain one so they still
 * count in the interview's tally. The append-only revision history is left
 * as written; it keeps a label for the retired values.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('recruitment_interview_evaluations')->where('recommendation', 'strong_yes')->update(['recommendation' => 'yes']);
        DB::table('recruitment_interview_evaluations')->where('recommendation', 'strong_no')->update(['recommendation' => 'no']);
    }

    public function down(): void
    {
        // The original strength cannot be recovered.
    }
};
