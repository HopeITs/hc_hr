<x-slot:title>
    {{ __('feedback.inbox.title') }}
</x-slot:title>

<div>
    <livewire:layout.breadcrumbs
        :title="__('feedback.inbox.title')"
        :crumbs="[
            ['label' => __('feedback.inbox.title')],
        ]"
    />

    <p class="mt-2 text-body text-ink-2">@lang('feedback.inbox.intro')</p>

    <div class="mt-4 flex flex-wrap items-end gap-3">
        <div class="w-full sm:w-64">
            <x-forms.input-label for="feedback-filter">@lang('feedback.form.category')</x-forms.input-label>
            <select id="feedback-filter" wire:model.live="category"
                    class="mt-1 min-h-11 w-full rounded-control border border-line bg-card px-3 text-body shadow-e1">
                <option value="">@lang('feedback.inbox.all')</option>
                @foreach (config('feedback.categories') as $value)
                    <option value="{{ $value }}">@lang('feedback.categories.'.$value)</option>
                @endforeach
            </select>
        </div>
        <p class="text-small text-ink-2" aria-live="polite">
            {{ trans_choice('feedback.inbox.count', $items->total(), ['count' => $items->total()]) }}
        </p>
    </div>

    @if ($items->isEmpty())
        <div class="mt-6 rounded-surface border border-dashed border-line bg-card p-8 text-center text-body text-ink-2">
            @lang('feedback.inbox.empty')
        </div>
    @else
        <ol class="mt-6 space-y-3" aria-label="{{ __('feedback.inbox.title') }}">
            @foreach ($items as $item)
                <li wire:key="feedback-{{ $item->id }}" class="rounded-surface border border-line-soft bg-card p-4 shadow-e1">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-small text-ink-2">
                        <time datetime="{{ $item->created_at?->toIso8601String() }}"><bdi dir="ltr">{{ $item->created_at?->format('Y-m-d H:i') }}</bdi></time>
                        @if ($item->category)
                            <x-tag variant="soft">@lang('feedback.categories.'.$item->category)</x-tag>
                        @endif
                        <span>{{ $item->user?->profile?->name ?? __('feedback.inbox.deleted-user') }}</span>
                    </div>

                    <p dir="auto" class="mt-2 text-start whitespace-pre-line break-words text-body text-ink">{{ $item->message }}</p>

                    <dl class="mt-3 flex flex-wrap gap-x-5 gap-y-1 text-small text-ink-2">
                        <div class="flex gap-1">
                            <dt>@lang('feedback.disclosure.page'):</dt>
                            <dd><bdi dir="ltr" class="font-mono">{{ $item->route_name ?? '—' }}</bdi></dd>
                        </div>
                        <div class="flex gap-1">
                            <dt>@lang('feedback.disclosure.language'):</dt>
                            <dd>{{ $item->locale === 'ar' ? 'العربية' : 'English' }}</dd>
                        </div>
                        <div class="flex gap-1">
                            <dt>@lang('feedback.disclosure.role'):</dt>
                            <dd><bdi>{{ $item->role_name ? role_label($item->role_name) : '—' }}</bdi></dd>
                        </div>
                    </dl>
                </li>
            @endforeach
        </ol>

        <div class="mt-4">
            {{ $items->links() }}
        </div>
    @endif
</div>
