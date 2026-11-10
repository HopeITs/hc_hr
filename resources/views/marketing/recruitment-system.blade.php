{{--
    Public presentation of the recruitment module (RecruitmentSystemController).
    Every value is a brand token from app.css, so dark mode and RTL need nothing
    extra here. The product pictures are drawn in markup, not screenshots: they
    show the module's real steps without any real applicant in them.
--}}
@php
    $isAr = app()->getLocale() === 'ar';
    $otherLocale = $isAr ? 'en' : 'ar';
    $t = fn (string $key, array $replace = []) => __('recruitment-system.'.$key, $replace);
    $features = [
        ['id' => 'efficiency', 'icon' => 'search'],
        ['id' => 'experience', 'icon' => 'users'],
        ['id' => 'control', 'icon' => 'shield'],
    ];
    $modules = [
        ['key' => 'employees', 'icon' => 'user'],
        ['key' => 'contracts', 'icon' => 'file'],
        ['key' => 'leaves', 'icon' => 'calendar'],
        ['key' => 'calendar', 'icon' => 'layout'],
        ['key' => 'news', 'icon' => 'inbox'],
        ['key' => 'journeys', 'icon' => 'arrow'],
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ $isAr ? 'rtl' : 'ltr' }}">
<head>
    @include('partials.head', ['title' => $t('title'), 'description' => $t('meta_description')])
</head>
<body class="min-h-screen bg-card font-sans text-ink antialiased">

    {{-- Top bar --}}
    <header class="sticky top-0 z-30 border-b border-line-soft bg-base/95 backdrop-blur">
        <div class="mx-auto flex h-16 max-w-6xl items-center justify-between gap-4 px-4">
            <a href="{{ route('recruitment-system') }}" class="shrink-0">
                <x-app-logo class="h-8 w-auto" />
            </a>
            <nav class="flex items-center gap-1 sm:gap-3">
                <a href="#features" class="hidden rounded px-3 py-2 text-small text-ink-2 hover:bg-hover hover:text-ink sm:inline-block">{{ $t('nav_features') }}</a>
                <a href="#platform" class="hidden rounded px-3 py-2 text-small text-ink-2 hover:bg-hover hover:text-ink sm:inline-block">{{ $t('nav_platform') }}</a>
                <a href="{{ LaravelLocalization::getLocalizedURL($otherLocale, null, [], true) }}" hreflang="{{ $otherLocale }}" class="rounded px-3 py-2 text-small text-ink-2 hover:bg-hover hover:text-ink">{{ $t('switch_language') }}</a>
                <x-theme-toggle />
                <x-button size="sm" :href="route('login')" :wire:navigate="false">{{ $t('sign_in') }}</x-button>
            </nav>
        </div>
    </header>

    <main>
        {{-- Hero --}}
        <section class="relative overflow-hidden">
            <div class="mx-auto grid max-w-6xl items-center gap-12 px-4 py-16 lg:grid-cols-2 lg:py-24">
                <div>
                    <h1 class="text-balance text-[clamp(32px,5vw,52px)] font-extrabold leading-[1.15] text-ink">{{ $t('hero_title') }}</h1>
                    <p class="mt-5 max-w-xl text-lg leading-relaxed text-ink-2">{{ $t('hero_text') }}</p>
                    <div class="mt-8 flex flex-wrap gap-3">
                        <x-button size="lg" :href="route('login')" :wire:navigate="false">{{ $t('hero_primary') }}</x-button>
                        <x-button size="lg" variant="outline" href="#features" :wire:navigate="false">{{ $t('hero_secondary') }}</x-button>
                    </div>
                </div>

                {{-- Picture: one vacancy and its top applicants --}}
                <div class="relative" aria-hidden="true">
                    <div class="absolute -inset-6 -z-10 rounded-[2rem] bg-soft"></div>
                    <div class="rounded-2xl border border-line-soft bg-card p-5 shadow-sm">
                        <div class="flex items-center justify-between gap-3 border-b border-line-soft pb-4">
                            <div>
                                <p class="font-bold text-ink">{{ $t('mock_job') }}</p>
                                <p class="text-caption text-ink-3">{{ $t('mock_applicants', ['count' => $isAr ? ar_digits(38) : 38]) }}</p>
                            </div>
                            <span class="grid size-10 place-items-center rounded-full bg-soft text-ink-2"><x-ic name="users" /></span>
                        </div>
                        <ul class="mt-4 space-y-3">
                            @foreach ([['init' => $isAr ? 'ر' : 'R', 'score' => 92, 'tag' => 'act', 'label' => 'mock_waiting'], ['init' => $isAr ? 'م' : 'M', 'score' => 84, 'tag' => 'soft', 'label' => 'mock_interview'], ['init' => $isAr ? 'س' : 'S', 'score' => 77, 'tag' => 'soft', 'label' => 'mock_offer']] as $row)
                                <li class="flex items-center gap-3 rounded-xl border border-line-soft p-3">
                                    <span class="grid size-10 shrink-0 place-items-center rounded-full bg-ink font-bold text-on-ink">{{ $row['init'] }}</span>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center justify-between text-caption text-ink-2">
                                            <span>{{ $t('mock_match') }}</span>
                                            <span class="num font-bold text-ink">{{ $isAr ? ar_digits($row['score']) : $row['score'] }}%</span>
                                        </div>
                                        <div class="mt-1.5 h-2 rounded-full bg-surface">
                                            <div class="h-2 rounded-full bg-ink" style="width: {{ $row['score'] }}%"></div>
                                        </div>
                                    </div>
                                    <x-tag :variant="$row['tag']" :label="$t($row['label'])" class="hidden shrink-0 sm:inline-flex" />
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>
        </section>

        {{-- Totals from the system, in place of client logos --}}
        <section class="bg-ink text-on-ink">
            <div class="mx-auto max-w-6xl px-4 py-12">
                <h2 class="text-center text-lg font-medium opacity-90">{{ $t('stats_title') }}</h2>
                <dl class="mt-8 grid grid-cols-2 gap-8 text-center md:grid-cols-4">
                    @foreach (['cities', 'centers', 'programs', 'employees'] as $key)
                        <div>
                            <dt class="sr-only">{{ $t('stats_'.$key) }}</dt>
                            <dd>
                                <span class="num block text-4xl font-extrabold">{{ $isAr ? ar_digits(number_format($stats[$key])) : number_format($stats[$key]) }}</span>
                                <span class="mt-1 block text-small opacity-80" aria-hidden="true">{{ $t('stats_'.$key) }}</span>
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        </section>

        {{-- Three pillars --}}
        <section id="features" class="scroll-mt-20">
            <div class="mx-auto max-w-6xl px-4 py-20">
                <h2 class="text-balance text-center text-[clamp(26px,4vw,40px)] font-extrabold text-ink">{{ $t('pillars_title') }}</h2>
                <div class="mt-12 grid gap-5 md:grid-cols-3">
                    @foreach ($features as $feature)
                        <a href="#{{ $feature['id'] }}" class="group rounded-2xl border border-line-soft bg-soft p-6 transition hover:border-line hover:bg-card">
                            <span class="grid size-12 place-items-center rounded-xl bg-card text-primary [&_svg]:size-6"><x-ic :name="$feature['icon']" /></span>
                            <h3 class="mt-5 text-xl font-bold text-ink">{{ $t('pillar_'.$feature['id'].'_title') }}</h3>
                            <p class="mt-2 leading-relaxed text-ink-2">{{ $t('pillar_'.$feature['id'].'_text') }}</p>
                        </a>
                    @endforeach
                </div>
            </div>
        </section>

        <section class="border-y border-line-soft bg-soft">
            <h2 class="mx-auto max-w-6xl text-balance px-4 py-12 text-center text-[clamp(22px,3vw,32px)] font-extrabold text-ink">{{ $t('band_title') }}</h2>
        </section>

        {{-- Feature rows, picture alternating sides --}}
        @foreach ($features as $i => $feature)
            <section id="{{ $feature['id'] }}" class="scroll-mt-20">
                <div class="mx-auto grid max-w-6xl items-center gap-12 px-4 py-20 lg:grid-cols-2">
                    <div @class(['lg:order-2' => $i % 2 === 1])>
                        <p class="text-caption font-bold uppercase tracking-wider text-primary">{{ $t($feature['id'].'_eyebrow') }}</p>
                        <h2 class="mt-3 text-balance text-[clamp(26px,3.5vw,38px)] font-extrabold leading-tight text-ink">{{ $t($feature['id'].'_title') }}</h2>
                        <p class="mt-4 text-lg leading-relaxed text-ink-2">{{ $t($feature['id'].'_text') }}</p>
                        <ul class="mt-6 space-y-3">
                            @foreach ([1, 2, 3] as $n)
                                <li class="flex items-start gap-3">
                                    <span class="mt-0.5 grid size-6 shrink-0 place-items-center rounded-full bg-ink text-on-ink [&_svg]:size-3.5"><x-ic name="check" /></span>
                                    <span class="text-ink">{{ $t($feature['id'].'_point_'.$n) }}</span>
                                </li>
                            @endforeach
                        </ul>
                        @if ($i === 0)
                            <div class="mt-8 flex flex-wrap gap-3">
                                <x-button :href="route('login')" :wire:navigate="false">{{ $t('hero_primary') }}</x-button>
                            </div>
                        @endif
                    </div>

                    <div class="relative" aria-hidden="true">
                        <div class="absolute -inset-5 -z-10 rounded-[2rem] bg-soft"></div>
                        @if ($feature['id'] === 'efficiency')
                            {{-- CV analysis --}}
                            <div class="rounded-2xl border border-line-soft bg-card p-6 shadow-sm">
                                <div class="flex items-center gap-3">
                                    <span class="grid size-10 place-items-center rounded-lg bg-soft text-ink-2"><x-ic name="file" /></span>
                                    <p class="font-bold text-ink">{{ $t('mock_cv') }}</p>
                                    <span class="ms-auto num rounded-full bg-ink px-3 py-1 text-caption font-bold text-on-ink">{{ $isAr ? ar_digits(92) : 92 }}%</span>
                                </div>
                                <div class="mt-5 space-y-3">
                                    @foreach (['experience', 'education', 'languages'] as $line)
                                        <div class="flex items-center gap-3 rounded-lg bg-soft px-4 py-3">
                                            <span class="text-primary [&_svg]:size-4"><x-ic name="star" /></span>
                                            <span class="text-small text-ink">{{ $t('mock_cv_'.$line) }}</span>
                                        </div>
                                    @endforeach
                                </div>
                                <div class="mt-5 space-y-2">
                                    <div class="h-2 w-full rounded-full bg-surface"></div>
                                    <div class="h-2 w-4/5 rounded-full bg-surface"></div>
                                    <div class="h-2 w-3/5 rounded-full bg-surface"></div>
                                </div>
                            </div>
                        @elseif ($feature['id'] === 'experience')
                            {{-- Application form on a phone --}}
                            <div class="mx-auto max-w-xs rounded-[2rem] border-8 border-ink bg-card p-5 shadow-sm">
                                <x-app-logo class="mx-auto h-6 w-auto" />
                                <p class="mt-4 text-center font-bold text-ink">{{ $t('mock_form') }}</p>
                                <p class="text-center text-caption text-ink-3">{{ $t('mock_job') }}</p>
                                <div class="mt-5 space-y-3">
                                    @foreach (['name', 'phone'] as $field)
                                        <div>
                                            <p class="text-caption text-ink-2">{{ $t('mock_form_'.$field) }}</p>
                                            <div class="mt-1 h-10 rounded-lg border border-line bg-field"></div>
                                        </div>
                                    @endforeach
                                    <div class="flex items-center justify-center gap-2 rounded-lg border border-dashed border-line py-4 text-caption text-ink-2">
                                        <x-ic name="file" /> {{ $t('mock_form_cv') }}
                                    </div>
                                    <div class="rounded-lg bg-primary py-2.5 text-center text-small font-bold text-on-brand">{{ $t('mock_form_submit') }}</div>
                                </div>
                            </div>
                        @else
                            {{-- Vacancy pipeline --}}
                            <div class="rounded-2xl border border-line-soft bg-card p-6 shadow-sm">
                                <p class="font-bold text-ink">{{ $t('mock_pipeline') }}</p>
                                <p class="text-caption text-ink-3">{{ $t('mock_job') }}</p>
                                <div class="mt-6 space-y-4">
                                    @foreach ([['applied', 38, 100], ['screened', 14, 37], ['interview', 6, 16], ['offer', 1, 3]] as [$stage, $count, $width])
                                        <div>
                                            <div class="flex items-center justify-between text-small">
                                                <span class="text-ink-2">{{ $t('mock_stage_'.$stage) }}</span>
                                                <span class="num font-bold text-ink">{{ $isAr ? ar_digits($count) : $count }}</span>
                                            </div>
                                            <div class="mt-1.5 h-3 rounded-full bg-surface">
                                                <div @class(['h-3 rounded-full', 'bg-primary' => $stage === 'offer', 'bg-ink' => $stage !== 'offer']) style="width: {{ max($width, 4) }}%"></div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                                <div class="mt-6 flex items-center gap-2 border-t border-line-soft pt-4 text-small text-ink-2">
                                    <x-ic name="calendar" /> {{ $t('mock_interview') }}
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            </section>
        @endforeach

        {{-- The rest of HopeHR --}}
        <section id="platform" class="scroll-mt-20 bg-soft">
            <div class="mx-auto max-w-6xl px-4 py-20">
                <p class="text-center text-caption font-bold uppercase tracking-wider text-primary">{{ $t('explore_eyebrow') }}</p>
                <h2 class="mt-3 text-balance text-center text-[clamp(26px,4vw,40px)] font-extrabold text-ink">{{ $t('explore_title') }}</h2>
                <p class="mx-auto mt-4 max-w-2xl text-center text-lg text-ink-2">{{ $t('explore_text') }}</p>
                <div class="mt-12 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($modules as $module)
                        <div class="flex gap-4 rounded-2xl border border-line-soft bg-card p-5">
                            <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-soft text-ink"><x-ic :name="$module['icon']" /></span>
                            <div>
                                <h3 class="font-bold text-ink">{{ $t('module_'.$module['key']) }}</h3>
                                <p class="mt-1 text-small leading-relaxed text-ink-2">{{ $t('module_'.$module['key'].'_text') }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- Closing call to action --}}
        <section>
            <div class="mx-auto max-w-6xl px-4 py-20">
                <div class="flex flex-col items-center gap-6 rounded-3xl bg-ink px-6 py-14 text-center text-on-ink">
                    <h2 class="text-balance text-[clamp(26px,4vw,40px)] font-extrabold">{{ $t('cta_title') }}</h2>
                    <p class="max-w-xl text-lg opacity-85">{{ $t('cta_text') }}</p>
                    <x-button size="lg" :href="route('login')" :wire:navigate="false">{{ $t('hero_primary') }}</x-button>
                </div>
            </div>
        </section>
    </main>

    <footer class="border-t border-line-soft">
        <div class="mx-auto flex max-w-6xl flex-col items-center justify-between gap-3 px-4 py-8 text-small text-ink-3 sm:flex-row">
            <x-app-logo class="h-6 w-auto" />
            <p>{{ $t('footer', ['year' => $isAr ? ar_digits(now()->year) : now()->year]) }}</p>
        </div>
    </footer>
</body>
</html>
