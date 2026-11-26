@php
    use App\Models\BoardMeetingAttendee as A;

    $err = fn (string $key) => $errors->first($key);
    $attTone = [
        A::EXPECTED => ['wait', 'clock'], A::PRESENT => ['done', 'check'], A::ONLINE => ['done', 'check'], A::ABSENT => ['stop', 'x'],
    ];
@endphp

<div class="space-y-6">
    <x-slot:title>{{ __('board.meeting-number', ['number' => $meeting->number]) }}</x-slot:title>

    <x-page-header :title="__('board.meeting-number', ['number' => $meeting->number])"
                   :lead="$meeting->starts_at->translatedFormat('l j F Y').' · '.$meeting->starts_at->format('H:i')">
        <x-slot:actions>
            <x-button variant="quiet" :href="route('board.meetings.index')" wire:navigate>@lang('board.back')</x-button>
            @if($canEdit && $minutesVisible && ! $meeting->isCancelled())
                @if($sheetMode)
                    <x-button variant="outline" type="button" wire:click="setMode('edit')">{{ $editLabel }}</x-button>
                @else
                    <x-button variant="outline" type="button" wire:click="setMode('view')">@lang('board.minutes.view-mode')</x-button>
                @endif
            @endif
            @if($minutesVisible && ! $meeting->isCancelled())
                <x-button variant="outline" :href="route('board.meetings.pdf', $meeting)">@lang('board.minutes.download-pdf')</x-button>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if($sheetMode)
        {{-- The minutes as the printed sheet: what the PDF holds, read on the page. --}}
        {{-- Paper, in light and dark alike: white page, dark ink (never the theme's colours). --}}
        <div class="mx-auto max-w-3xl rounded-surface border border-line p-6 shadow-sm sm:p-10" data-testid="board-sheet" dir="rtl"
             style="background:#FFFFFF;color:#414042;color-scheme:light">
            @include('board.minutes-sheet', $sheet)
        </div>
    @else
    @if($meeting->isCancelled())
        <div class="rounded-surface border-2 border-dashed border-line bg-card p-5" data-testid="board-cancelled">
            <x-status tone="stop" icon="x" :label="__('board.status.cancelled')" />
            <p class="mt-2 text-ink">{{ $meeting->cancel_reason }}</p>
            @if($canManage)
                <x-button class="mt-3" variant="outline" type="button" wire:click="restoreMeeting">@lang('board.cancel.restore')</x-button>
            @endif
        </div>
    @endif

    {{-- 1. The meeting: shown to all; the chair edits it. --}}
    <section class="space-y-3 rounded-surface border border-line bg-card p-5" aria-labelledby="details-title">
        <h2 id="details-title" class="text-h4 font-bold text-ink">@lang('board.details')</h2>
        @if($canManage)
            <form wire:submit.prevent="saveDetails" class="space-y-4">
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
                    <div><x-forms.input-label :value="__('board.fields.location')" /><x-forms.input wire:model="location" /></div>
                    <div><x-forms.input-label :value="__('board.fields.room')" /><x-forms.input wire:model="room" /></div>
                    <div class="sm:col-span-2"><x-forms.input-label :value="__('board.fields.led-by')" /><x-forms.input wire:model="ledBy" /></div>
                </div>
                <div><x-forms.input-label :value="__('board.fields.purpose')" /><x-forms.textarea wire:model="purpose" rows="2" /></div>
                <p class="text-small text-ink-2">@lang('board.move-tells')</p>
                <x-button variant="primary" type="submit">@lang('board.save')</x-button>
            </form>

            @unless($meeting->isCancelled())
                <form wire:submit.prevent="cancelMeeting" class="space-y-2 border-t border-line-soft pt-4">
                    <x-forms.input-label :value="__('board.cancel.reason')" :required="true" />
                    <x-forms.textarea wire:model="cancelReason" rows="2" />
                    @error('cancelReason') <p class="text-small font-semibold text-primary" role="alert">{{ $message }}</p> @enderror
                    <x-button variant="outline" type="submit">@lang('board.cancel.action')</x-button>
                </form>
            @endunless
        @else
            <dl class="grid gap-3 sm:grid-cols-2">
                @foreach([
                    'location' => $meeting->location, 'room' => $meeting->room, 'led-by' => $meeting->led_by,
                    'ends-at' => $meeting->ends_at?->format('H:i'), 'purpose' => $meeting->purpose,
                ] as $key => $value)
                    @if($value)
                        <div><dt class="text-small text-ink-2">@lang('board.fields.'.$key)</dt><dd class="text-ink">{{ $value }}</dd></div>
                    @endif
                @endforeach
            </dl>
        @endif
    </section>

    {{-- 2. Who is coming. A member asks to miss it; the chair answers; the secretary sets the real state. --}}
    <section class="space-y-3 rounded-surface border border-line bg-card p-5" aria-labelledby="att-title">
        <h2 id="att-title" class="text-h4 font-bold text-ink">@lang('board.attendance.title')</h2>

        @if($isMember && $meeting->isUpcoming() && ! $meeting->isPublished() && ! in_array($mine?->absence_request, ['pending', 'approved'], true))
            <form wire:submit.prevent="requestAbsence" class="space-y-2 rounded-surface border border-line-soft bg-soft p-4" data-testid="board-absence-form">
                <x-forms.input-label :value="__('board.absence.ask')" />
                <x-forms.textarea wire:model="absenceReason" rows="2" :placeholder="__('board.absence.reason')" />
                @error('absenceReason') <p class="text-small font-semibold text-primary" role="alert">{{ $message }}</p> @enderror
                <x-button variant="outline" type="submit">@lang('board.absence.send')</x-button>
            </form>
        @elseif($mine?->absence_request === 'pending')
            <x-status tone="wait" icon="clock" :label="__('board.absence.waiting')" />
        @endif

        <ul class="divide-y divide-line-soft">
            @foreach($attendees as $a)
                @php [$t, $i] = $attTone[$a->status] ?? ['wait', 'clock']; @endphp
                <li class="flex flex-wrap items-center justify-between gap-3 py-3" wire:key="att-{{ $a->id }}">
                    <div>
                        <p class="font-semibold text-ink">{{ $a->employee?->name }}</p>
                        @if($a->absence_reason && ($canManage || $canWrite || $a->employee_id === auth()->user()->employee_id))
                            <p class="text-small text-ink-2">{{ $a->absence_reason }}</p>
                        @endif
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        @if($a->absence_request === 'pending')
                            <x-status tone="attn" icon="clock" :label="__('board.absence.pending')" />
                            @if($canManage)
                                <x-button size="sm" variant="primary" type="button" wire:click="decideAbsence({{ $a->id }}, true)">@lang('board.absence.approve')</x-button>
                                <x-button size="sm" variant="outline" type="button" wire:click="decideAbsence({{ $a->id }}, false)">@lang('board.absence.reject')</x-button>
                            @endif
                        @endif
                        @if($canWrite)
                            <select wire:change="setAttendance({{ $a->id }}, $event.target.value)" aria-label="{{ __('board.attendance.set') }}"
                                    class="rounded-control border border-line bg-card px-2 py-1 text-small text-ink">
                                @foreach(A::STATUSES as $s)
                                    <option value="{{ $s }}" @selected($a->status === $s)>@lang('board.attendance.'.$s)</option>
                                @endforeach
                            </select>
                        @else
                            <x-status :tone="$t" :icon="$i" :label="__('board.attendance.'.$a->status)" />
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    </section>

    {{-- 3. The minutes: free narrative sections, then the follow-up table. Draft for the writers until published. --}}
    @if($minutesVisible && ! $meeting->isCancelled())
        <section class="space-y-4 rounded-surface border border-line bg-card p-5" aria-labelledby="min-title" data-testid="board-minutes">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 id="min-title" class="text-h4 font-bold text-ink">@lang('board.minutes.title')</h2>
                @if($meeting->isPublished())
                    <x-status tone="done" icon="check" :label="__('board.minutes.published-badge')" />
                @else
                    <x-status tone="attn" icon="clock" :label="__('board.minutes.draft')" />
                @endif
            </div>

            @foreach($sections as $s)
                <article class="space-y-1 border-t border-line-soft pt-3" wire:key="sec-{{ $s->id }}">
                    <div class="flex items-start justify-between gap-2">
                        <h3 class="font-bold text-ink">{{ $s->title }}</h3>
                        @if($canWrite)
                            <div class="flex gap-1">
                                <x-button size="sm" variant="quiet" type="button" wire:click="editSection({{ $s->id }})">@lang('board.edit')</x-button>
                                <x-button size="sm" variant="quiet" type="button" wire:click="removeSection({{ $s->id }})" wire:confirm="{{ __('board.minutes.remove-confirm') }}">@lang('board.remove')</x-button>
                            </div>
                        @endif
                    </div>
                    <div class="whitespace-pre-line text-ink">{{ $s->body }}</div>
                </article>
            @endforeach

            @if($canWrite)
                <form wire:submit.prevent="saveSection" class="space-y-2 rounded-surface border border-line-soft bg-soft p-4">
                    <x-forms.input-label :value="__('board.minutes.section-title')" :required="true" />
                    <x-forms.input wire:model="sectionTitle" :placeholder="__('board.minutes.section-title-example')" />
                    @error('sectionTitle') <p class="text-small font-semibold text-primary" role="alert">{{ $message }}</p> @enderror
                    <x-forms.input-label :value="__('board.minutes.section-body')" />
                    <x-forms.textarea wire:model="sectionBody" rows="8" />
                    <x-button variant="outline" type="submit">{{ $sectionId ? __('board.save') : __('board.minutes.add-section') }}</x-button>
                </form>
            @endif

            {{-- The follow-up table --}}
            <div class="space-y-2 border-t border-line-soft pt-4">
                <h3 class="font-bold text-ink">@lang('board.minutes.follow-title')</h3>
                @if($followUps->isNotEmpty())
                    <div class="overflow-x-auto">
                        <table class="w-full text-start text-table">
                            <thead>
                                <tr class="text-table-head text-ink-2">
                                    <th class="p-2 text-start">@lang('board.minutes.follow-topic')</th>
                                    <th class="p-2 text-start">@lang('board.minutes.follow-party')</th>
                                    <th class="p-2 text-start">@lang('board.minutes.follow-required')</th>
                                    @if($canWrite)<th class="p-2"></th>@endif
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($followUps as $f)
                                    <tr class="border-t border-line-soft" wire:key="fu-{{ $f->id }}">
                                        <td class="p-2 font-semibold text-ink">{{ $f->topic }}</td>
                                        <td class="p-2 text-ink">{{ $f->party }}</td>
                                        <td class="p-2 text-ink">{{ $f->required }}</td>
                                        @if($canWrite)
                                            <td class="p-2 whitespace-nowrap">
                                                <x-button size="sm" variant="quiet" type="button" wire:click="editFollowUp({{ $f->id }})">@lang('board.edit')</x-button>
                                                <x-button size="sm" variant="quiet" type="button" wire:click="removeFollowUp({{ $f->id }})" wire:confirm="{{ __('board.minutes.remove-confirm') }}">@lang('board.remove')</x-button>
                                            </td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="text-ink-2">@lang('board.minutes.no-follow-ups')</p>
                @endif

                @if($canWrite)
                    <form wire:submit.prevent="saveFollowUp" class="grid gap-2 rounded-surface border border-line-soft bg-soft p-4 sm:grid-cols-3">
                        <div>
                            <x-forms.input-label :value="__('board.minutes.follow-topic')" :required="true" />
                            <x-forms.input wire:model="followUpTopic" />
                            @error('followUpTopic') <p class="text-small font-semibold text-primary" role="alert">{{ $message }}</p> @enderror
                        </div>
                        <div><x-forms.input-label :value="__('board.minutes.follow-party')" /><x-forms.input wire:model="followUpParty" /></div>
                        <div><x-forms.input-label :value="__('board.minutes.follow-required')" /><x-forms.input wire:model="followUpRequired" /></div>
                        <div class="sm:col-span-3"><x-button variant="outline" type="submit">{{ $followUpId ? __('board.save') : __('board.minutes.add-follow-up') }}</x-button></div>
                    </form>
                @endif
            </div>

            @if($canWrite)
                <div class="space-y-3 border-t border-line-soft pt-4">
                    <form wire:submit.prevent="saveMinutesHeader" class="flex flex-wrap items-end gap-2">
                        <div class="min-w-60 flex-1">
                            <x-forms.input-label :value="__('board.minutes.prepared-by')" />
                            <x-forms.input wire:model="preparedBy" />
                        </div>
                        <x-button variant="quiet" type="submit">@lang('board.save')</x-button>
                    </form>

                    <label class="flex items-center gap-2 text-ink">
                        <input type="checkbox" wire:model="sendEmail" class="rounded border-line">
                        @lang('board.minutes.send-email')
                    </label>
                    <x-button variant="primary" type="button" wire:click="publish" wire:confirm="{{ __('board.minutes.publish-confirm') }}">
                        @lang('board.minutes.publish')
                    </x-button>
                    <p class="text-small text-ink-2">@lang('board.minutes.publish-help')</p>
                </div>
            @endif
        </section>
    @endif
    @endif
</div>
