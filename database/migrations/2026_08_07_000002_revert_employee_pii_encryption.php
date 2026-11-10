<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Undoes 2026_07_31_000004_encrypt_employee_pii_columns: PII encryption at
 * rest has been dropped from the application entirely, so the widened
 * columns and blind index are no longer needed.
 *
 * A database that went through `security:encrypt-pii` still holds ciphertext,
 * and that command no longer exists. So the values are decrypted here first:
 * narrowing the columns over ciphertext either fails ("Data too long") or,
 * worse, leaves unreadable values behind for code that no longer decrypts.
 *
 * MySQL DDL is not transactional, so every step is written to be safe to run
 * again after a partial failure.
 */
return new class extends Migration
{
    private const ENCRYPTED_COLUMNS = [
        'national_number',
        'civil_registry_number',
        'civil_registry_office',
        'address_en',
        'address_ar',
        'personal_phone',
        'work_phone',
        'personal_email',
        'whatsapp_work',
        'telegram_work',
    ];

    public function up(): void
    {
        $this->decryptExistingValues();

        if (Schema::hasColumn('employees', 'national_number_index')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->dropUnique(['national_number_index']);
                $table->dropColumn('national_number_index');
            });
        }

        Schema::table('employees', function (Blueprint $table) {
            $table->char('national_number', 255)->nullable()->change();
            $table->string('civil_registry_office')->nullable()->change();
            $table->string('civil_registry_number')->nullable()->change();
            $table->string('work_phone')->nullable()->change();
            $table->string('personal_phone')->nullable()->change();
            $table->string('personal_email')->nullable()->change();
            $table->string('whatsapp_work')->nullable()->change();
            $table->string('telegram_work')->nullable()->change();
        });

        if (! $this->hasUniqueIndexOn('national_number')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->unique('national_number');
            });
        }
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            foreach ([
                'national_number', 'civil_registry_office', 'civil_registry_number',
                'work_phone', 'personal_phone', 'personal_email', 'whatsapp_work', 'telegram_work',
            ] as $column) {
                $table->text($column)->nullable()->change();
            }

            $table->string('national_number_index', 64)->nullable()->after('national_number');
            $table->unique('national_number_index');
        });
    }

    /**
     * Replace every ciphertext with its plaintext. A value that looks
     * encrypted but cannot be decrypted (wrong APP_KEY) stops the migration
     * instead of being kept or truncated: the data stays exactly as it was.
     */
    private function decryptExistingValues(): void
    {
        DB::table('employees')->select(['id', ...self::ENCRYPTED_COLUMNS])->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    $changes = [];

                    foreach (self::ENCRYPTED_COLUMNS as $column) {
                        $value = $row->{$column};

                        if (! is_string($value) || ! str_starts_with($value, 'eyJ')) {
                            continue;
                        }

                        try {
                            $changes[$column] = Crypt::decryptString($value);
                        } catch (DecryptException) {
                            throw new RuntimeException(
                                "Employee #{$row->id} has an encrypted {$column} that this APP_KEY cannot decrypt. "
                                .'Restore the APP_KEY the data was encrypted with, then run the migration again.',
                            );
                        }
                    }

                    if ($changes !== []) {
                        DB::table('employees')->where('id', $row->id)->update($changes);
                    }
                }
            });
    }

    private function hasUniqueIndexOn(string $column): bool
    {
        foreach (Schema::getIndexes('employees') as $index) {
            if ($index['unique'] && $index['columns'] === [$column]) {
                return true;
            }
        }

        return false;
    }
};
