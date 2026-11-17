<?php

use App\Services\SalaryCipher;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 Task 9: what the Phase 1 job-offer skeleton needs for the offer
 * workflow.
 *
 * - recruitment_job_offer_versions.salary_amount holds ciphertext from now
 *   on, under the salary key, exactly like contracts.salary — an offer's pay
 *   figure is the same secret as the contract it becomes. The column is
 *   widened to text on MySQL only: on SQLite change() rebuilds the table and
 *   type affinity keeps the envelope verbatim anyway (see the contracts
 *   migration, 2026_09_22_000000). Any plaintext figure already there is
 *   encrypted in place, through the query builder so the cast cannot
 *   encrypt it twice.
 * - A version records why it was revised and who last edited it while it
 *   was still a draft. Once sent it is a frozen snapshot (enforced in the
 *   model), so the history of what was offered is never rewritten.
 * - An offer records how it ended: the candidate's response (who recorded
 *   it, and their reason), expiry, or cancellation (who, why).
 *
 * One offer per application stays the policy (rjo_application_uq): a revised
 * offer is a new version of the same offer, never a second active offer.
 */
return new class extends Migration
{
    public function up(): void
    {
        $mysql = DB::getDriverName() !== 'sqlite';

        if ($mysql) {
            Schema::table('recruitment_job_offer_versions', function (Blueprint $table) {
                $table->text('salary_amount')->nullable()->change();
            });
        }

        $cipher = app(SalaryCipher::class);
        DB::table('recruitment_job_offer_versions')->whereNotNull('salary_amount')->orderBy('id')
            ->each(function (object $row) use ($cipher): void {
                if ($cipher->decrypt((string) $row->salary_amount) !== null) {
                    return;
                }

                DB::table('recruitment_job_offer_versions')->where('id', $row->id)->update([
                    'salary_amount' => $cipher->encrypt(number_format((float) $row->salary_amount, 2, '.', '')),
                ]);
            });

        Schema::table('recruitment_job_offer_versions', function (Blueprint $table) {
            $table->text('revision_reason')->nullable()->after('terms_json');
            $table->foreignId('updated_by_user_id')->nullable()->after('created_by_user_id');
        });

        Schema::table('recruitment_job_offers', function (Blueprint $table) {
            $table->foreignId('response_recorded_by_user_id')->nullable()->after('rejected_at');
            $table->text('response_note')->nullable()->after('response_recorded_by_user_id');
            $table->dateTime('expired_at')->nullable()->after('expires_at');
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable();
            $table->string('cancellation_reason', 60)->nullable();
            $table->text('cancellation_note')->nullable();
            $table->foreignId('updated_by_user_id')->nullable();
        });

        if ($mysql) {
            Schema::table('recruitment_job_offer_versions', function (Blueprint $table) {
                $table->foreign('updated_by_user_id', 'rjov_updater_fk')->references('id')->on('users')->restrictOnDelete();
            });

            Schema::table('recruitment_job_offers', function (Blueprint $table) {
                $table->foreign('response_recorded_by_user_id', 'rjo_responder_fk')->references('id')->on('users')->restrictOnDelete();
                $table->foreign('cancelled_by_user_id', 'rjo_canceller_fk')->references('id')->on('users')->restrictOnDelete();
                $table->foreign('updated_by_user_id', 'rjo_updater_fk')->references('id')->on('users')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        $mysql = DB::getDriverName() !== 'sqlite';

        Schema::table('recruitment_job_offers', function (Blueprint $table) use ($mysql) {
            if ($mysql) {
                $table->dropForeign('rjo_responder_fk');
                $table->dropForeign('rjo_canceller_fk');
                $table->dropForeign('rjo_updater_fk');
            }
            $table->dropColumn([
                'response_recorded_by_user_id', 'response_note', 'expired_at', 'cancelled_at',
                'cancelled_by_user_id', 'cancellation_reason', 'cancellation_note', 'updated_by_user_id',
            ]);
        });

        Schema::table('recruitment_job_offer_versions', function (Blueprint $table) use ($mysql) {
            if ($mysql) {
                $table->dropForeign('rjov_updater_fk');
            }
            $table->dropColumn(['revision_reason', 'updated_by_user_id']);
        });

        // Figures stay encrypted: turning them back into plaintext integers
        // would be the one thing this migration exists to prevent.
    }
};
