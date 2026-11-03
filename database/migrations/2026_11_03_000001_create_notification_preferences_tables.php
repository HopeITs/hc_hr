<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Notification preferences (docs/notifications/architecture.md).
     *
     *   notification_preferences  one row per user: the master switch, the
     *                             channel switches, sound / previews, the
     *                             schedule and the per-category matrix (json).
     *                             No row = the defaults.
     *   push_subscriptions        one row per browser / installed app that
     *                             accepted Web Push, with its own settings.
     *   notification_outbox       what still has to go out by push or email,
     *                             and when (quiet hours, digests). The unique
     *                             key keeps one notification from being sent
     *                             twice on one channel.
     */
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->boolean('in_app')->default(true);
            $table->boolean('desktop')->default(true);
            $table->boolean('mobile')->default(true);
            $table->boolean('email')->default(true);
            $table->boolean('sound')->default(true);
            $table->boolean('previews')->default(true);
            $table->string('frequency', 16)->default('instant');
            $table->string('digest_time', 5)->default('09:00');
            $table->boolean('quiet_hours')->default(false);
            $table->string('quiet_start', 5)->default('22:00');
            $table->string('quiet_end', 5)->default('07:00');
            $table->string('timezone', 64)->nullable();
            $table->json('working_days')->nullable();
            $table->boolean('urgent_bypass')->default(true);
            $table->json('categories')->nullable();
            $table->timestamps();
        });

        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('endpoint');
            $table->char('endpoint_hash', 64)->unique();
            $table->string('public_key', 255);
            $table->string('auth_token', 255);
            $table->string('content_encoding', 16)->default('aes128gcm');
            $table->timestamp('expires_at')->nullable();
            $table->string('device_id', 36)->nullable()->index();
            $table->string('device_name', 80)->nullable();
            $table->string('device_type', 16)->default('desktop');
            $table->string('browser', 40)->nullable();
            $table->string('platform', 40)->nullable();
            $table->boolean('standalone')->default(false);
            $table->boolean('enabled')->default(true);
            $table->boolean('urgent_only')->default(false);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->unsignedSmallInteger('failure_count')->default(0);
            $table->string('last_error', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('notification_outbox', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('notification_id')->nullable();
            $table->string('channel', 16);
            $table->string('category', 32);
            $table->boolean('urgent')->default(false);
            $table->json('payload');
            $table->timestamp('deliver_after')->index();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('error', 255)->nullable();
            $table->timestamps();

            $table->unique(['notification_id', 'channel']);
            $table->index(['sent_at', 'deliver_after']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_outbox');
        Schema::dropIfExists('push_subscriptions');
        Schema::dropIfExists('notification_preferences');
    }
};
