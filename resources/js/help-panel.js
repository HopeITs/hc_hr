/**
 * Page-context help panel (D3).
 *
 * All topics arrive with the page (App\Support\HelpTopics, rendered by
 * <x-help.panel>), already in the page's language, so opening help, searching
 * and moving between topics happen entirely in the browser. Nothing here calls
 * Livewire: an open form, a half-filled dialog or the contract composer keep
 * every value while help is on screen.
 *
 * Opened by a `help-open` window event:
 *   - the header button sends it with no topic (the list, current page first);
 *   - a "?" beside a field (<x-help.trigger>) sends { topic } to open that
 *     topic directly.
 */

// Folds the differences a reader does not type: case, Arabic short vowels and
// tatweel, and the alef / ya / ta marbuta variants, so «اجازة» finds «إجازة».
export function normalizeForSearch(text) {
    return String(text ?? '')
        .toLowerCase()
        .replace(/[ً-ٰٟـ]/g, '')
        .replace(/[أإآٱ]/g, 'ا')
        .replace(/ى/g, 'ي')
        .replace(/ة/g, 'ه')
        .replace(/ؤ/g, 'و')
        .replace(/ئ/g, 'ي')
        .replace(/\s+/g, ' ')
        .trim();
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('helpPanel', (help) => ({
        open: false,
        query: '',
        active: null, // key of the topic on show, or null for the list
        opener: null,
        resultLabels: [], // "N topics match" per N, set from the page (plural forms)
        topics: (help?.topics ?? []).map((topic) => ({
            ...topic,
            haystack: normalizeForSearch([topic.title, topic.summary, topic.keywords, ...topic.points].join(' ')),
        })),
        current: help?.current ?? [],

        get topic() {
            return this.topics.find((topic) => topic.key === this.active) ?? null;
        },

        get pageTopics() {
            return this.current.map((key) => this.topics.find((topic) => topic.key === key)).filter(Boolean);
        },

        get otherTopics() {
            return this.topics.filter((topic) => !this.current.includes(topic.key));
        },

        get searching() {
            return normalizeForSearch(this.query) !== '';
        },

        // Every word of the query must appear somewhere in the topic.
        get results() {
            const words = normalizeForSearch(this.query).split(' ').filter(Boolean);

            return this.topics.filter((topic) => words.every((word) => topic.haystack.includes(word)));
        },

        openAt(key, source) {
            const trigger = source instanceof Element ? source.closest('button, a, [tabindex]') : null;
            this.opener = trigger ?? (document.activeElement !== document.body ? document.activeElement : null);
            this.query = '';
            this.active = this.topics.some((topic) => topic.key === key) ? key : null;

            if (this.open) {
                this.focusView();
                return;
            }

            this.open = true;
            this.focusView();
        },

        show(key) {
            this.active = key;
            this.focusView();
        },

        back() {
            const from = this.active;
            this.active = null;
            this.$nextTick(() => {
                // Back lands on the topic just read, so a keyboard user keeps their place.
                const row = this.$root.querySelector(`[data-help-topic-link="${CSS.escape(from ?? '')}"]`);
                (row ?? this.$refs.search)?.focus({ preventScroll: false });
            });
        },

        close() {
            if (!this.open) return;

            this.open = false;
            const opener = this.opener;
            this.opener = null;

            // x-trap hands focus back to what was focused when it opened; a
            // button clicked in Safari never takes focus, so make sure it lands.
            setTimeout(() => {
                const lost = !document.activeElement || document.activeElement === document.body
                    || this.$root.contains(document.activeElement);

                if (!lost || !opener?.isConnected) return;

                // Opened from a menu that has closed since (the phone account
                // menu): land on that menu's own button instead.
                const target = opener.getClientRects().length
                    ? opener
                    : opener.closest('[x-data]')?.querySelector('[aria-haspopup]');
                target?.focus({ preventScroll: true });
            }, 60);
        },

        // The panel is revealed on the next frame; focus after it is painted.
        // Opened from inside a form dialog, that dialog's focus trap is still
        // active for a moment (x-trap activates ours on a later tick) and pulls
        // focus straight back to the "?". So keep checking briefly, as the
        // confirm dialog does, until focus is really inside the panel.
        focusView(attempt = 0) {
            this.$nextTick(() => {
                const target = this.active ? this.$refs.heading : this.$refs.search;

                if (!target || !target.getClientRects().length) {
                    if (this.open && attempt < 20) setTimeout(() => this.focusView(attempt + 1), 25);
                    return;
                }

                target.focus({ preventScroll: true });
                if (this.active) this.$refs.body?.scrollTo?.({ top: 0 });

                [60, 200, 500].forEach((ms) => setTimeout(() => {
                    const panel = this.$refs.panel;
                    if (this.open && panel && !panel.contains(document.activeElement)) {
                        (this.active ? this.$refs.heading : this.$refs.search)?.focus({ preventScroll: true });
                    }
                }, ms));
            });
        },
    }));
});
