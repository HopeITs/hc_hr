<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** ContractAmendmentStatus::Approved, ::Applied */
    private const DECIDED = [1, 2];

    /** ScheduleMode::Flexible */
    private const FLEXIBLE = 2;

    private const DAY_TERMS = ['work_weekdays', 'work_day_from', 'work_day_to', 'schedule_mode'];

    /** @var array<int, true> contracts whose first change has been seen */
    private array $seen = [];

    /**
     * Leave now reads a contract's day range as well as its picked days. An
     * amendment that changed the range before that gave no row, so the days
     * before it would read as the new range. Each decided amendment that
     * changed the week gets its row from its date, and the contract a row
     * from its start with the days it had before its first change (when
     * those were stated, and no row covers the start yet).
     */
    public function up(): void
    {
        DB::table('contract_amendments')
            ->whereIn('status', self::DECIDED)
            ->orderBy('contract_id')
            ->orderBy('effective_from')
            ->orderBy('number')
            ->get(['id', 'contract_id', 'effective_from', 'approved_by', 'terms_before', 'terms_after'])
            ->each(function ($amendment): void {
                $before = json_decode((string) $amendment->terms_before, true) ?: [];
                $after = json_decode((string) $amendment->terms_after, true) ?: [];

                $changed = collect(self::DAY_TERMS)->contains(fn (string $term) => array_key_exists($term, $after)
                    && ($before[$term] ?? null) != ($after[$term] ?? null));

                if (! $changed) {
                    return;
                }

                $contract = DB::table('contracts')->where('id', $amendment->contract_id)->first(['id', 'start_date']);

                if ($contract === null || $contract->start_date === null) {
                    return;
                }

                $start = substr((string) $contract->start_date, 0, 10);
                $from = substr((string) $amendment->effective_from, 0, 10);
                $rows = DB::table('contract_work_patterns')->where('contract_id', $contract->id);

                // Only the contract's first change says what it was signed with.
                $first = ! isset($this->seen[$contract->id]);
                $this->seen[$contract->id] = true;
                $signed = $first ? $this->days($before) : null;

                if ($signed !== null && ! (clone $rows)->whereDate('effective_from', '<=', $start)->exists()) {
                    DB::table('contract_work_patterns')->insert([
                        'contract_id' => $contract->id, 'weekdays' => $signed, 'effective_from' => $start,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }

                $values = [
                    'weekdays' => $this->days($after),
                    'contract_amendment_id' => $amendment->id,
                    'created_by' => $amendment->approved_by,
                    'updated_at' => now(),
                ];

                // Several amendments on one date: the last one is the week.
                $existing = (clone $rows)->whereDate('effective_from', $from)->first(['id', 'contract_amendment_id']);

                if ($existing === null) {
                    DB::table('contract_work_patterns')->insert($values + [
                        'contract_id' => $contract->id, 'effective_from' => $from, 'created_at' => now(),
                    ]);
                } elseif ($existing->contract_amendment_id !== null) {
                    DB::table('contract_work_patterns')->where('id', $existing->id)->update($values);
                }
            });
    }

    /**
     * Rows added here are those of amendments; they are kept on the way down
     * (the code reading them works without them all the same).
     */
    public function down(): void {}

    /** Contract::termsDays(), as a string, frozen here. */
    private function days(array $terms): ?string
    {
        if ((int) ($terms['schedule_mode'] ?? 1) === self::FLEXIBLE) {
            return null;
        }

        $picked = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) ($terms['work_weekdays'] ?? ''))), fn ($d) => $d !== '' && ctype_digit($d) && (int) $d <= 6)));

        if ($picked !== []) {
            sort($picked);

            return implode(',', $picked);
        }

        $from = $terms['work_day_from'] ?? null;
        $to = $terms['work_day_to'] ?? null;

        if (! is_numeric($from) || ! is_numeric($to) || (int) $to < (int) $from) {
            return null;
        }

        return implode(',', range((int) $from, (int) $to));
    }
};
