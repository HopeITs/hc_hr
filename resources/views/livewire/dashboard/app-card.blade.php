{{--
    A shortcut's data (AppCard): one number and a few short rows, simplified
    from its page. The tile partial draws the card around it (icon, name,
    open link); this is only what the page says right now. A page without a
    summary shows its counter, if it has one, and nothing else.
--}}
<div class="hc-appcard-data">
    @if ($loadFailed)
        <x-ui.load-error class="min-h-0 border-0" />
    @else
        @isset($data['num'])
            <p class="hc-acnum"><b class="num">{{ $data['num'] }}</b><span>{{ $data['unit'] ?? '' }}</span></p>
        @endisset

        @if ($data['rows'] !== [])
            <ul class="hc-aclist">
                @foreach ($data['rows'] as $row)
                    <li>
                        @if (! empty($row['href']))
                            <a href="{{ $row['href'] }}" wire:navigate><span dir="auto">{{ $row['t'] }}</span>@isset($row['s'])<small>{{ $row['s'] }}</small>@endisset</a>
                        @else
                            <span><span dir="auto">{{ $row['t'] }}</span>@isset($row['s'])<small class="num">{{ $row['s'] }}</small>@endisset</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @elseif (! empty($data['empty']))
            <p class="hc-acempty">{{ $data['empty'] }}</p>
        @elseif ($counter)
            <div class="hc-acnum">@livewire($counter, key('ac-cnt-'.$this->getId()))</div>
        @endif
    @endif
</div>
