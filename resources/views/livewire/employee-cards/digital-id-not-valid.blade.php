{{--
    The one "card is not valid" page (access-policy.md §5.3 #5, §9 A2D-Q6).

    Returned with 404 for every card that does not resolve: malformed input,
    unknown id, wrong or old codes, an older index, a disabled card, a
    departed employee, an old username link. It must stay byte-for-byte the
    same in all of those cases, so it takes no variables and does not echo
    the requested URL (no canonical / og:url, unlike partials.head).
--}}
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow, noarchive, noimageindex">
    <title>{{ __('digital_id.not_valid.title', [], 'en') }} · {{ __('digital_id.not_valid.title', [], 'ar') }}</title>
    <link rel="icon" href="{{ asset('favicons/favicon.ico') }}" sizes="any">
    <style>
        .id-invalid {
            min-height: 100vh;
            min-height: 100dvh;
            margin: 0;
            padding: 1.5rem 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #ffffff;
            color: #414042;
            font-family: 'SomarSans', 'Lato', system-ui, sans-serif;
        }

        .id-invalid main {
            width: min(100%, 24rem);
            text-align: center;
        }

        .id-invalid img {
            width: min(52%, 9rem);
            margin: 0 auto 1.5rem;
        }

        .id-invalid section {
            padding: 1.25rem 1rem;
            border-top: 4px solid #BE1F24;
            border-radius: 1rem;
            box-shadow: 0 0.5rem 1.5rem rgb(65 64 66 / 18%);
        }

        .id-invalid section + section {
            margin-top: 1rem;
        }

        .id-invalid h1 {
            margin: 0;
            color: #BE1F24;
            font-size: 1.5rem;
            font-weight: 800;
            line-height: 1.3;
        }

        .id-invalid p {
            margin: 0.5rem 0 0;
            font-size: 1rem;
            line-height: 1.6;
        }
    </style>
</head>
<body class="id-invalid">
    <main>
        <img src="{{ asset('assets/img/001_HC.svg') }}" alt="Christian Hope Center">

        <section lang="ar" dir="rtl">
            <h1>{{ __('digital_id.not_valid.heading', [], 'ar') }}</h1>
            <p>{{ __('digital_id.not_valid.body', [], 'ar') }}</p>
        </section>

        <section lang="en" dir="ltr">
            <h1>{{ __('digital_id.not_valid.heading', [], 'en') }}</h1>
            <p>{{ __('digital_id.not_valid.body', [], 'en') }}</p>
        </section>
    </main>
</body>
</html>
