<?php

namespace App\Services;

use App\Models\MailList;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fills the mailing lists from the "Mail Lists" sheet (read once into
 * database/data/mail-lists.json; parse() is the reader of its layout).
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

            // A block can hold further lists below the first: an email address
            // in the block's first column starts a new list, whose members are
            // the emails that follow in the second column.
            $current = $address;

            foreach (array_slice($rows, 1) as $row) {
                $nested = $this->email($row[$column] ?? null);

                if ($nested !== null) {
                    $current = $nested;
                }

                $lists[$current] ??= [
                    'address' => $current,
                    'name' => Str::headline(str_replace(['.', '_', '-'], ' ', Str::before($current, '@'))),
                    'emails' => [],
                ];

                $email = $this->email($row[$column + 1] ?? null);

                if ($email !== null) {
                    $lists[$current]['emails'][$email] = $email;
                }
            }
        }

        return array_values(array_map(
            fn (array $list) => [...$list, 'emails' => array_values($list['emails'])],
            $lists,
        ));
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

    /**
     * Load lists from a JSON file ([{address, name, emails: []}, …]), the form
     * parse() produces. Every member loaded is already in the group.
     *
     * @return array{lists: int, members: int}
     */
    public function importFile(string $path): array
    {
        $parsed = json_decode((string) file_get_contents($path), true);

        return $this->import(is_array($parsed) ? $parsed : []);
    }

    private function email(mixed $cell): ?string
    {
        $value = Str::lower(trim((string) $cell));

        return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null;
    }
}
