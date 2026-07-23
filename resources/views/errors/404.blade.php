<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <title>Page not found | {{ config('app.name', 'ERNIE') }}</title>
        <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
        <style>
            :root {
                color-scheme: light dark;
                font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                background: #f4f7f9;
                color: #172033;
            }

            * {
                box-sizing: border-box;
            }

            body {
                min-height: 100vh;
                margin: 0;
                display: grid;
                place-items: center;
                padding: 2rem;
                background:
                    radial-gradient(circle at top left, rgb(0 128 180 / 14%), transparent 32rem),
                    #f4f7f9;
            }

            main {
                width: min(100%, 42rem);
                padding: clamp(2rem, 6vw, 4rem);
                border: 1px solid #d9e2e8;
                border-radius: 1.5rem;
                background: rgb(255 255 255 / 94%);
                box-shadow: 0 1.5rem 4rem rgb(23 32 51 / 12%);
                text-align: center;
            }

            .logo {
                width: min(15rem, 72%);
                height: auto;
                margin-bottom: 2rem;
            }

            .status {
                display: inline-flex;
                align-items: center;
                min-height: 2rem;
                padding: 0.3rem 0.8rem;
                border-radius: 999px;
                background: #e6f4f9;
                color: #006f99;
                font-weight: 700;
                letter-spacing: 0.08em;
            }

            h1 {
                margin: 1.25rem 0 0.75rem;
                font-size: clamp(2rem, 7vw, 3.25rem);
                line-height: 1.08;
                letter-spacing: -0.04em;
            }

            p {
                max-width: 34rem;
                margin: 0 auto;
                color: #506176;
                font-size: 1.05rem;
                line-height: 1.7;
            }

            nav {
                display: flex;
                flex-wrap: wrap;
                justify-content: center;
                gap: 0.75rem;
                margin-top: 2rem;
            }

            a {
                display: inline-flex;
                min-height: 2.75rem;
                align-items: center;
                justify-content: center;
                padding: 0.65rem 1.1rem;
                border: 1px solid #0080b4;
                border-radius: 0.65rem;
                color: #006f99;
                font-weight: 650;
                text-decoration: none;
            }

            a.primary {
                background: #0080b4;
                color: #fff;
            }

            a:hover {
                text-decoration: underline;
                text-underline-offset: 0.2rem;
            }

            a:focus-visible {
                outline: 3px solid #f5a623;
                outline-offset: 3px;
            }

            @media (prefers-color-scheme: dark) {
                :root {
                    background: #101823;
                    color: #f5f8fa;
                }

                body {
                    background:
                        radial-gradient(circle at top left, rgb(0 150 210 / 18%), transparent 32rem),
                        #101823;
                }

                main {
                    border-color: #334255;
                    background: rgb(24 34 48 / 95%);
                    box-shadow: 0 1.5rem 4rem rgb(0 0 0 / 30%);
                }

                .logo {
                    filter: invert(1);
                }

                .status {
                    background: #14384a;
                    color: #83d8f5;
                }

                p {
                    color: #bdc9d6;
                }

                a {
                    border-color: #45bce8;
                    color: #83d8f5;
                }

                a.primary {
                    background: #0080b4;
                    color: #fff;
                }
            }
        </style>
    </head>
    <body>
        <main>
            <img class="logo" src="{{ asset('logo.svg') }}" alt="ERNIE">
            <div class="status" aria-label="Error 404">404</div>
            <h1>This page is no longer available.</h1>
            <p>
                The address may be outdated, or the requested resource may have been removed from ERNIE.
                You can continue in the public data portal or return to the application homepage.
            </p>
            <nav aria-label="Error page navigation">
                <a class="primary" href="{{ route('portal') }}">Explore the data portal</a>
                <a href="{{ url('/') }}">Return to the homepage</a>
            </nav>
        </main>
    </body>
</html>
