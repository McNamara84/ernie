<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="app-base-path" content="{{ parse_url(config('app.url'), PHP_URL_PATH) ?? '' }}">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: oklch(1 0 0);
            }

            html.dark {
                background-color: oklch(0.145 0 0);
            }
        </style>

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
        <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
        <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
        <link rel="icon" href="{{ asset('favicon-96x96.png') }}" type="image/png" sizes="96x96">
        <link rel="manifest" href="{{ asset('site.webmanifest') }}">        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

        {{-- Set base URL for Inertia.js --}}
        <script>
            // Set global variables for JavaScript  
            window.APP_URL = '{{ config('app.url') }}';
            
            // Override Inertia.js at the global level before it loads
            (function() {
                if (window.location.hostname !== 'localhost' && window.location.hostname !== '127.0.0.1') {
                    // Store original fetch
                    const originalFetch = window.fetch;
                    
                    // Override fetch for all HTTP requests
                    window.fetch = function(input, init) {
                        if (typeof input === 'string' && input.startsWith('/') && !input.startsWith('//')) {
                            input = '{{ config('app.url') }}' + input;
                        } else if (input && typeof input === 'object' && input.url) {
                            if (input.url.startsWith('/') && !input.url.startsWith('//')) {
                                input.url = '{{ config('app.url') }}' + input.url;
                            }
                        }
                        return originalFetch.call(this, input, init);
                    };
                    
                    // Override XMLHttpRequest as backup
                    const originalOpen = XMLHttpRequest.prototype.open;
                    XMLHttpRequest.prototype.open = function(method, url, ...args) {
                        if (typeof url === 'string' && url.startsWith('/') && !url.startsWith('//')) {
                            url = '{{ config('app.url') }}' + url;
                        }
                        return originalOpen.call(this, method, url, ...args);
                    };
                }
            })();
        </script>

        @viteReactRefresh
        @vite(['resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        @inertiaHead
        
        {{-- Additional script to configure Inertia after it loads --}}
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                // Wait for Inertia to be available and configure it
                function configureInertia() {
                    if (typeof window.Inertia !== 'undefined' && window.location.hostname !== 'localhost') {
                        console.log('Configuring Inertia for production...');
                        const appUrl = '{{ config('app.url') }}';
                        
                        // Override visit method
                        const originalVisit = window.Inertia.visit;
                        window.Inertia.visit = function(url, options) {
                            if (typeof url === 'string' && url.startsWith('/') && !url.startsWith('//')) {
                                url = appUrl + url;
                                console.log('Inertia visit redirected to:', url);
                            }
                            return originalVisit.call(this, url, options);
                        };
                    } else if (typeof window.Inertia === 'undefined') {
                        // Retry after 100ms if Inertia is not yet loaded
                        setTimeout(configureInertia, 100);
                    }
                }
                configureInertia();
            });
        </script>
    </head>
    <body class="font-sans antialiased">
        @inertia
        
        {{-- Debug information for production --}}
        @if(app()->environment('production'))
        <script>
            console.log('Production Debug Info:');
            console.log('APP_URL:', '{{ config('app.url') }}');
            console.log('Current URL:', window.location.href);
            console.log('Meta base-path:', document.querySelector('meta[name="app-base-path"]')?.content);
            console.log('Inertia page data:', document.getElementById('app')?.getAttribute('data-page') ? JSON.parse(document.getElementById('app').getAttribute('data-page')) : 'No data-page found');
        </script>
        @endif
    </body>
</html>