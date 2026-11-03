<?php

namespace App\Console\Commands;

use App\Services\Notifications\WebPushSender;
use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;
use Throwable;

/**
 * Prints a new VAPID pair for .env. It never writes .env itself: replacing
 * the pair silently would cut off every device subscribed with the old one
 * (browsers bind a subscription to the public key).
 */
class GenerateVapidKeys extends Command
{
    protected $signature = 'webpush:vapid
        {--show : Only show the public key this environment uses now}';

    protected $description = 'Generate a VAPID key pair for Web Push and print the .env lines';

    public function handle(): int
    {
        if ($this->option('show')) {
            if (! WebPushSender::configured()) {
                $this->warn('Web Push is not configured: VAPID_PUBLIC_KEY, VAPID_PRIVATE_KEY and VAPID_SUBJECT must all be set.');

                return self::FAILURE;
            }

            $this->line('VAPID_SUBJECT='.config('webpush.vapid.subject'));
            $this->line('VAPID_PUBLIC_KEY='.config('webpush.vapid.public_key'));
            $this->line('VAPID_PRIVATE_KEY is set (not shown).');

            return self::SUCCESS;
        }

        if (WebPushSender::configured()) {
            $this->warn('This environment already has a VAPID pair. Replacing it disconnects every subscribed device until it enables push again.');
        }

        try {
            $keys = VAPID::createVapidKeys();
        } catch (Throwable $e) {
            $this->error('Could not create the key pair: '.$e->getMessage());
            // PHP on Windows ships OpenSSL without telling it where its
            // config is, and EC keys cannot be made without one. Sending
            // push needs the same, so set it for the web server too.
            if (PHP_OS_FAMILY === 'Windows') {
                $this->line('Set OPENSSL_CONF before starting PHP, e.g. OPENSSL_CONF='
                    .dirname(PHP_BINARY).DIRECTORY_SEPARATOR.'extras'.DIRECTORY_SEPARATOR.'ssl'.DIRECTORY_SEPARATOR.'openssl.cnf');
            }

            return self::FAILURE;
        }

        $this->info('Add these lines to .env (this command does not write the file):');
        $this->newLine();
        $this->line('VAPID_SUBJECT='.(config('webpush.vapid.subject') ?: 'mailto:it@example.org'));
        $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $this->newLine();
        $this->line('Then run `php artisan config:cache` if the configuration is cached.');

        return self::SUCCESS;
    }
}
