@php
    use App\Models\BoardMeetingAttendee as A;
    use App\Models\BoardMeetingFollowUp as F;

    $attTone = [
        A::EXPECTED => ['wait', 'clock'], A::PRESENT => ['done', 'check'], A::ONLINE => ['done', 'check'], A::ABSENT => ['stop', 'x'],
    ];
    $followTone = [F::OPEN => ['attn', 'clock'], F::DONE => ['done', 'check'], F::CARRIED => ['wait', 'return']];
    $me = auth()->user();
    $started = $meeting->hasStarted();
    // Who may tick a follow-up row: the secretary, the chair, or the person it waits on.
    $mayTick = fn ($row) => $canTrack || ($row->employee_id !== null && $row->employee_id === $me->employee_id);
@endphp

<div class="space-y-6">
    <x-slot:title>{{ __('board.meeting-number', ['number' => $meeting->number]) }}</x-slot:title>

    <x-page-header :title="__('board.meeting-number', ['number' => $meeting->number])"
                   :lead="$meeting->starts_at->translatedFormat('l j F Y').' · '.$meeting->starts_at->format('H:i')">
        <x-slot:actions>
            <x-button variant="quiet" :href="route('board.meetings.index')" wire:navigate>@lang('board.back')</x-button>
            @if($meeting->isUpcoming())
                <x-button variant="quiet" :href="route('board.meetings.ics', $meeting)">@lang('board.add-to-calendar')</x-button>
            @endif
            @if($canEdit && $minutesVisible && ! $meeting->isCancelled())
                @if($sheetMode)
                    <x-button variant="outline" type="button" wire:click="setMode('edit')">{{ $editLabel }}</x-button>
                @else
                    <x-button variant="outline" type="button" wire:click="setMode('view')">@lang('board.minutes.view-mode')</x-button>
                @endif
            @endif
            @if($meeting->isArchive())
                <x-button variant="outline" :href="route('board.meetings.archive', ['meeting' => $meeting, 'download' => 1])">@lang('board.archive.download')</x-button>
            @elseif($minutesVisible && ! $meeting->isCancelled())
                <x-button variant="outline" :href="route('board.meetings.pdf', $meeting)">@lang('board.minutes.download-pdf')</x-button>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if($sheetMode && $meeting->isArchive())
        {{-- Minutes from before the system: the signed original, shown as it is. --}}
        <section class="mx-auto max-w-4xl space-y-3" data-testid="board-archive">
            <div class="flex flex-wrap items-center gap-3 rounded-surface border border-line bg-card p-4">
                <x-status tone="done" icon="check" :label="__('board.status.held')" />
                <x-status tone="wait" icon="inbox" :label="__('board.archive.badge')" />
                <span class="text-small text-ink-2">
                    {{ $meeting->starts_at->translatedFormat('l j F Y') }}
                    @if($meeting->starts_at->format('H:i') !== '00:00')
                        · <bdi dir="ltr" class="num" style="direction:ltr;unicode-bidi:isolate">{{ $meeting->starts_at->format('H:i') }}@if($meeting->ends_at) – {{ $meeting->ends_at->format('H:i') }}@endif</bdi>
                    @endif
                    @if($meeting->location) · {{ $meeting->location }}@endif
                    @if($meeting->led_by) · @lang('board.fields.led-by'): {{ $meeting->led_by }}@endif
                </span>
            </div>

            @if($archiveIsPdf)
                <iframe src="{{ route('board.meetings.archive', $meeting) }}" title="{{ __('board.meeting-number', ['number' => $meeting->number]) }}"
                        class="w-full rounded-surface border border-line bg-white" style="height:80vh;min-height:32rem"></iframe>
            @else
                <div class="rounded-surface border border-line p-6 sm:p-10" dir="rtl" style="background:#FFFFFF;color:#414042;color-scheme:light">
                    <p class="mb-4 text-small">@lang('board.archive.word-note')</p>
                    <div class="whitespace-pre-line leading-relaxed">{{ $meeting->archive_text ?: '—' }}</div>
                </div>
            @endif

            @if($canRemoveArchive)
                <div class="flex justify-end">
                    <x-button size="sm" variant="quiet" type="button" wire:click="removeArchive" wire:confirm="{{ __('board.archive.remove-confirm') }}">@lang('board.archive.remove')</x-button>
                </div>
            @endif
        </section>
    @elseif($sheetMode)
        {{-- The minutes as the printed sheet: what the PDF holds, read on the page. --}}
        {{-- Paper, in light and dark alike: white page, dark ink (never the theme's colours). --}}
        <div class="mx-auto max-w-3xl rounded-surface border border-line p-6 shadow-sm sm:p-10" data-testid="board-sheet" dir="rtl"
             style="background:#FFFFFF;color:#414042;color-scheme:light">
            @include('board.minutes-sheet', $sheet)
        </div>

        {{-- Where each follow-up stands now: not part of the signed sheet, so it lives beside it. --}}
        @if($meeting->isPublished() && $followUps->isNotEmpty())
            <section class="mx-auto max-w-3xl space-y-3 rounded-surface border border-line bg-card p-5" aria-labelledby="track-title" data-testid="board-follow-track">
                <h2 id="track-title" class="text-h4 font-bold text-ink">@lang('board.follow.track-title')</h2>
                <ul class="divide-y divide-line-soft">
                    @foreach($followUps as $f)
                        @php [$t, $i] = $followTone[$f->status] ?? ['attn', 'clock']; @endphp
                        <li class="flex flex-wrap items-center justify-between gap-3 py-3" wire:key="track-{{ $f->id }}">
                            <div class="min-w-0">
                                <p class="font-semibold text-ink">{{ $f->topic }}</p>
                                <p class="text-small text-ink-2">{{ $f->party ?: '—' }}@if($f->required) · {{ $f->required }}@endif</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <x-status :tone="$t" :icon="$i" :label="__('board.follow.status.'.$f->status)" />
                                @if($f->status !== F::CARRIED && $mayTick($f))
                                    @if($f->isOpen())
                                        <x-button size="sm" variant="outline" type="button" wire:click="markFollowUp({{ $f->id }}, true)">@lang('board.follow.mark-done')</x-button>
                                    @else
                                        <x-button size="sm" variant="quiet" type="button" wire:click="markFollowUp({{ $f->id }}, false)">@lang('board.follow.reopen')</x-button>
                                    @endif
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        {{-- The chair alone may take published minutes back to correct them; the reason is kept. --}}
        @if($canReopen)
            <form wire:submit.prevent="reopenMinutes" class="mx-auto max-w-3xl space-y-2 rounded-surface border border-dashed border-line bg-card p-5" data-testid="board-reopen">
                <h2 class="font-bold text-ink">@lang('board.reopen.title')</h2>
                <p class="text-small text-ink-2">@lang('board.reopen.help')</p>
                <x-forms.textarea wire:model="reopenReason" rows="2" :placeholder="__('board.reopen.reason')" />
                @error('reopenReason') <p class="text-small font-semibold text-primary" role="alert">{{ $message }}</p> @enderror
                <x-button variant="outline" type="submit" wire:confirm="{{ __('board.reopen.confirm') }}">@lang('board.reopen.action')</x-button>
            </form>
        @endif
    @else
    @if($meeting->isCancelled())
        <div class="rounded-surface border-2 border-dashed border-line bg-card p-5" data-testid="board-cancelled">
            <x-status tone="stop" icon="x" :label="__('board.status.cancelled')" />
            <p class="mt-2 text-ink">{{ $meeting->cancel_reason }}</p>
            @if($canManage && ! $started)
                <x-button class="mt-3" variant="outline" type="button" wire:click="restoreMeeting">@lang('board.cancel.restore')</x-button>
            @endif
        </div>
    @endif

    @if($meeting->reopened_at && ! $meeting->isPublished() && ($canWrite || $canManage))
        <div class="rounded-surface border border-line bg-soft p-4 text-ink" data-testid="board-reopened">
            <x-status tone="attn" icon="clock" :label="__('board.reopen.badge')" />
            <p class="mt-2 text-small">@lang('board.reopen.when', ['date' => $meeting->reopened_at->translatedFormat('j F Y H:i')]) {{ $meeting->reopen_reason }}</p>
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
                        {{-- A meeting that has begun keeps its date. --}}
                        <x-forms.input type="datetime-local" wire:model="startsAt" :disabled="$started" />
                        @if($started)<p class="mt-1 text-small text-ink-2">@lang('board.locks.started-date')</p>@endif
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
                @unless($started)<p class="text-small text-ink-2">@lang('board.move-tells')</p>@endunless
                <x-button variant="primary" type="submit">@lang('board.save')</x-button>
            </form>

            @if(! $meeting->isCancelled() && ! $started)
                <form wire:submit.prevent="cancelMeeting" class="space-y-2 border-t border-line-soft pt-4">
                    <x-forms.input-label :value="__('board.cancel.reason')" :required="true" />
                    <x-forms.textarea wire:model="cancelReason" rows="2" />
                    @error('cancelReason') <p class="text-small font-semibold text-primary" role="alert">{{ $message }}</p> @enderror
                    <x-button variant="outline" type="submit">@lang('board.cancel.action')</x-button>
                </form>
            @endif
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

    {{-- 2. Who is coming. A member asks to miss it (and may take it back); the chair answers; the secretary records it from the meeting's time. --}}
    <section class="space-y-3 rounded-surface border border-line bg-card p-5" aria-labelledby="att-title">
        <h2 id="att-title" class="text-h4 font-bold text-ink">@lang('board.attendance.title')</h2>

        @if($mayAskAbsence && $meeting->isUpcoming() && ! $meeting->isPublished() && ! in_array($mine?->absence_request, ['pending', 'approved'], true))
            <form wire:submit.prevent="requestAbsence" class="space-y-2 rounded-surface border border-line-soft bg-soft p-4" data-testid="board-absence-form">
                <x-forms.input-label :value="__('board.absence.ask')" />
                <x-forms.textarea wire:model="absenceReason" rows="2" :placeholder="__('board.absence.reason')" />
                @error('absenceReason') <p class="text-small font-semibold text-primary" role="alert">{{ $message }}</p> @enderror
                <x-button variant="outline" type="submit">@lang('board.absence.send')</x-button>
            </form>
        @elseif($mine?->absence_request === 'pending')
            <div class="flex flex-wrap items-center gap-3 rounded-surface border border-line-soft bg-soft p-4" data-testid="board-absence-pending">
                <x-status tone="wait" icon="clock" :label="__('board.absence.waiting')" />
                <span class="text-small text-ink-2">{{ $mine->absence_reason }}</span>
                <x-button size="sm" variant="quiet" type="button" wire:click="withdrawAbsence">@lang('board.absence.withdraw')</x-button>
            </div>
        @elseif($mine?->absence_request === 'approved' && $meeting->isUpcoming() && ! $meeting->isPublished())
            <div class="flex flex-wrap items-center gap-3 rounded-surface border border-line-soft bg-soft p-4" data-testid="board-absence-approved">
                <x-status tone="done" icon="check" :label="__('board.absence.approved')" />
                <span class="text-small text-ink-2">{{ $mine->absence_reason }}</span>
                <x-button size="sm" variant="quiet" type="button" wire:click="withdrawAbsence" wire:confirm="{{ __('board.absence.take-back-confirm') }}">@lang('board.absence.take-back')</x-button>
            </div>
        @endif
        @error('absence') <p class="text-small font-semibold text-primary" role="alert">{{ $message }}</p> @enderror

        @if($canWrite && ! $started)
            <p class="text-small text-ink-2">@lang('board.locks.attendance-before-start')</p>
        @endif

        <ul class="divide-y divide-line-soft">
            @foreach($attendees as $a)
                @php [$t, $i] = $attTone[$a->status] ?? ['wait', 'clock']; @endphp
                <li class="flex flex-wrap items-center justify-between gap-3 py-3" wire:key="att-{{ $a->id }}">
                    <div>
                        <p class="font-semibold text-ink">{{ $a->employee?->name }}</p>
                        @if($a->absence_reason && ($canManage || $canWrite || $a->employee_id === $me->employee_id))
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
                        @if($canWrite && $started)
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

    {{-- Open rows from earlier minutes: the secretary closes them or carries them into this meeting's table. --}}
    @if($earlier->isNotEmpty())
        <section class="space-y-3 rounded-surface border-2 border-primary bg-card p-5" aria-labelledby="earlier-title" data-testid="board-earlier-follow-ups">
            <div>
                <h2 id="earlier-title" class="text-h4 font-bold text-ink">@lang('board.follow.earlier-title')</h2>
                <p class="text-small text-ink-2">@lang('board.follow.earlier-help')</p>
            </div>
            <ul class="divide-y divide-line-soft">
                @foreach($earlier as $f)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-3" wire:key="earlier-{{ $f->id }}">
                        <div class="min-w-0">
                            <p class="font-semibold text-ink">{{ $f->topic }}</p>
                            <p class="text-small text-ink-2">
                                @lang('board.meeting-number', ['number' => $f->meeting->number]) · {{ $f->party ?: '—' }}@if($f->required) · {{ $f->required }}@endif
                            </p>
                        </div>
                        <div class="flex gap-2">
                            <x-button size="sm" variant="outline" type="button" wire:click="markFollowUp({{ $f->id }}, true)">@lang('board.follow.mark-done')</x-button>
                            <x-button size="sm" variant="primary" type="button" wire:click="carryFollowUp({{ $f->id }})">@lang('board.follow.carry')</x-button>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

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
                        <h3 class="font-bold text-ink">{{ $loop->iteration }}. {{ $s->title }}</h3>
                        @if($canWrite)
                            <div class="flex gap-1">
                                <x-button size="sm" variant="quiet" type="button" wire:click="moveSection({{ $s->id }}, -1)" :disabled="$loop->first" :aria-label="__('board.minutes.move-up')">↑</x-button>
                                <x-button size="sm" variant="quiet" type="button" wire:click="moveSection({{ $s->id }}, 1)" :disabled="$loop->last" :aria-label="__('board.minutes.move-down')">↓</x-button>
                                <x-button size="sm" variant="quiet" type="button" wire:click="editSection({{ $s->id }})">@lang('board.edit')</x-button>
                                <x-button size="sm" variant="quiet" type="button" wire:click="removeSection({{ $s->id }})" wire:confirm="{{ __('board.minutes.remove-confirm') }}">@lang('board.remove')</x-button>
                            </div>
                        @endif
                    </div>
                    <div class="whitespace-pre-line text-ink">{{ $s->body }}</div>
                </article>
            @endforeach

            @if($canWrite)
                {{--
                    A section being edited is saved as it is typed. A new one is kept in this browser until it is added,
                    so a reload or a lost connection does not take the paragraph with it; leaving with it unsaved asks first.
                --}}
                <form wire:submit.prevent="saveSection" class="space-y-2 rounded-surface border border-line-soft bg-soft p-4" data-testid="board-section-form"
                      x-data="{
                          key: 'board-draft-{{ $meeting->id }}',
                          init() {
                              if (! $wire.sectionId && ! $wire.sectionBody) {
                                  const saved = JSON.parse(localStorage.getItem(this.key) || 'null');
                                  if (saved) { $wire.sectionTitle = saved.title || ''; $wire.sectionBody = saved.body || ''; }
                              }
                              $watch('$wire.sectionTitle', () => this.keep());
                              $watch('$wire.sectionBody', () => this.keep());
                              window.addEventListener('beforeunload', (e) => { if (! $wire.sectionId && ($wire.sectionBody || '').trim() !== '') { e.preventDefault(); e.returnValue = ''; } });
                          },
                          keep() {
                              if ($wire.sectionId) return;
                              const body = ($wire.sectionBody || '').trim(), title = ($wire.sectionTitle || '').trim();
                              body || title ? localStorage.setItem(this.key, JSON.stringify({ title, body })) : localStorage.removeItem(this.key);
                          },
                      }"
                      x-on:board-section-saved.window="localStorage.removeItem(key)">
                    @if($templates !== [])
                        <div class="flex flex-wrap gap-2" aria-label="{{ __('board.minutes.templates') }}">
                            @foreach($templates as $index => $template)
                                <button type="button" wire:click="useTemplate({{ $index }})"
                                        class="rounded-full border border-line px-3 py-1 text-small text-ink hover:bg-hover">{{ $template }}</button>
                            @endforeach
                        </div>
                    @endif
                    <x-forms.input-label :value="__('board.minutes.section-title')" :required="true" />
                    <x-forms.input wire:model="sectionTitle" :placeholder="__('board.minutes.section-title-example')" />
                    @error('sectionTitle') <p class="text-small font-semibold text-primary" role="alert">{{ $message }}</p> @enderror
                    <x-forms.input-label :value="__('board.minutes.section-body')" />
                    @if($sectionId)
                        <x-forms.textarea wire:model.live.debounce.1500ms="sectionBody" rows="8" />
                        <p class="text-small text-ink-2" wire:loading.remove wire:target="sectionBody">@lang('board.minutes.autosaved')</p>
                        <p class="text-small text-ink-2" wire:loading wire:target="sectionBody">@lang('board.minutes.saving')</p>
                    @else
                        <x-forms.textarea wire:model="sectionBody" rows="8" />
                    @endif
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
                                    <th class="p-2 text-start">@lang('board.follow.status-title')</th>
                                    @if($canWrite)<th class="p-2"></th>@endif
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($followUps as $f)
                                    @php [$t, $i] = $followTone[$f->status] ?? ['attn', 'clock']; @endphp
                                    <tr class="border-t border-line-soft" wire:key="fu-{{ $f->id }}">
                                        <td class="p-2 font-semibold text-ink">{{ $f->topic }}</td>
                                        <td class="p-2 text-ink">
                                            {{ $f->party }}
                                            @if($f->employee_id)<span class="text-small text-ink-2">· @lang('board.follow.linked')</span>@endif
                                        </td>
                                        <td class="p-2 text-ink">{{ $f->required }}</td>
                                        <td class="p-2"><x-status :tone="$t" :icon="$i" :label="__('board.follow.status.'.$f->status)" /></td>
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
                        <div>
                            <x-forms.input-label :value="__('board.minutes.follow-party')" />
                            {{-- Pick a person from the list (they are told when the minutes come out), or type a department or body. --}}
                            <input type="text" wire:model="followUpParty" list="board-people-{{ $meeting->id }}" autocomplete="off"
                                   class="w-full rounded-control border border-line bg-card px-3 py-2 text-ink" />
                            <datalist id="board-people-{{ $meeting->id }}">
                                @foreach($employeeNames as $name)<option value="{{ $name }}"></option>@endforeach
                            </datalist>
                            <p class="mt-1 text-small text-ink-2">@lang('board.follow.party-hint')</p>
                        </div>
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
                    @if($started)
                        @if($unmarkedWarning)
                            <p class="flex items-start gap-2 text-small font-semibold text-ink" role="status" data-testid="board-unmarked-warning">
                                <x-ic name="warning" class="mt-0.5 size-4 shrink-0" />
                                <span>{{ $unmarkedWarning }}</span>
                            </p>
                        @endif
                        <x-button variant="primary" type="button" wire:click="publish" wire:confirm="{{ $publishConfirm }}">
                            @lang('board.minutes.publish')
                        </x-button>
                        <p class="text-small text-ink-2">@lang('board.minutes.publish-help')</p>
                        @error('publish') <p class="text-small font-semibold text-primary" role="alert">{{ $message }}</p> @enderror
                    @else
                        <p class="text-small text-ink-2" data-testid="board-publish-not-yet">@lang('board.locks.publish-before-start')</p>
                    @endif
                </div>
            @endif
        </section>
    @endif
    @endif
</div>
