@php
    $row = function ($meeting) use ($seeDraft) {
        $tone = $meeting->isCancelled() ? ['stop', 'x', __('board.status.cancelled')]
            : ($meeting->starts_at->isPast() ? ['done', 'check', __('board.status.held')] : ['work', 'clock', __('board.status.scheduled')]);

        return $tone;
    };
@endphp

<div class="space-y-6">
    <x-slot:title>{{ __('board.title') }}</x-slot:title>

    <x-page-header :title="__('board.title')" :lead="__('board.lead')">
        @if($canManage)
            <x-slot:actions>
                <x-button variant="primary" type="button" wire:click="openAdd">@lang('board.add-meeting')</x-button>
            </x-slot:actions>
        @endif
    </x-page-header>

    @if($canManage && $adding)
        <form wire:submit.prevent="saveMeeting" class="space-y-4 rounded-surface border border-line bg-card p-5" data-testid="board-new-meeting">
            <h2 class="text-h4 font-bold text-ink">@lang('board.add-meeting')</h2>
            <p class="text-small text-ink-2">@lang('board.add-meeting-help')</p>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <x-forms.input-label :value="__('board.fields.starts-at')" :required="true" />
                    <x-forms.input type="datetime-local" wire:model="startsAt" />
                    @error('startsAt') <p class="mt-1 text-small font-semibold text-primary" role="alert">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-forms.input-label :value="__('board.fields.ends-at')" />
                    <x-forms.input type="datetime-local" wire:model="endsAt" />
                    @error('endsAt') <p class="mt-1 text-small font-semibold text-primary" role="alert">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-forms.input-label :value="__('board.fields.location')" />
                    <x-forms.input wire:model="location" />
                </div>
                <div>
                    <x-forms.input-label :value="__('board.fields.room')" />
                    <x-forms.input wire:model="room" />
                </div>
            </div>
            <div>
                <x-forms.input-label :value="__('board.fields.purpose')" />
                <x-forms.textarea wire:model="purpose" rows="2" />
            </div>
            <div class="flex gap-2">
                <x-button variant="primary" type="submit">@lang('board.save')</x-button>
                <x-button variant="quiet" type="button" wire:click="$set('adding', false)">@lang('board.close')</x-button>
            </div>
        </form>
    @endif

    <section class="space-y-3" aria-labelledby="upcoming-title">
        <h2 id="upcoming-title" class="text-h4 font-bold text-ink">@lang('board.upcoming')</h2>
        @forelse($upcoming as $meeting)
            @php [$t, $i, $label] = $row($meeting); @endphp
            <a href="{{ route('board.meetings.show', $meeting) }}" wire:navigate wire:key="up-{{ $meeting->id }}"
               class="flex flex-wrap items-center justify-between gap-3 rounded-surface border border-line bg-card p-4 hover:bg-hover">
                <div>
                    <p class="font-bold text-ink">@lang('board.meeting-number', ['number' => $meeting->number])</p>
                    <p class="text-small text-ink-2"><bdi class="num">{{ $meeting->starts_at->translatedFormat('l j F Y') }} · {{ $meeting->starts_at->format('H:i') }}</bdi></p>
                    @if($meeting->isCancelled() && $meeting->cancel_reason)<p class="text-small text-ink-2">{{ $meeting->cancel_reason }}</p>@endif
                </div>
                <x-status :tone="$t" :icon="$i" :label="$label" />
            </a>
        @empty
            <p class="rounded-surface border border-dashed border-line p-5 text-ink-2">@lang('board.none-upcoming')</p>
        @endforelse
    </section>

    <section class="space-y-3" aria-labelledby="past-title">
        <h2 id="past-title" class="text-h4 font-bold text-ink">@lang('board.earlier')</h2>
        @forelse($past as $meeting)
            @php [$t, $i, $label] = $row($meeting); @endphp
            <a href="{{ route('board.meetings.show', $meeting) }}" wire:navigate wire:key="past-{{ $meeting->id }}"
               class="flex flex-wrap items-center justify-between gap-3 rounded-surface border border-line-soft bg-card p-4 hover:bg-hover">
                <div>
                    <p class="font-bold text-ink">@lang('board.meeting-number', ['number' => $meeting->number])</p>
                    <p class="text-small text-ink-2"><bdi class="num">{{ $meeting->starts_at->translatedFormat('l j F Y') }}</bdi></p>
                </div>
                <div class="flex items-center gap-2">
                    @if($meeting->isPublished())
                        <x-status tone="done" icon="check" :label="__('board.minutes.published-badge')" />
                    @elseif($seeDraft && ! $meeting->isCancelled())
                        <x-status tone="attn" icon="clock" :label="__('board.minutes.no-minutes')" />
                    @endif
                    <x-status :tone="$t" :icon="$i" :label="$label" />
                </div>
            </a>
        @empty
            <p class="rounded-surface border border-dashed border-line p-5 text-ink-2">@lang('board.none-earlier')</p>
        @endforelse
    </section>
</div>
