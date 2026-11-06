<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A contract amendment (ملحق عقد): a dated change to the hours, schedule or pay
 * of an active contract, printed and signed on its own sheet.
 *
 * The contract row keeps holding the *current* terms, so everything that reads
 * them (leave pricing, exports, renewals) is untouched; each amendment keeps a
 * before/after snapshot of the amendable terms, which is the history.
 *
 * Pay figures live in their own text columns and pay-tier rows, encrypted with
 * EncryptedSalary like contracts.salary — never inside the JSON snapshots,
 * which would store them in clear.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_amendments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->unsignedTinyInteger('status')->default(0);
            $table->date('effective_from');
            $table->text('reason')->nullable();
            $table->json('terms_before');
            $table->json('terms_after');
            $table->text('salary_before')->nullable();
            $table->text('salary_after')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->unique(['contract_id', 'number']);
            $table->index(['status', 'effective_from']);
        });

        Schema::create('contract_amendment_pay_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_amendment_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('side');
            $table->string('condition_en');
            $table->string('condition_ar');
            $table->text('amount')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_amendment_pay_tiers');
        Schema::dropIfExists('contract_amendments');
    }
};
