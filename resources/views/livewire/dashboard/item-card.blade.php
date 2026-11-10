{{--
    One item of a page's card grid as a tile (ItemCard): a folder (its folders
    and files), one file, a program or a centre, with the same facts its page's
    card shows and a link to that page. No longer allowed: one quiet line.
    Empty folder: data-tile-empty, so the tile folds.
--}}
@php
    $locale = app()->getLocale();
    $size = fn ($bytes) => $bytes >= 1048576 ? number_format($bytes / 1048576, 1).' MB' : number_format($bytes / 1024, 1).' KB';
@endphp
<div class="hc-item flex h-full w-full flex-col overflow-hidden rounded-surface border border-line-soft bg-card shadow-sm" data-item="{{ $type }}">
    @if ($loadFailed)
        <x-ui.load-error class="h-full min-h-0 border-0" />
    @elseif ($model === null)
        <p class="hc-nt-empty pt-4">{{ __('dashboard.record.gone') }}</p>
    @else
        <header class="hc-nt-head">
            <h2 class="hc-nt-title">{{ $label }}</h2>
        </header>

        @switch($type)
            @case('folder')
                @if ($data['folders']->isEmpty() && $data['files']->isEmpty())
                    <p class="hc-nt-empty" data-tile-empty>{{ __('general.folder_empty') }}</p>
                @else
                    <ul class="hc-list">
                        @foreach ($data['folders'] as $child)
                            <li>
                                <a href="{{ $child['href'] }}" wire:navigate class="hc-list-row hover:bg-hover">
                                    <x-icons.folder class="size-5 shrink-0 text-ink-3" />
                                    <b class="min-w-0 flex-1 truncate">{{ $child['name'] }}</b>
                                </a>
                            </li>
                        @endforeach
                        @foreach ($data['files'] as $file)
                            <li>
                                <a href="{{ route('folder-files.preview', ['media' => $file->id]) }}" target="_blank" rel="noopener" class="hc-list-row hover:bg-hover">
                                    <x-icons.document-text class="size-5 shrink-0 text-ink-3" />
                                    <span class="min-w-0 flex-1">
                                        <b class="block truncate">{{ $file->name }}</b>
                                        <span class="block text-small text-ink-2 num">{{ strtoupper((string) $file->extension) }} · {{ $size($file->size) }}</span>
                                    </span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                    @if ($data['fileCount'] > $data['files']->count())
                        <p class="px-4 pb-3 text-small text-ink-2">{{ trans_choice('dashboard.item.more-files', $data['fileCount'] - $data['files']->count()) }}</p>
                    @endif
                @endif
                @break

            @case('file')
                <div class="hc-me-body items-center text-center">
                    @if ($data['image'])
                        <img src="{{ route('folder-files.preview', ['media' => $model->id]) }}" alt="" class="max-h-28 rounded-inset object-contain ring-1 ring-line-soft" loading="lazy">
                    @else
                        <x-icons.document-text class="size-12 text-ink-3" />
                    @endif
                    <p class="text-small text-ink-2 num">{{ strtoupper((string) $model->extension) }} · {{ $size($model->size) }}</p>
                    <div class="flex flex-wrap justify-center gap-2">
                        <x-button variant="outline" size="sm" icon="eye" :href="route('folder-files.preview', ['media' => $model->id])" target="_blank">{{ __('dashboard.item.preview') }}</x-button>
                        @if ($data['canDownload'])
                            <x-button variant="quiet" size="sm" :href="route('folder-files.download', ['media' => $model->id])">{{ __('dashboard.item.download') }}</x-button>
                        @endif
                    </div>
                </div>
                @break

            @case('program')
                <div class="hc-me-body">
                    <dl class="hc-me-kv">
                        <dt>{{ __('general.program-manager') }}</dt>
                        <dd @class(['font-semibold', 'text-ink-3' => ! $data['manager']])>{{ $data['manager'] ?? __('general.not-specified') }}</dd>
                        <dt>{{ trans_choice('general.city', 2) }}</dt>
                        <dd class="num font-semibold">{{ $data['cities'] }}</dd>
                        <dt>{{ trans_choice('general.employee', 2) }}</dt>
                        <dd class="num font-semibold">{{ $data['employees'] }}</dd>
                        <dt>{{ __('general.start-date') }}</dt>
                        <dd class="num">{{ $data['start']?->locale($locale)->translatedFormat('M Y') ?? '—' }}</dd>
                        <dt>{{ __('general.end-date') }}</dt>
                        <dd class="num">{{ $data['end'] ? $data['end']->locale($locale)->translatedFormat('M Y') : __('general.active') }}</dd>
                    </dl>
                </div>
                @break

            @case('interview')
                <div class="hc-me-body">
                    <p class="font-bold text-ink">
                        <bdi class="num">{{ $data['start']?->locale($locale)->translatedFormat('D j M') }} · {{ $data['start']?->format('H:i') }}@if ($data['end'])–{{ $data['end']->format('H:i') }}@endif</bdi>
                    </p>
                    <p class="text-small text-ink-2">
                        @if ($data['position']){{ $data['position'] }} · @endif{{ __('recruitment-applicant.interviews.types.'.$model->interview_type) }}
                    </p>
                    <x-tag variant="soft">{{ __('recruitment-applicant.interviews.statuses.'.$model->status) }}</x-tag>
                    <dl class="hc-me-kv">
                        <dt>{{ __('dashboard.item.where') }}</dt>
                        <dd>
                            @if ($model->location_type === \App\Enums\RecruitmentInterviewLocationType::Online)
                                {{ __('recruitment-applicant.interviews.location_types.online') }}@if ($model->location_value) · {{ $model->location_value }}@endif
                            @else
                                {{ $model->location_value ?: __('recruitment-applicant.interviews.location_types.in_person') }}
                            @endif
                        </dd>
                        @if ($data['panel']->isNotEmpty())
                            <dt>{{ __('recruitment-applicant.interviews.board.panel') }}</dt>
                            <dd>{{ $data['panel']->implode($locale === 'ar' ? '، ' : ', ') }}</dd>
                        @endif
                    </dl>
                </div>
                @break

            @case('feedback')
                <div class="hc-me-body">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-small text-ink-2">
                        <time datetime="{{ $model->created_at?->toIso8601String() }}"><bdi dir="ltr" class="num">{{ $model->created_at?->format('Y-m-d H:i') }}</bdi></time>
                        @if ($model->category)
                            <x-tag variant="soft">@lang('feedback.categories.'.$model->category)</x-tag>
                        @endif
                        <span>{{ $model->user?->profile?->name ?? __('feedback.inbox.deleted-user') }}</span>
                    </div>
                    <p dir="auto" class="whitespace-pre-line break-words text-start text-body text-ink">{{ $model->message }}</p>
                    <p class="text-small text-ink-2">@lang('feedback.disclosure.page'): <bdi dir="ltr" class="font-mono">{{ $model->route_name ?? '—' }}</bdi></p>
                </div>
                @break

            @case('center')
                <div class="hc-me-body">
                    <dl class="hc-me-kv">
                        <dt>{{ trans_choice('general.city', 1) }}</dt>
                        <dd class="font-semibold">{{ $data['city'] ?? '—' }}</dd>
                        <dt>@lang('general.manager')</dt>
                        <dd class="font-semibold">{{ $data['manager'] ?? '—' }}</dd>
                        <dt>{{ trans_choice('general.employee', 2) }}</dt>
                        <dd class="num font-semibold">{{ $data['employees'] }}</dd>
                    </dl>
                </div>
                @break
        @endswitch
    @endif
</div>
