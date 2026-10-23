@php
    $query = request()->get('search');
    $locale = app()->getLocale();
    $titleCol = $locale === 'ar' ? 'title_ar' : 'title_en';
@endphp

<div x-data="{ search: '' }"
    class="relative w-full h-full bg-white rounded-surface shadow-e1 overflow-hidden flex flex-col">

    <x-dashboard-cards.card-header :title="__('general.contact-the-team')">
        <x-slot:actions>
            <div class="relative w-36 shrink-0">
                <input x-model="search" type="text" placeholder="{{ __('general.search') }}"
                    class="h-6 w-full rounded-full border border-neutral-300 bg-white pe-3 ps-8 text-xs leading-none text-neutral-700
                           placeholder-secondary-light outline-none transition focus:border-neutral-400 focus:ring-0">
                <div class="pointer-events-none absolute start-2 top-1/2 -translate-y-1/2 text-neutral-400">
                    <x-icons.search class="size-2" />
                </div>
            </div>
        </x-slot:actions>
    </x-dashboard-cards.card-header>

    <!-- Team List -->
    <div
        data-csb-exclude
        class="relative px-6 pt-2 space-y-3 overflow-y-auto flex-grow
                scrollbar-thin scrollbar-thumb-[var(--color-neutral-300)] scrollbar-track-transparent
                hover:scrollbar-thumb-[var(--color-secondary-medium)] max-h-[440px]">

        @forelse($this->teamMembers as $member)
            @php
                // Work contact only — personal numbers are never shown to colleagues.
                $phone = preg_replace('/\D+/', '', $member->work_phone ?? '');
                $email = $member->work_email;

                // Telegram (work) is normally a phone number stored locally (09xxxxxxxx);
                // Telegram needs it international (+963 9xxxxxxxx). A username or a
                // t.me link still works.
                $telegram = trim((string) $member->telegram_work);
                $telegramDigits = preg_replace('/\D+/', '', $telegram);
                $telegramDigits = match (true) {
                    str_starts_with($telegramDigits, '00') => substr($telegramDigits, 2),
                    str_starts_with($telegramDigits, '0') => '963'.substr($telegramDigits, 1),
                    default => $telegramDigits,
                };
                $telegramHref = match (true) {
                    $telegram === '' => null,
                    str_starts_with($telegram, 'http://'), str_starts_with($telegram, 'https://') => $telegram,
                    ! preg_match('/[a-z]/i', $telegram) => $telegramDigits !== '' ? 'https://t.me/+'.$telegramDigits : null,
                    default => 'https://t.me/'.ltrim($telegram, '@'),
                };

                // Localized job title for each member
                $memberContract = $member->latestActiveContract();
                $memberTitle = $memberContract?->position?->$titleCol ?? ($memberContract?->position?->title_en ?? '—');
            @endphp

            <!-- Member Row -->
            <div x-show="
                    !search ||
                    {{ \Illuminate\Support\Js::from(mb_strtolower($member->displayName ?? $member->name)) }}.includes(search.toLowerCase()) ||
                    {{ \Illuminate\Support\Js::from(mb_strtolower($memberTitle)) }}.includes(search.toLowerCase())
                "
                x-transition
                class="flex items-center justify-between py-2 border-b border-[var(--color-neutral-200)] last:border-0">

                <!-- Avatar + Name + Title -->
                <div class="flex items-center flex-1 min-w-0 gap-3">

                    <!-- Avatar -->
                    <div
                        class="flex items-center justify-center size-[34px] flex-shrink-0 rounded-full overflow-hidden
                                bg-[var(--color-primary)] text-white font-semibold uppercase shadow-e1">
                        <img src="{{ $member->avatar_url }}"
                            alt="{{ $member->displayName ?? $member->name }}" class="object-fit w-full h-full">
                    </div>

                    <!-- Name + Position -->
                    <div class="min-w-0">
                        <p class="text-[12.1px] font-bold text-secondary-medium truncate">
                            {{ $member->displayName ?? $member->name }}
                        </p>

                        <p class="text-[9.6px] font-light truncate text-secondary-light">
                            {{ Str::limit($memberTitle, 40) }}
                        </p>
                    </div>
                </div>

                <!-- Actions -->
                <div class="flex items-center flex-shrink-0 gap-2 ms-4">

                    <a href="{{ route('employees.show', ['employee' => $member?->username]) }}" wire:navigate title="{{ __('general.view-profile') }}" aria-label="{{ __('general.view-profile') }}">
                        <x-icons.user class="transition text-secondary-light hover:text-primary" />
                    </a>

                    @if ($phone)
                        <a href="tel:{{ $phone }}" title="{{ __('dashboard.call') }}" aria-label="{{ __('dashboard.call') }}">
                            <x-icons.phone class="text-secondary-light ms-1 hover:text-primary transition" />
                        </a>
                    @endif

                    @if ($telegramHref)
                        <a href="{{ $telegramHref }}" target="_blank" rel="noopener"
                            class="transition text-secondary-light hover:text-primary" title="{{ __('dashboard.chat-on-telegram') }}" aria-label="{{ __('dashboard.chat-on-telegram') }}">
                            <x-icons.telegram class="transition text-secondary-light hover:text-primary" />
                        </a>
                    @endif

                    @if ($email)
                        <a href="mailto:{{ $email }}"
                            class="transition text-secondary-light hover:text-primary" title="{{ __('dashboard.send-email') }}" aria-label="{{ __('dashboard.send-email') }}">
                            <x-icons.envelope class="transition text-secondary-light ms-1 hover:text-primary" />
                        </a>
                    @endif

                </div>
            </div>

        @empty
            <div class="flex flex-col items-center justify-center h-full text-body font-light text-secondary-light">
                <span class="text-[10.5px]">@lang('general.no-available-data')</span>
            </div>
        @endforelse

    </div>

    <!-- Footer -->
    <div class="border-t border-[var(--color-neutral-200)] bg-white px-6 py-3 text-center mt-auto">
        <a href="{{ route('org.chart') }}" wire:navigate
            class="text-small font-semibold text-[var(--color-primary)] hover:underline">
            @lang('general.view-all') <span aria-hidden="true" class="inline-block rtl:rotate-180">→</span>
        </a>
    </div>
</div>
