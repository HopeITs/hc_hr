<?php

namespace Tests\Concerns;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;

/**
 * Plays the evening run from routes/console.php over a range of days.
 *
 * Each module's tests call its own command once, on a date of their choosing,
 * and often set up by hand what an earlier command of the same evening would
 * have done (a contract made inactive before the auto-renewal, say). The
 * commands are ordered on purpose (amendments 21:25 before deactivation 21:30
 * before auto-renewal 21:35 …), and that order is only exercised in
 * production. This trait reads the evening commands from the real schedule,
 * in their real order, and runs them at their real times, one day after
 * another, so a scenario sees what the server would.
 */
trait RunsTheNightlySchedule
{
    /** Evening commands start at this hour; earlier daily ones (reminders, sweeps) are left out. */
    protected int $nightFromHour = 21;

    /** @var list<string> artisan command names to leave out of the run (e.g. noisy notifiers) */
    protected array $nightSkips = [];

    /**
     * The daily commands scheduled from $nightFromHour on, in the order the
     * scheduler runs them: [['at' => 'HH:MM', 'command' => 'name'], …].
     *
     * @return Collection<int, array{at: string, command: string}>
     */
    protected function nightlyCommands(): Collection
    {
        // Loads routes/console.php, which is what fills the Schedule.
        $this->app->make(Kernel::class)->bootstrap();
        Artisan::all();

        return collect($this->app->make(Schedule::class)->events())
            ->map(function (Event $event): ?array {
                [$minute, $hour, $dom, $month, $dow] = explode(' ', $event->expression);

                if (! ctype_digit($minute) || ! ctype_digit($hour) || $dom !== '*' || $month !== '*' || $dow !== '*') {
                    return null; // not a once-a-day command
                }

                if (! preg_match("/artisan'?\"?\s+'?\"?([\w:-]+)/", (string) $event->command, $m)) {
                    return null;
                }

                return ['at' => sprintf('%02d:%02d', $hour, $minute), 'command' => $m[1]];
            })
            ->filter(fn (?array $c) => $c !== null
                && (int) substr($c['at'], 0, 2) >= $this->nightFromHour
                && ! in_array($c['command'], $this->nightSkips, true))
            ->sortBy('at')
            ->values();
    }

    /**
     * Run one evening: each command at its scheduled minute on $date.
     *
     * @return array<string, string> command => its output, for diagnosis
     */
    protected function runNight(string|Carbon $date): array
    {
        $day = Carbon::parse($date)->toDateString();
        $outputs = [];

        foreach ($this->nightlyCommands() as $command) {
            $this->travelTo(Carbon::parse("{$day} {$command['at']}:00"));

            $exit = Artisan::call($command['command']);
            $outputs[$command['command']] = trim(Artisan::output());

            $this->assertSame(0, $exit, "{$command['command']} failed on {$day}: {$outputs[$command['command']]}");
        }

        return $outputs;
    }

    /**
     * Run every evening from $from to $to inclusive, calling $afterEach($day)
     * after each one so invariants can be checked night by night.
     */
    protected function runNights(string $from, string $to, ?callable $afterEach = null): void
    {
        for ($day = Carbon::parse($from); $day->lte(Carbon::parse($to)); $day = $day->copy()->addDay()) {
            $this->runNight($day);

            if ($afterEach) {
                $afterEach($day->toDateString());
            }
        }
    }
}
