{{--
    The workspace (dashboard/workspace.js): pages the dashboard leads to open here, over the
    dashboard, as tabs, instead of taking the person away. Each tab is the page itself in a
    frame of this site (data-ws-frame: shown without the site's own sidebar and header).
    wire:ignore: the dashboard's own re-renders must never reload an open page.
--}}
<div x-data="hcWorkspace" data-home="{{ route('dashboard') }}" data-ws-panel wire:ignore
     class="hc-ws" x-show="open" x-cloak
     x-transition:enter="hc-ws-enter" x-transition:enter-start="hc-ws-from" x-transition:enter-end="hc-ws-to"
     x-transition:leave="hc-ws-leave" x-transition:leave-start="hc-ws-to" x-transition:leave-end="hc-ws-from"
     role="region" aria-label="{{ __('dashboard.workspace.label') }}" data-testid="dashboard-workspace">
    <div class="hc-ws-panel" x-ref="panel" tabindex="-1">
        <div class="hc-ws-bar">
            <div class="hc-ws-tabs" role="tablist" aria-label="{{ __('dashboard.workspace.tabs') }}">
                <template x-for="tab in tabs" :key="tab.id">
                    <div class="hc-ws-tab" :class="{ 'is-on': tab.id === active }">
                        <button type="button" role="tab" class="hc-ws-tab-name"
                                :id="'tab-' + tab.id" :aria-controls="'pane-' + tab.id"
                                :aria-selected="(tab.id === active).toString()"
                                x-on:click="show(tab.id)"
                                x-text="tab.title || @js(__('dashboard.workspace.loading'))"></button>
                        <button type="button" class="hc-ws-tab-x" x-on:click="close(tab.id)"
                                :aria-label="@js(__('dashboard.workspace.close-tab', ['page' => '__P__'])).replace('__P__', tab.title)">
                            <x-ic name="x" />
                        </button>
                    </div>
                </template>
            </div>
            <div class="hc-ws-actions">
                <button type="button" class="hc-ws-btn" x-on:click="pop()" title="{{ __('dashboard.workspace.open-page') }}" aria-label="{{ __('dashboard.workspace.open-page') }}">
                    <x-ic name="arrow" class="rtl:-scale-x-100" />
                </button>
                <button type="button" class="hc-ws-btn" x-on:click="closeAll()" title="{{ __('dashboard.workspace.close') }}" aria-label="{{ __('dashboard.workspace.close') }}" data-testid="workspace-close">
                    <x-ic name="x" />
                </button>
            </div>
        </div>
        <div class="hc-ws-body">
            <template x-for="tab in tabs" :key="tab.id">
                {{-- src set once: following the page inside must not reload it. --}}
                <iframe data-ws-frame class="hc-ws-frame" x-show="tab.id === active"
                        :id="'pane-' + tab.id" role="tabpanel" :aria-labelledby="'tab-' + tab.id"
                        :title="tab.title"
                        x-init="$el.src = tab.url" x-on:load="frameLoaded(tab, $el)"></iframe>
            </template>
        </div>
    </div>
</div>
