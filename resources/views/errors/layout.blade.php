<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title')</title>
    @yield('page-background')

    <style>
        html, body {
            background-color: #ffffff;
            color: #58595b;
            font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont,
            "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            margin: 0;
            height: 100%;
        }

        .title {
            font-size: 21px;
            font-weight: 700;
            margin-top: 20px;
            color: #58595b;
        }

        .message {
            font-size: 18px;
            color: #6d6e71;
            margin: 5px 0 25px 0;

            width: 100%;
            max-width: 900px;
            margin-left: auto;
            margin-right: auto;

            white-space: normal;
        }


        .btn-home {
            display: inline-block;
            padding: 10px 20px;
            color: #be1f24;
            border-radius: 8px;
            font-weight:400;
            font-size: 11px;
            text-decoration: underline;
        }

        .content-box {
            position: absolute;
            left: 50%;
            bottom: 10px;
            transform: translateX(-50%);
            width: 100%;
            max-width: 700px;

            display: flex;
            flex-direction: column;
            text-align: center;
        }



    </style>
</head>

<body>

@yield('banner')

<div class="content-box">
    @yield('content')
</div>

</body>
</html>
