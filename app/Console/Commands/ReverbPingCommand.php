<?php

namespace App\Console\Commands;

use App\Events\ReverbPing;
use App\Models\User;
use Illuminate\Console\Command;
use Throwable;

class ReverbPingCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reverb:ping {email : Email of the user whose open pages should receive the ping}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send a realtime test message to one user, to check that Reverb is reachable end to end';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error('No user with that email.');

            return self::FAILURE;
        }

        try {
            ReverbPing::dispatch($user->id);
        } catch (Throwable $e) {
            $this->error('Could not reach Reverb: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Ping sent. Any page {$user->email} has open logs \"[reverb] ping received\" in the browser console.");

        return self::SUCCESS;
    }
}
