<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_lists', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            // The group's own address (lower case), e.g. managers@hcsyria.org.
            $table->string('address', 190)->unique();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('mail_list_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_list_id')->constrained('mail_lists')->cascadeOnDelete();
            // Lower case. Matched to an employee through the account's work email.
            $table->string('email', 190);
            $table->timestamps();

            $table->unique(['mail_list_id', 'email']);
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_list_members');
        Schema::dropIfExists('mail_lists');
    }
};
