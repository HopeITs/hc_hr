<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Durable claim ledger for scheduled notifications. The unique key is what
     * makes "once per subject, recipient and day" atomic across overlapping
     * workers: the insert either wins the claim or fails, with no read-then-write
     * window. Rows are kept after delivery (they are the audit of what was sent),
     * and the notifications table itself is never touched.
     */
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key', 191)->unique();
            $table->string('kind', 64);
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('subject_id');
            $table->date('local_date');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['kind', 'local_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};
