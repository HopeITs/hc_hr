{{--
    Quick actions beside a search result (smart search). $actions: list of
      ['kind' => 'link'|'mail'|'external'|'copy'|'templates', 'label', 'href'|'text'|'id'].
    Inside a result line a click must not also open the result, hence x-on:click.stop.
    "templates" lists the message templates, filled in for that person, when it is clicked.
--}}
@if (! empty($actions))
    @php($linkClass = 'inline-flex min-h-8 items-center text-caption font-semibold text-ink-2 underline underline-offset-2 hover:text-ink')
    <span class="mt-1 flex flex-wrap items-center gap-x-4 gap-y-0.5" x-on:click.stop data-testid="search-actions">
        @foreach ($actions as $action)
            @switch($action['kind'])
                @case('copy')
                    <button type="button" class="{{ $linkClass }}"
                            x-on:click.stop="navigator.clipboard?.writeText(@js($action['text'])).then(() => window.dispatchEvent(new CustomEvent('toast', { detail: { type: 'success', message: @js(__('smart_search.actions.copied')) } })))">{{ $action['label'] }}</button>
                    @break
                @case('templates')
                    <span class="relative inline-block" x-data="{
                              shown: false, loading: false, items: null,
                              async toggle() {
                                  this.shown = ! this.shown;
                                  if (this.shown && this.items === null) {
                                      this.loading = true;
                                      try { this.items = await $wire.templatesFor({{ (int) $action['id'] }}); } finally { this.loading = false; }
                                  }
                              },
                          }">
                        <button type="button" class="{{ $linkClass }}" x-on:click.stop="toggle()" x-bind:aria-expanded="shown" data-testid="search-template-action">{{ $action['label'] }}</button>
                        <span x-show="shown" x-cloak class="mt-1 block min-w-48 rounded-control border border-line-soft bg-card p-1 shadow-e2" role="group">
                            <span x-show="loading" class="block px-2 py-1 text-caption text-ink-2">{{ __('smart_search.templates-loading') }}</span>
                            <template x-if="items !== null && items.length === 0">
                                <span class="block px-2 py-1 text-caption text-ink-2">{{ __('smart_search.templates-none') }}</span>
                            </template>
                            {{-- A ready-made announcement opens the composer with the person's name in. --}}
                            <template x-for="item in (items || []).filter(i => i.compose)" :key="'compose-' + item.compose.template">
                                <button type="button" x-on:click.stop="shown = false; Livewire.dispatch('announcement-compose', item.compose)"
                                        class="flex w-full items-center justify-between gap-3 rounded-control px-2 py-1 text-start text-caption font-semibold text-ink hover:bg-hover"
                                        data-testid="search-announcement">
                                    <span x-text="item.name"></span>
                                    <span class="text-ink-3">{{ __('messages.announcements.short') }}</span>
                                </button>
                            </template>
                            <template x-for="item in (items || []).filter(i => ! i.compose)" :key="item.name + item.channel">
                                <a :href="item.url" :target="item.channel === 'whatsapp' ? '_blank' : null" rel="noopener"
                                   class="flex items-center justify-between gap-3 rounded-control px-2 py-1 text-caption font-semibold text-ink hover:bg-hover" x-on:click.stop>
                                    <span x-text="item.name"></span>
                                    <span class="text-ink-3" x-text="item.channel === 'whatsapp' ? 'WhatsApp' : 'Email'"></span>
                                </a>
                            </template>
                        </span>
                    </span>
                    @break
                @default
                    <a href="{{ $action['href'] }}" class="{{ $linkClass }}"
                       @if ($action['kind'] === 'external') target="_blank" rel="noopener" @endif
                       x-on:click.stop>{{ $action['label'] }}</a>
            @endswitch
        @endforeach
    </span>
@endif
