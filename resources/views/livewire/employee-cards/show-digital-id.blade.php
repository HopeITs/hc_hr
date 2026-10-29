@php
    $hasPhone = filled($employee->work_phone) && filled($phoneHref);
    $hasTelegram = filled($employee->telegram_work) && filled($telegramHref);
    $hasLinkedin = filled($employee->linkedin) && filled($linkedinHref);
    $hasContactLink = $hasPhone || $hasTelegram || $hasLinkedin;
@endphp

<x-slot:title>
    {{ $employeeName }}
</x-slot:title>

<div class="digital-id-page">
    <style>
        .digital-id-page {
            --id-primary: var(--color-primary, #BE1F24);
            --id-black: var(--color-black, #414042);
            --id-icon: #414042;
            --id-card-width: min(88vw, 21rem, 43dvh);
            min-height: 100vh;
            min-height: 100dvh;
            padding: clamp(0.5rem, 1.5dvh, 1rem) 1rem;
            color: var(--id-black);
            background: #ffffff;
            font-family: 'SomarSans', 'Lato', sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .digital-id-wrap {
            width: var(--id-card-width);
            margin: 0 auto;
            text-align: center;
        }

        .digital-id-logo {
            width: min(52%, 9rem);
            margin: 0 auto clamp(0.7rem, 1.8dvh, 1.1rem);
        }

        .digital-id-card {
            position: relative;
            display: flex;
            flex-direction: column;
            aspect-ratio: 0.645;
            height: auto;
            min-height: 0;
            overflow: hidden;
            border-radius: calc(var(--id-card-width) * 0.095);
            background: #ffffff;
            box-shadow: 0 calc(var(--id-card-width) * 0.04) calc(var(--id-card-width) * 0.08) rgb(65 64 66 / 22%);
            container-type: inline-size;
        }

        .digital-id-photo-panel {
            position: relative;
            flex: 0 0 44%;
            overflow: hidden;
            background: #ffffff;
        }

        .digital-id-photo-bar {
            position: absolute;
            inset-inline: 0;
            bottom: 0;
            height: 13.5%;
            background: var(--id-primary);
        }

        .digital-id-avatar {
            position: absolute;
            bottom: 0;
            left: 50%;
            z-index: 1;
            display: block;
            width: 92%;
            max-height: 94%;
            transform: translateX(-50%);
            object-fit: contain;
            object-position: bottom center;
        }

        .digital-id-info {
            display: flex;
            flex: none;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            min-height: 0;
            padding: 4.2cqw 6cqw 12cqw;
        }

        .digital-id-name {
            max-width: 100%;
            color: var(--id-primary);
            font-size: clamp(1.55rem, 14.2cqw, 2.8rem);
            font-weight: 900;
            line-height: 0.88;
            letter-spacing: 0;
            text-transform: uppercase;
            overflow-wrap: anywhere;
        }

        .digital-id-position {
            max-width: 100%;
            margin-top: 2.8cqw;
            color: var(--id-black);
            font-size: clamp(0.78rem, 6.8cqw, 1.36rem);
            font-weight: 700;
            line-height: 1.02;
            overflow-wrap: anywhere;
        }

        .digital-id-center {
            max-width: 100%;
            margin-top: 1cqw;
            color: var(--id-black);
            font-size: clamp(0.58rem, 4.9cqw, 0.98rem);
            font-weight: 700;
            line-height: 1.05;
            text-transform: uppercase;
            overflow-wrap: anywhere;
        }

        .digital-id-city {
            max-width: 100%;
            margin-top: 4.4cqw;
            color: var(--id-primary);
            font-size: clamp(0.58rem, 4.9cqw, 0.98rem);
            font-weight: 700;
            line-height: 1.05;
            text-transform: uppercase;
            overflow-wrap: anywhere;
        }

        .digital-id-divider {
            width: 70%;
            height: max(1px, 0.45cqw);
            margin-top: 4.4cqw;
            background: #bcbec0;
        }

        .digital-id-icons {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 2.4cqw;
            margin-top: 5.2cqw;
        }

        .digital-id-icon-link {
            display: inline-flex;
            width: clamp(1.15rem, 8.5cqw, 1.72rem);
            height: clamp(1.15rem, 8.5cqw, 1.72rem);
            align-items: center;
            justify-content: center;
            border-radius: 1.4cqw;
            color: #ffffff;
            background: var(--id-icon);
            transition: transform 160ms ease, background-color 160ms ease;
        }

        .digital-id-icon-link:hover,
        .digital-id-icon-link:focus-visible {
            background: var(--id-primary);
            transform: translateY(-1px);
        }

        .digital-id-icon {
            width: 62%;
            height: 62%;
            fill: currentColor;
        }

        .digital-id-emergency {
            margin-top: clamp(0.75rem, 2.1dvh, 1.25rem);
            color: var(--id-black);
            font-size: clamp(0.72rem, 3.8vw, 1rem);
            font-weight: 500;
            line-height: 1.2;
        }

        .digital-id-emergency a,
        .digital-id-emergency strong {
            color: var(--id-black);
            font-size: clamp(0.8rem, 4.2vw, 1.08rem);
            font-weight: 700;
            text-decoration: none;
        }

        @supports not (height: 100dvh) {
            .digital-id-page {
                --id-card-width: min(88vw, 21rem, 43vh);
            }
        }

        @media (max-width: 399px) {
            .digital-id-page {
                --id-card-width: min(84vw, 20rem, 41dvh);
            }

            .digital-id-logo {
                margin-bottom: clamp(0.55rem, 1.4dvh, 0.9rem);
            }

            .digital-id-photo-panel {
                flex-basis: 41%;
            }

            .digital-id-info {
                padding: 3.6cqw 6cqw 14cqw;
            }

            .digital-id-name {
                font-size: clamp(1.35rem, 13.2cqw, 2.45rem);
            }

            .digital-id-position {
                margin-top: 2.4cqw;
                font-size: clamp(0.72rem, 6.2cqw, 1.16rem);
            }

            .digital-id-center,
            .digital-id-city {
                font-size: clamp(0.52rem, 4.5cqw, 0.86rem);
            }

            .digital-id-city,
            .digital-id-divider {
                margin-top: 3.5cqw;
            }

            .digital-id-icons {
                margin-top: 5.4cqw;
            }

            .digital-id-icon-link {
                width: clamp(1rem, 7.7cqw, 1.45rem);
                height: clamp(1rem, 7.7cqw, 1.45rem);
            }
        }
    </style>

    <main class="digital-id-wrap" aria-label="{{ __('digital_id.card.label') }}">
        <img class="digital-id-logo" src="{{ asset('assets/img/001_HC.svg') }}" alt="Christian Hope Center">

        <section class="digital-id-card">
            <div class="digital-id-photo-panel">
                <div class="digital-id-photo-bar" aria-hidden="true"></div>
                <img class="digital-id-avatar" src="{{ $avatarSrc }}" alt="{{ $employeeName }}">
            </div>

            <div class="digital-id-info">
                <h1 class="digital-id-name">
                    <span>{{ $firstName }}</span><br>
                    <span>{{ $lastName }}</span>
                </h1>

                <div class="digital-id-position">{{ $positionTitle }}</div>
                <div class="digital-id-center">{{ $center?->name }}</div>
                <div class="digital-id-city">{{ $cityName }}</div>

                <div class="digital-id-divider" aria-hidden="true"></div>

                @if ($hasContactLink)
                    <div class="digital-id-icons" role="group" aria-label="{{ __('digital_id.card.contact_links') }}">
                        @if ($hasPhone)
                            <a class="digital-id-icon-link" href="{{ $phoneHref }}" aria-label="{{ __('digital_id.card.call', ['name' => $employeeName]) }}">
                                <svg class="digital-id-icon" viewBox="0 0 24 24" aria-hidden="true">
                                    <path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1C10.61 21 3 13.39 3 4c0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.24.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/>
                                </svg>
                            </a>
                        @endif

                        @if ($hasTelegram)
                            <a class="digital-id-icon-link" href="{{ $telegramHref }}" target="_blank" rel="noopener noreferrer" aria-label="{{ __('digital_id.card.telegram', ['name' => $employeeName]) }}">
                                <svg class="digital-id-icon" viewBox="0 0 24 24" aria-hidden="true">
                                    <path d="M21.94 4.16a1.27 1.27 0 0 0-1.42-.2L3.3 11.07c-.48.2-.8.62-.8 1.08 0 .55.42.96 1.08 1.08l4.35.78 1.66 5.22c.18.55.56.86 1.03.86.35 0 .64-.17.9-.48l2.4-2.89 4.54 3.36c.27.2.56.31.85.31.67 0 1.13-.47 1.28-1.28l2.35-13.57c.1-.58-.1-1.09-.5-1.38zM18.72 7.4l-7.46 6.68-.3 3.09-1.1-3.48 8.86-6.29z"/>
                                </svg>
                            </a>
                        @endif

                        @if ($hasLinkedin)
                            <a class="digital-id-icon-link" href="{{ $linkedinHref }}" target="_blank" rel="noopener noreferrer" aria-label="{{ __('digital_id.card.linkedin', ['name' => $employeeName]) }}">
                                <svg class="digital-id-icon" viewBox="0 0 24 24" aria-hidden="true">
                                    <path d="M19 3A2 2 0 0 1 21 5v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h14zM8.34 18.34V9.75H5.67v8.59h2.67zM7 8.58c.85 0 1.54-.69 1.54-1.54S7.85 5.5 7 5.5s-1.54.69-1.54 1.54S6.15 8.58 7 8.58zm11.34 9.76v-4.71c0-2.32-1.24-3.39-2.9-3.39-1.33 0-1.93.73-2.26 1.25V9.75h-2.66v8.59h2.66v-4.79c0-1.26.24-2.48 1.8-2.48 1.53 0 1.55 1.43 1.55 2.56v4.71h2.81z"/>
                                </svg>
                            </a>
                        @endif
                    </div>
                @endif
            </div>
        </section>

        @if (filled($emergencyPhone) && filled($emergencyPhoneHref))
            <p class="digital-id-emergency">
                {{ __('digital_id.card.emergency') }}
                <a href="{{ $emergencyPhoneHref }}" dir="ltr">{{ $emergencyPhone }}</a>
            </p>
        @endif
    </main>
</div>
