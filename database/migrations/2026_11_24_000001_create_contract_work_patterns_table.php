<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** ContractAmendmentStatus::Applied */
    private const APPLIED = 2;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // The working days a contract keeps from a date on. A Study Zone
        // volunteer moves their own day off every few months; each move is a
        // row, so leave and timesheets of the months before keep the days
        // that were worked then. weekdays null = back to the day range and
        // the center's weekend from that date. A row an amendment added
        // points at it.
        Schema::create('contract_work_patterns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->string('weekdays', 20)->nullable();
            $table->date('effective_from');
            $table->foreignId('contract_amendment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['contract_id', 'effective_from']);
        });

        $this->backfill();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contract_work_patterns');
    }

    /**
     * The history the contracts already carry: the days they were signed
     * with (an amendment's "before", else the contract's own), from the start
     * date, then each applied amendment that changed them, from its date.
     */
    private function backfill(): void
    {
        $amendments = DB::table('contract_amendments')
            ->where('status', self::APPLIED)
            ->orderBy('effective_from')
            ->orderBy('number')
            ->get(['id', 'contract_id', 'effective_from', 'approved_by', 'terms_before', 'terms_after'])
            ->map(function ($row) {
                $before = json_decode((string) $row->terms_before, true) ?: [];
                $after = json_decode((string) $row->terms_after, true) ?: [];

                return (object) [
                    'id' => $row->id,
                    'contract_id' => $row->contract_id,
                    'effective_from' => substr((string) $row->effective_from, 0, 10),
                    'approved_by' => $row->approved_by,
                    // Older amendments predate the field: they did not change it.
                    'changes' => array_key_exists('work_weekdays', $before) && array_key_exists('work_weekdays', $after)
                        && ($before['work_weekdays'] ?? null) !== ($after['work_weekdays'] ?? null),
                    'before' => $before['work_weekdays'] ?? null,
                    'after' => $after['work_weekdays'] ?? null,
                ];
            })
            ->filter(fn ($a) => $a->changes)
            ->groupBy('contract_id');

        DB::table('contracts')
            ->whereNotNull('start_date')
            ->where(fn ($q) => $q->where(fn ($q) => $q->whereNotNull('work_weekdays')->where('work_weekdays', '<>', ''))
                ->orWhereIn('id', $amendments->keys()->all()))
            ->orderBy('id')
            ->get(['id', 'work_weekdays', 'start_date'])
            ->each(function ($contract) use ($amendments): void {
                $changes = $amendments->get($contract->id, collect());
                $signed = $changes->isNotEmpty() ? $changes->first()->before : $contract->work_weekdays;
                $start = substr((string) $contract->start_date, 0, 10);
                $rows = [];

                if (filled($signed)) {
                    $rows[$start] = ['weekdays' => $signed, 'contract_amendment_id' => null, 'created_by' => null];
                }

                foreach ($changes as $amendment) {
                    $rows[$amendment->effective_from] = [
                        'weekdays' => filled($amendment->after) ? $amendment->after : null,
                        'contract_amendment_id' => $amendment->id,
                        'created_by' => $amendment->approved_by,
                    ];
                }

                foreach ($rows as $from => $row) {
                    DB::table('contract_work_patterns')->insert($row + [
                        'contract_id' => $contract->id,
                        'effective_from' => $from,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });
    }
};
