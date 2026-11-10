<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Services\ContractPdfRenderer;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ContractExportController extends Controller
{
    public function __construct(private readonly ContractPdfRenderer $renderer) {}

    /**
     * Read-only and idempotent: rendering never creates or touches a contract,
     * so a failed export is safe to retry against the same contract.
     *
     * A render that throws or yields no PDF answers 503 with a short plain-text
     * reason (the export UI shows it and offers a retry) rather than a 500 page
     * or a 0-byte "PDF" with a 200.
     */
    public function pdf(Contract $contract)
    {
        // Authorization stays outside the try: a refusal is a 403, never a 503.
        Gate::authorize('export', $contract);

        abort_unless(auth()->user()->canAccessCity($contract->employee?->city_id), 403);

        try {
            $pdf = $this->renderer->render($contract);
        } catch (Throwable $exception) {
            return $this->exportFailed($contract, $exception);
        }

        if (! str_starts_with($pdf, '%PDF-')) {
            return $this->exportFailed($contract);
        }

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$this->renderer->fileName($contract).'"',
        ]);
    }

    // Local-only: renders the export as plain HTML (not through Browsershot), so
    // the print layout can be iterated on in the browser's own devtools. The
    // page carries the pay clause, so it is held to the same gates as the PDF.
    public function preview(Contract $contract)
    {
        abort_unless(app()->isLocal(), 404);

        Gate::authorize('export', $contract);

        abort_unless(auth()->user()->canAccessCity($contract->employee?->city_id), 403);

        return $this->renderer->view($contract);
    }

    private function exportFailed(Contract $contract, ?Throwable $exception = null): Response
    {
        // The id and a clipped reason only: a process failure's message can
        // echo the node command, and the page it was given carries the pay
        // clause, so the full message never goes to the log.
        Log::error('Contract PDF export failed.', [
            'contract_id' => $contract->getKey(),
            'user_id' => auth()->id(),
            'reason' => $exception
                ? $exception::class.': '.Str::limit($exception->getMessage(), 300)
                : 'Renderer returned no PDF.',
        ]);

        return response(__('contracts.export-failed'), 503, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'Retry-After' => '5',
        ]);
    }
}
