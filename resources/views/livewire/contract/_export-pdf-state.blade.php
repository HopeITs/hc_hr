{{--
    Alpine state for the contract PDF export, shared by the details page and
    the contracts list. Output as an x-data object literal, so it must never
    contain a raw double quote (@js escapes its own; regexes use \x22).

    Busy controls use aria-disabled rather than disabled, so keyboard focus
    stays on the button during the request; exportPdf() ignores repeats.

    Export is a plain GET of contracts.export: it only reads the contract, so
    Retry simply repeats the same request and can never create anything.
    Server contract: 503 = render failed (body = contracts.export-failed),
    403 = not allowed, anything else = generic. The 503 message is taken
    from this page's own translation, not from the body, so an unrelated 503
    page (maintenance mode) is never shown as text.
--}}
{
    exportUrl: @js(route('contracts.export', $contract)),
    exportFallbackName: @js('contract-'.$contract->getKey().'.pdf'),
    exportMessages: @js([
        'failed' => __('contracts.export-failed'),
        'forbidden' => __('contracts.export-forbidden'),
        'generic' => __('contracts.export-error'),
    ]),
    exporting: false,
    exportError: null,
    async exportPdf() {
        if (this.exporting) return;

        {{-- The previous error stays up during a retry: hiding it would
             take the focused Retry button away mid-request. --}}
        this.exporting = true;
        let message = this.exportMessages.generic;

        try {
            const response = await fetch(this.exportUrl, {
                headers: { Accept: 'application/pdf', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });

            if (! response.ok) {
                if (response.status === 503) message = this.exportMessages.failed;
                else if (response.status === 403) message = this.exportMessages.forbidden;
                throw new Error(message);
            }

            {{-- A login page or anything else that is not a PDF is a failure,
                 and so is an empty body (the old 0-byte PDF symptom). --}}
            const type = response.headers.get('Content-Type') || '';
            const blob = await response.blob();

            if (! type.includes('application/pdf')) {
                throw new Error(message);
            }

            if (blob.size === 0) {
                message = this.exportMessages.failed;
                throw new Error(message);
            }

            this.saveExport(blob, response.headers.get('Content-Disposition'));
            this.exportError = null;
        } catch (failure) {
            this.exportError = message;
            this.$dispatch('toast', { type: 'error', message });
        } finally {
            this.exporting = false;
        }
    },
    exportFileName(disposition) {
        const header = disposition || '';
        const encoded = /filename\*\s*=\s*(?:UTF-8'')?([^;]+)/i.exec(header);

        if (encoded) {
            try {
                return decodeURIComponent(encoded[1].trim().replace(/^\x22|\x22$/g, ''));
            } catch (e) {}
        }

        const plain = /filename\s*=\s*\x22?([^\x22;]+)\x22?/i.exec(header);

        return plain ? plain[1].trim() : this.exportFallbackName;
    },
    saveExport(blob, disposition) {
        const href = URL.createObjectURL(blob);
        const link = document.createElement('a');

        if ('download' in link) {
            link.href = href;
            link.download = this.exportFileName(disposition);
            link.rel = 'noopener';
            document.body.appendChild(link);
            link.click();
            link.remove();
        } else {
            window.open(href, '_blank', 'noopener');
        }

        setTimeout(() => URL.revokeObjectURL(href), 60000);
    },
}
