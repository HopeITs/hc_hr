<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * B4-D personal saved table views (table-contract.md section 5.1). One row is
 * the table STATE a user chose to keep (search, filters, sort, per-page,
 * columns), tied to the role context it was saved under. Never row data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_table_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('table', 100);
            $table->string('role_context', 64);
            $table->string('name', 60);
            $table->json('state');
            $table->timestamps();

            $table->unique(['user_id', 'table', 'role_context', 'name'], 'saved_table_views_unique_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_table_views');
    }
};
