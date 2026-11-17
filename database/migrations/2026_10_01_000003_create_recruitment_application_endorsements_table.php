<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_application_endorsements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id');
            $table->foreignId('user_id');
            $table->string('voter_role', 20); // 'hr' | 'manager'
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['application_id', 'user_id'], 'raen_application_user_uq');
            $table->index(['application_id', 'voter_role'], 'raen_application_role_idx');
            $table->index('user_id', 'raen_user_idx');

            $table->foreign('application_id', 'raen_application_fk')
                ->references('id')->on('recruitment_applications')->cascadeOnDelete();
            $table->foreign('user_id', 'raen_user_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_application_endorsements');
    }
};
