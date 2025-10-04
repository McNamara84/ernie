<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>API Documentation</title>
    <link rel="icon" type="image/png" href="{{ asset('favicon-96x96.png') }}">
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    @unless(app()->environment('testing'))
        @vite('resources/css/app.css')
    @endunless
</head>
<body>
    <main id="main-content" class="min-h-screen bg-zinc-50 p-6 dark:bg-zinc-900">
        <div class="mx-auto flex w-full max-w-6xl flex-col gap-6" role="document">
            <header class="space-y-2">
                <p class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                    API reference
                </p>
                <h1 class="text-3xl font-bold text-zinc-900 dark:text-zinc-50">
                    OpenAPI documentation
                </h1>
                <p class="max-w-2xl text-base text-zinc-600 dark:text-zinc-300">
                    Explore every endpoint that powers the metadata editor. Use the interactive console below to
                    inspect requests, review payloads, and try out operations safely.
                </p>
            </header>

            <div
                id="swagger-ui"
                aria-label="Interactive API documentation"
                class="rounded-xl border border-zinc-200 bg-white shadow-sm focus-within:ring-2 focus-within:ring-blue-500 dark:border-zinc-700 dark:bg-zinc-950"
                tabindex="0"
            ></div>
            <noscript>
                <p class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-amber-900 dark:border-amber-500 dark:bg-amber-950 dark:text-amber-200">
                    JavaScript is required to view the interactive documentation.
                </p>
            </noscript>
        </div>
        <script>window.__spec__ = @json($spec);</script>
        @unless(app()->environment('testing'))
            @vite('resources/js/swagger.tsx')
        @endunless
    </main>
</body>
</html>
