<?php

namespace App\Console\Commands;

use App\Models\Contract;
use App\Services\ContractPdfRenderer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Reports what the host actually does when asked to render a contract PDF.
 *
 * Browsershot keeps raw stdout, stderr and the exit code to itself whenever the
 * node process exits 0 — which is the case that fails silently — so this command
 * runs the very same node command itself with all three captured, after checking
 * each thing the render depends on.
 */
class ContractExportDoctor extends Command
{
    protected $signature = 'contracts:export-doctor
                            {contract? : Render this contract instead of a trivial page}
                            {--keep : Save the rendered PDF to storage/app/export-doctor.pdf}';

    protected $description = 'Diagnose contract PDF export on this host (Chrome, node, permissions, raw render output)';

    private bool $failed = false;

    public function handle(ContractPdfRenderer $renderer): int
    {
        $this->section('PHP');
        $this->line('  version        '.PHP_VERSION.'  (sapi: '.PHP_SAPI.')');
        $this->line('  memory_limit   '.ini_get('memory_limit'));
        $this->check('proc_open available', function_exists('proc_open'),
            'Symfony Process cannot spawn node without it — remove it from disable_functions.');
        $this->warnIf(
            (bool) config('app.debug') && app()->environment('production'),
            'APP_DEBUG is on in production: a failed export is exposing your stack traces.',
        );

        $this->section('Configuration (config/browsershot.php)');
        foreach (['chrome_path', 'node_binary', 'timeout'] as $key) {
            $value = config("browsershot.$key");
            $this->line(sprintf('  %-12s %s', $key, $value === null || $value === '' ? '(unset)' : var_export($value, true)));
        }

        $this->section('Chrome');
        $chrome = (string) config('browsershot.chrome_path');
        // On connect the local Chrome is never launched, so checking it would
        // only fail a host that renders fine.
        if (! $this->usesRemoteChrome($renderer) && $this->check('chrome_path exists', $chrome !== '' && is_file($chrome), 'Not a file: '.$chrome.'. On Linux this is usually /usr/bin/chromium — install it with the package manager rather than unpacking one by hand.')) {
            $this->check('chrome_path executable', is_executable($chrome), 'chmod +x '.$chrome.', or the web user cannot run it.');
            $this->probe([$chrome, '--version'], 'chrome --version', timeout: 15);
            $this->launchesAlone($chrome, $renderer);
        }

        $this->section('Node');
        $node = (string) (config('browsershot.node_binary') ?: 'node');
        $this->probe([$node, '--version'], 'node --version');
        $browserCjs = base_path('vendor/spatie/browsershot/bin/browser.cjs');
        $this->check('browser.cjs present', is_file($browserCjs), 'Missing '.$browserCjs.' — run composer install on this host.');

        // browser.cjs does require('puppeteer'), resolved from its own directory
        // upwards. Asking node the same question from the same place is the only
        // answer that means anything.
        $resolve = $this->probe(
            [$node, '-e', "console.log(require.resolve('puppeteer'))"],
            "node require.resolve('puppeteer')",
            cwd: dirname($browserCjs),
        );
        $this->check('puppeteer resolvable from browser.cjs', $resolve['ok'],
            'Run `npm install --omit=dev` on the server.');

        $this->section('Writable directories');
        $workDir = $renderer->workDir();
        $this->check('work dir writable ('.$workDir.')', is_dir($workDir) && is_writable($workDir),
            'Chrome needs somewhere to put its config and the rendered file.');

        $this->section('Render');

        return $this->attemptRender($renderer) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Report BROWSERSHOT_WS_ENDPOINT, if set, without the token it carries.
     *
     * True when the render goes to a remote Chrome — and also when the endpoint
     * is refused, since the render then fails for that reason alone.
     */
    private function usesRemoteChrome(ContractPdfRenderer $renderer): bool
    {
        try {
            $endpoint = $renderer->remoteEndpoint();
        } catch (Throwable $e) {
            $this->check('ws_endpoint accepted', false, $e->getMessage());

            return true;
        }

        if ($endpoint === null) {
            return false;
        }

        $parts = parse_url($endpoint);
        $shown = ($parts['scheme'] ?? '').'://'.($parts['host'] ?? '')
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .($parts['path'] ?? '');

        $this->line('  ws_endpoint    '.$shown.'  (query hidden)');
        $this->line('  <fg=yellow>NOTE</> Remote Chrome: chrome_path is not used, and every contract, pay clause included, is rendered by that service.');

        return true;
    }

    /**
     * Start Chrome with nothing between it and us.
     *
     * "Target closed" is only puppeteer reporting that the process went away;
     * Chrome itself normally says why on its own stderr, and that message never
     * reaches Browsershot.
     */
    private function launchesAlone(string $chrome, ContractPdfRenderer $renderer): void
    {
        $profile = $renderer->workDir().'/probe-'.getmypid();
        File::ensureDirectoryExists($profile, 0755, true);

        try {
            $result = $this->capture(array_merge([
                $chrome,
                '--headless=new',
                '--no-sandbox',
                '--no-first-run',
                '--user-data-dir='.$profile,
                '--enable-logging=stderr',
                '--virtual-time-budget=2000',
            ], array_map(
                // The same switches the render uses, so the two agree.
                fn (string $flag) => '--'.$flag,
                $renderer->chromiumArguments(),
            ), [
                '--dump-dom',
                'about:blank',
            ]), env: [
                'HOME' => $profile,
                'CHROME_CONFIG_HOME' => $renderer->workDir().'/.config',
            ], timeout: 60);
        } finally {
            File::deleteDirectory($profile);
        }

        $ok = str_contains($result['out'], '<body');

        $this->check('chrome opens a page on its own', $ok,
            'Chrome cannot start a browser on this host — puppeteer is not the problem. Its own output follows.');

        if (! $ok) {
            $this->dump($result['out']);
        }
    }

    private function attemptRender(ContractPdfRenderer $renderer): bool
    {
        $contract = null;

        if ($id = $this->argument('contract')) {
            $contract = Contract::find($id);

            if (! $contract) {
                $this->check('contract '.$id.' exists', false, 'No such contract; falling back to a trivial page.');
            }
        }

        $html = $contract
            ? $renderer->view($contract, inlineAssets: true)->render()
            : '<html><body><h1>export doctor</h1></body></html>';

        $this->line('  source         '.($contract ? 'contract #'.$contract->getKey() : 'trivial page')
            .' ('.number_format(strlen($html) / 1024, 1).' KB of HTML)');

        $target = $renderer->workDir().'/export-doctor.pdf';

        try {
            $browsershot = $renderer->browsershot($html);
            $command = $browsershot->nodeCommand($target);

            $process = is_array($command)
                ? new Process($command, null, $browsershot->nodeEnvironment())
                : Process::fromShellCommandline($command);

            $process->setTimeout((int) config('browsershot.timeout') + 30);

            $started = microtime(true);
            $process->run();

            $stdout = $process->getOutput();
            $stderr = $process->getErrorOutput();
            $pdf = is_file($target) ? (string) file_get_contents($target) : '';

            $this->line('  exit code      '.var_export($process->getExitCode(), true)
                .'  ('.round(microtime(true) - $started, 1).'s)');
            $this->line('  stdout         '.strlen($stdout).' bytes'.($stdout === '' ? '' : ': '.$this->snippet($stdout)));
            $this->line('  stderr         '.strlen($stderr).' bytes');
            $this->line('  pdf file       '.strlen($pdf).' bytes');

            $ok = str_starts_with($pdf, '%PDF-');
            $this->check('PDF produced', $ok, 'Chrome did not write a usable PDF. Its full output follows.');

            if (! $ok) {
                // Printed whole: the line that explains a failed launch is
                // routinely the last one, and a snippet always cuts it off.
                $this->dump($stderr);
            }

            if ($ok && $this->option('keep')) {
                $this->line('  saved          '.$target);
            }

            return $ok && ! $this->failed;
        } catch (Throwable $e) {
            $this->check('render completed', false, class_basename($e).': '.$e->getMessage());

            return false;
        } finally {
            if (! ($this->option('keep') && is_file($target))) {
                File::delete($target);
            }
        }
    }

    /**
     * @param  array<int, string>  $command
     * @param  array<string, string>  $env
     * @return array{ok: bool, out: string}
     */
    private function probe(array $command, string $label, ?string $cwd = null, array $env = [], int $timeout = 30): array
    {
        $result = $this->capture($command, $cwd, $env, $timeout);

        $this->line(sprintf(
            '  %s %s %s',
            $result['ok'] ? '<fg=green>OK  </>' : '<fg=red>FAIL</>',
            $label,
            $this->snippet($result['out']),
        ));

        return $result;
    }

    /**
     * @param  array<int, string>  $command
     * @param  array<string, string>  $env
     * @return array{ok: bool, out: string}
     */
    private function capture(array $command, ?string $cwd = null, array $env = [], int $timeout = 30): array
    {
        try {
            $process = new Process($command, $cwd, $env === [] ? null : $env);
        } catch (Throwable $e) {
            return ['ok' => false, 'out' => $e->getMessage()];
        }

        $process->setTimeout($timeout);
        $thrown = null;

        try {
            $process->run();
        } catch (Throwable $e) {
            // A process killed by a signal — Chrome aborting is signal 6 — makes
            // run() throw, and everything it printed before dying is still on the
            // process object. Reading it anyway is the whole point of this command.
            $thrown = $e;
        }

        $out = trim($process->getOutput()."\n".$process->getErrorOutput());

        return [
            'ok' => $thrown === null && $process->isSuccessful(),
            'out' => $thrown ? trim($thrown->getMessage()."\n".$out) : $out,
        ];
    }

    private function dump(string $output): void
    {
        foreach (explode("\n", trim($output)) as $line) {
            $this->line('       <fg=gray>'.addcslashes($line, "\0..\10\13..\37").'</>');
        }
    }

    private function check(string $label, bool $ok, string $remedy = ''): bool
    {
        $this->line(sprintf('  %s %s', $ok ? '<fg=green>OK  </>' : '<fg=red>FAIL</>', $label));

        if (! $ok) {
            $this->failed = true;

            if ($remedy !== '') {
                $this->line('       <fg=yellow>→ '.$remedy.'</>');
            }
        }

        return $ok;
    }

    private function warnIf(bool $condition, string $message): void
    {
        if ($condition) {
            $this->line('  <fg=yellow>WARN</> '.$message);
        }
    }

    private function snippet(string $value, int $limit = 300): string
    {
        return Str::limit(addcslashes(trim(substr($value, 0, $limit + 100)), "\0..\37"), $limit);
    }

    private function section(string $title): void
    {
        $this->newLine();
        $this->line('<fg=cyan;options=bold>'.$title.'</>');
    }
}
