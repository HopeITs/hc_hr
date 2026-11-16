<?php

namespace App\Services;

use App\Models\MailList;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fills the mailing lists from the "Mail Lists" sheet exported as CSV.
 *
 * The sheet puts each list in a block of three columns: the list's own address
 * on the first row, in the block's first column, then a "#" / "Emails" header
 * row, then one member email per row in the block's second column. parse()
 * finds every block by its address, so the number of lists and their order do
 * not matter. import() replaces each list's members with the file's, matching
 * the list by its address, and leaves lists the file does not mention alone.
 */
class MailListImporter
{
    /**
     * @param  array<int, array<int, string|null>>  $rows
     * @return array<int, array{address: string, name: string, emails: array<int, string>}>
     */
    public function parse(array $rows): array
    {
        $rows = array_values($rows);
        $header = $rows[0] ?? [];
        $lists = [];

        foreach ($header as $column => $cell) {
            $address = $this->email($cell);

            if ($address === null) {
                continue;
            }

            $emails = [];

            foreach (array_slice($rows, 1) as $row) {
                $email = $this->email($row[$column + 1] ?? null);

                if ($email !== null) {
                    $emails[$email] = $email;
                }
            }

            $lists[$address] = [
                'address' => $address,
                'name' => Str::headline(str_replace(['.', '_', '-'], ' ', Str::before($address, '@'))),
                'emails' => array_values($emails),
            ];
        }

        return array_values($lists);
    }

    /**
     * @param  array<int, array{address: string, name: string, emails: array<int, string>}>  $parsed
     * @return array{lists: int, members: int}
     */
    public function import(array $parsed): array
    {
        $members = 0;

        DB::transaction(function () use ($parsed, &$members): void {
            foreach ($parsed as $position => $entry) {
                $list = MailList::query()->updateOrCreate(
                    ['address' => $entry['address']],
                    ['name' => $entry['name'], 'position' => $position],
                );

                $list->members()->whereNotIn('email', $entry['emails'])->delete();

                $existing = $list->members()->pluck('email')->all();

                foreach (array_diff($entry['emails'], $existing) as $email) {
                    $list->members()->create(['email' => $email]);
                }

                $members += count($entry['emails']);
            }
        });

        return ['lists' => count($parsed), 'members' => $members];
    }

    /** @return array<int, array<int, string|null>> the rows of a CSV file */
    public function readCsv(string $path): array
    {
        $rows = [];
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return [];
        }

        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $rows[] = $row;
        }

        fclose($handle);

        // A byte order mark would hide the first list's address.
        if (isset($rows[0][0])) {
            $rows[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $rows[0][0]);
        }

        return $rows;
    }

    private function email(mixed $cell): ?string
    {
        $value = Str::lower(trim((string) $cell));

        return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null;
    }
}
