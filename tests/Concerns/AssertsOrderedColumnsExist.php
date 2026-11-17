<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catches an ORDER BY on a column that does not exist.
 *
 * The suite runs on sqlite and the application runs on MySQL, and the two
 * disagree about exactly this: sqlite quietly ignores `order by name` when no
 * such column exists, while MySQL refuses with a 500. So a screen that orders a
 * list by the wrong column name passes every test and breaks the moment anybody
 * opens it — which is how a translatable `name_en` column came to be ordered by
 * `name` with a green suite behind it.
 *
 * Column names are checked against the schema rather than against a list kept
 * here, so this keeps working as tables change.
 */
trait AssertsOrderedColumnsExist
{
    /**
     * Run the callback and fail if any query orders by a column that is absent.
     *
     * @param  callable():mixed  $work
     */
    protected function assertEveryOrderedColumnExists(callable $work): mixed
    {
        $queries = [];

        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $result = $work();

        foreach ($queries as $sql) {
            foreach ($this->orderedColumns($sql) as [$table, $column]) {
                $this->assertTrue(
                    Schema::hasColumn($table, $column),
                    "Ordering by a column that does not exist: {$table}.{$column}\n".
                    "sqlite ignores this; MySQL returns a 500.\nSQL: {$sql}",
                );
            }
        }

        return $result;
    }

    /**
     * The (table, column) pairs a single-table query orders by.
     *
     * Only queries with one table are examined: a join makes the owning table
     * of an unqualified column ambiguous, and guessing wrong would fail a test
     * over code that is correct.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function orderedColumns(string $sql): array
    {
        if (! preg_match('/\bfrom\s+"([a-z0-9_]+)"/i', $sql, $from)) {
            return [];
        }

        if (preg_match_all('/\b(?:from|join)\s+"([a-z0-9_]+)"/i', $sql, $tables)
            && count(array_unique($tables[1])) > 1) {
            return [];
        }

        if (! preg_match('/\border\s+by\s+(.+?)(?:\s+limit\b|\s*$)/is', $sql, $clause)) {
            return [];
        }

        $columns = [];

        foreach (explode(',', $clause[1]) as $term) {
            // Qualified terms carry their own table and are checked as given;
            // anything that is not a plain quoted identifier — a function, a
            // subquery, a raw expression — is skipped rather than guessed at.
            if (preg_match('/^\s*"([a-z0-9_]+)"\."([a-z0-9_]+)"/i', $term, $qualified)) {
                $columns[] = [$qualified[1], $qualified[2]];
            } elseif (preg_match('/^\s*"([a-z0-9_]+)"\s*(?:asc|desc)?\s*$/i', $term, $bare)) {
                $columns[] = [$from[1], $bare[1]];
            }
        }

        return $columns;
    }
}
