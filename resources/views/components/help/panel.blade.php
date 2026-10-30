{{--
    Page-context help (D3). Rendered once per full page by the app layout, next
    to the tour overlay; behaviour in resources/js/help-panel.js.

      - Topics for this page come from App\Support\HelpTopics, resolved here
        during the full page render, in the page's language. Opening,
        searching and reading never call the server, so a form or dialog
        behind the panel keeps everything typed into it.
      - A read-only dialog (A5-M: ordinary dismissal): Escape, the close
        button and a backdrop click all close it, and focus returns to the
        button that opened it.
      - x-trap.inert pauses any form dialog's own trap underneath and restores
        it on close, the same way the confirm dialog stacks over a form.
      - A drawer on the inline-end side: right in English, left in Arabic.
--}}
@php
    $help = \App\Support\HelpTopics::forRequest(request(), auth()->user());

    // "N topics match" for every possible N, with the language's own plural
    // forms (Arabic has six), so the browser never builds a plural itself.
    $resultLabels = array_map(
        fn (int $n) => trans_choice('help.panel.results', $n, ['count' => $n]),
        range(0, count($help['topics'])),
    );
@endphp

<div x-data="helpPanel(@js($help))"
     x-init="resultLabels = @js($resultLabels)"
     x-on:help-open.window="openAt($event.detail?.topic, $event.target)"
     {{-- Focus is trapped inside, so this only sees keys pressed in the panel;
          .stop keeps them from the tour, menus and dialogs listening on window. --}}
     x-on:keydown.escape.prevent.stop="close()"
     x-show="open"
     {{-- Opened over a form dialog, this root was hidden by that dialog's x-trap.inert. --}}
     x-effect="open && $el.removeAttribute('aria-hidden')"
     data-help-panel
     class="fixed inset-0 z-[1100]"
     style="display: none;">

    <div x-show="open"
         x-transition.opacity.duration.200ms
         x-on:click="close()"
         aria-hidden="true"
         class="absolute inset-0 bg-backdrop motion-reduce:transition-none"></div>

    {{-- tabindex="-1": a click on plain text inside keeps focus in the panel instead of dropping it to <body>. --}}
    <section x-show="open"
             x-ref="panel"
             tabindex="-1"
             x-trap.inert.noscroll.noautofocus="open"
             x-transition:enter="transition duration-300 ease-fluid motion-reduce:transition-none"
             x-transition:enter-start="ltr:translate-x-full rtl:-translate-x-full"
             x-transition:enter-end="translate-x-0"
             x-transition:leave="transition duration-200 ease-in motion-reduce:transition-none"
             x-transition:leave-start="translate-x-0"
             x-transition:leave-end="ltr:translate-x-full rtl:-translate-x-full"
             role="dialog"
             aria-modal="true"
             aria-labelledby="help-panel-title"
             data-testid="help-panel"
             class="absolute inset-y-0 end-0 flex w-full flex-col bg-card shadow-e3 focus:outline-none sm:w-[28rem] sm:max-w-[calc(100vw-2rem)]">

        <header class="flex items-center justify-between gap-3 border-b border-line-soft px-4 pb-3 pt-[max(0.75rem,env(safe-area-inset-top))]">
            <h2 id="help-panel-title" class="text-h4 font-semibold text-ink">{{ __('help.panel.title') }}</h2>

            <button type="button"
                    x-on:click="close()"
                    aria-label="{{ __('help.panel.close') }}"
                    class="-me-2 grid size-11 shrink-0 place-items-center rounded-control text-ink-3 transition hover:bg-hover hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 motion-reduce:transition-none">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" focusable="false">
                    <path d="M6 6l12 12M18 6L6 18"/>
                </svg>
            </button>
        </header>

        <div class="border-b border-line-soft px-4 py-3">
            <label for="help-panel-search" class="sr-only">{{ __('help.panel.search-label') }}</label>
            <div class="relative">
                <svg class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-ink-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" focusable="false">
                    <circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>
                </svg>
                <input id="help-panel-search"
                       x-ref="search"
                       x-model="query"
                       x-on:input="active = null"
                       type="search"
                       autocomplete="off"
                       enterkeyhint="search"
                       placeholder="{{ __('help.panel.search-placeholder') }}"
                       class="hc-field block ps-9 pe-3">
            </div>
            {{-- Spoken when the result count changes while typing. --}}
            <p class="sr-only" aria-live="polite" aria-atomic="true"
               x-text="searching ? resultLabels[results.length] : ''"></p>
        </div>

        <div x-ref="body" class="flex-1 overflow-y-auto overscroll-contain px-4 py-4" data-csb-exclude>

            {{-- Topic list: this page's topics first, then the rest; or the search results. --}}
            <div x-show="!active">
                <template x-if="!searching">
                    <div class="space-y-5">
                        <template x-if="pageTopics.length">
                            <section aria-labelledby="help-panel-page-heading">
                                <h3 id="help-panel-page-heading" class="mb-2 text-caption font-semibold uppercase tracking-wide text-ink-2">{{ __('help.panel.on-this-page') }}</h3>
                                <ul class="space-y-2">
                                    <template x-for="item in pageTopics" :key="item.key">
                                        <li>@include('components.help.partials.topic-link')</li>
                                    </template>
                                </ul>
                            </section>
                        </template>

                        <template x-if="otherTopics.length">
                            <section aria-labelledby="help-panel-other-heading">
                                <h3 id="help-panel-other-heading" class="mb-2 text-caption font-semibold uppercase tracking-wide text-ink-2"
                                    x-text="pageTopics.length ? @js(__('help.panel.other-topics')) : @js(__('help.panel.all-topics'))"></h3>
                                <ul class="space-y-2">
                                    <template x-for="item in otherTopics" :key="item.key">
                                        <li>@include('components.help.partials.topic-link')</li>
                                    </template>
                                </ul>
                            </section>
                        </template>
                    </div>
                </template>

                <template x-if="searching">
                    <div>
                        <ul class="space-y-2" x-show="results.length">
                            <template x-for="item in results" :key="item.key">
                                <li>@include('components.help.partials.topic-link')</li>
                            </template>
                        </ul>
                        <p x-show="!results.length" class="rounded-control bg-soft px-3 py-4 text-body text-ink-2"
                           x-text="@js(__('help.panel.no-results', ['query' => '__Q__'])).replace('__Q__', query.trim())"></p>
                    </div>
                </template>
            </div>

            {{-- One topic. --}}
            <article x-show="active" aria-labelledby="help-panel-topic-title">
                <button type="button"
                        x-on:click="back()"
                        class="-ms-2 mb-3 inline-flex min-h-11 items-center gap-1.5 rounded-control px-2 text-small font-semibold text-ink transition hover:bg-hover focus-visible:outline-2 focus-visible:outline-offset-2 motion-reduce:transition-none">
                    <svg class="size-4 rtl:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                        <path d="M15 18l-6-6 6-6"/>
                    </svg>
                    {{ __('help.panel.back') }}
                </button>

                <h3 id="help-panel-topic-title" x-ref="heading" tabindex="-1"
                    class="text-h4 font-bold text-ink focus:outline-none"
                    x-text="topic?.title"></h3>
                <p class="mt-1.5 text-body text-ink-2" x-text="topic?.summary"></p>

                <ul class="mt-4 space-y-3">
                    <template x-for="(point, i) in (topic?.points ?? [])" :key="i">
                        <li class="flex gap-2.5 text-body leading-relaxed text-ink">
                            <span class="mt-2.5 size-1.5 shrink-0 rounded-round bg-ink-3" aria-hidden="true"></span>
                            <span x-text="point"></span>
                        </li>
                    </template>
                </ul>
            </article>
        </div>

        <p class="border-t border-line-soft px-4 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] text-caption text-ink-2">
            {{ __('help.panel.stays-open') }}
        </p>
    </section>
</div>
