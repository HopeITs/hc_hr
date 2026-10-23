<?php

namespace App\Services;

use App\Models\Contract;
use App\Support\InspectableBrowsershot;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Renders a contract to PDF through a headless Chrome driven by Browsershot.
 *
 * Point BROWSERSHOT_CHROME_PATH at the host's own Chromium (on a Linux server,
 * usually /usr/bin/chromium from the package manager, whose dependencies the
 * package manager has already satisfied) and BROWSERSHOT_NODE_BINARY at node if
 * it is not on the web user's PATH. `contracts:export-doctor` drives this same
 * class and reports what the host does with it.
 */
class ContractPdfRenderer
{
    /**
     * What a remote Chrome navigates to. Node answers it from memory, so it
     * must never resolve to a real host (.invalid is reserved, RFC 2606).
     */
    public const REMOTE_CONTENT_URL = 'https://contract-export.invalid/';

    /** Pages in the printed layout, per contract category. */
    private const PAGES_PER_CATEGORY = ['annual' => 5, 'trial' => 4, 'service' => 4];

    /**
     * The printable contract as a Blade view.
     *
     * $inlineAssets embeds fonts and images as data URIs: the headless browser
     * renders from a file:// temp dir and can't fetch asset() URLs back through
     * the app's own web server. On-screen pages keep using normal URLs.
     */
    public function view(Contract $contract, bool $inlineAssets = false): View
    {
        $contract->loadMissing([
            'employee', 'employee.city',
            'position.center', 'position.department', 'position.jobLevel', 'position.program',
            'contractType',
        ]);

        $category = $this->category($contract);
        $locale = app()->getLocale();

        return view('pdf.contract', [
            'contract' => $contract,
            'category' => $category,
            'totalPages' => self::PAGES_PER_CATEGORY[$category] ?? 1,
            'locale' => $locale,
            'dir' => $locale === 'ar' ? 'rtl' : 'ltr',
            'fileTitle' => $this->fileName($contract),
            'inlineAssets' => $inlineAssets,
        ]);
    }

    public function category(Contract $contract): string
    {
        return $contract->contractType?->category ?? 'annual';
    }

    public function fileName(Contract $contract): string
    {
        return sprintf(
            '%s-%s-%s.pdf',
            Str::slug($contract->employee?->name ?? 'contract'),
            $this->category($contract),
            now()->format('Ymd'),
        );
    }

    /**
     * @throws RuntimeException
     */
    public function render(Contract $contract): string
    {
        return $this->renderHtml($this->view($contract, inlineAssets: true)->render());
    }

    /**
     * @throws RuntimeException
     */
    public function renderHtml(string $html): string
    {
        $target = $this->workDir().'/'.Str::random(16).'.pdf';

        $pdf = '';

        try {
            // Chrome writes the PDF to disk itself. pdf() would instead have it
            // base64-ed back through node's stdout, and Browsershot treats a
            // stdout it cannot parse as a success — it decodes null and returns
            // an empty string, which is how a broken render used to reach the
            // browser as a 200 with a 0-byte body.
            $this->browsershot($html)->savePdf($target);

            $pdf = is_file($target) ? (string) file_get_contents($target) : '';
        } finally {
            File::delete($target);
        }

        if (! str_starts_with($pdf, '%PDF-')) {
            throw new RuntimeException('Chrome did not write a PDF.');
        }

        return $pdf;
    }

    public function browsershot(string $html): InspectableBrowsershot
    {
        $browsershot = InspectableBrowsershot::html($html)
            ->setChromePath(config('browsershot.chrome_path'))
            ->timeout((int) config('browsershot.timeout'))
            // Without this Browsershot asks puppeteer for the old headless mode,
            // which Chromium removed in M132 — a current /usr/bin/chromium then
            // refuses to launch at all.
            ->newHeadless()
            ->noSandbox()
            ->addChromiumArguments($this->chromiumArguments())
            ->waitUntilNetworkIdle()
            ->ignoreHttpsErrors()
            // node otherwise echoes the URL of every request the page made back
            // on stdout — and since the fonts and images are inlined as data
            // URIs, that is the whole document again, ~350 KB of it per render.
            // Nothing reads the list.
            ->disableCaptureURLS()
            // Both of these shape the printed page, not the launch: without
            // them the brand-red headers print white and the @page size in the
            // stylesheet is ignored.
            ->showBackground()
            ->setOption('preferCSSPageSize', true)
            // Chrome reads this before XDG_CONFIG_HOME and exits at startup if
            // it cannot write there, which is the usual fate on a host whose
            // web user has no writable home.
            ->setEnvironmentOptions([
                'CHROME_CONFIG_HOME' => $this->workDir().'/.config',
            ]);

        if ($nodeBinary = config('browsershot.node_binary')) {
            $browsershot->setNodeBinary($nodeBinary);
        }

        if ($endpoint = $this->remoteEndpoint()) {
            // A remote Chrome cannot open the local file:// page and must not
            // be handed a public URL to the contract either. Node keeps the
            // HTML and answers this one URL from it through request
            // interception, so the page never sits anywhere it could be
            // fetched. The launch options above are ignored on connect.
            $browsershot->setWSEndpoint($endpoint)
                ->setContentUrl(self::REMOTE_CONTENT_URL);
        }

        return $browsershot;
    }

    /**
     * The opt-in remote Chrome (BROWSERSHOT_WS_ENDPOINT), or null for the local one.
     *
     * The printed contract carries the pay clause, so it only leaves the host
     * over an encrypted socket — plain ws:// is accepted for loopback alone,
     * e.g. a browserless container on the same machine.
     *
     * @throws RuntimeException
     */
    public function remoteEndpoint(): ?string
    {
        $endpoint = trim((string) config('browsershot.ws_endpoint'));

        if ($endpoint === '') {
            return null;
        }

        $scheme = strtolower((string) parse_url($endpoint, PHP_URL_SCHEME));
        $host = strtolower(trim((string) parse_url($endpoint, PHP_URL_HOST), '[]'));

        $loopback = in_array($host, ['localhost', '127.0.0.1', '::1'], true);

        if ($scheme !== 'wss' && ! ($scheme === 'ws' && $loopback)) {
            throw new RuntimeException('BROWSERSHOT_WS_ENDPOINT must use wss:// (ws:// is allowed only on loopback).');
        }

        return $endpoint;
    }

    /**
     * The three switches a locked-down server needs, and no more.
     *
     * Each answers a line the host puts in Chrome's own log:
     *
     *   pcilib: Cannot open /sys/bus/pci/devices/…: Permission denied
     *   GPU process exited unexpectedly: exit_code=256   (six times)
     *   FATAL: GPU process isn't usable. Goodbye.
     *
     * The GPU process identifies the card through libpci, and a host that hides
     * /sys/bus/pci kills it every time; after six deaths Chrome aborts the whole
     * browser. --in-process-gpu leaves no separate process to count. Deliberately
     * absent: --disable-software-rasterizer, which would take away the SwiftShader
     * fallback that makes running without a GPU work at all.
     *
     * @return array<int, string>
     */
    public function chromiumArguments(): array
    {
        return [
            'disable-gpu',
            'in-process-gpu',
            // /dev/shm is commonly present but read-only on these hosts; this
            // sends Chrome's shared memory to /tmp instead.
            'disable-dev-shm-usage',
        ];
    }

    /**
     * A directory the web user owns, for Chrome's config and the rendered file.
     */
    public function workDir(): string
    {
        $dir = storage_path('app/chrome');

        File::ensureDirectoryExists($dir.'/.config', 0755, true);

        return $dir;
    }
}
