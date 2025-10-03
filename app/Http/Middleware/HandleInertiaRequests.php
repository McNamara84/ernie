<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        try {
            [$message, $author] = str(Inspiring::quotes()->random())->explode('-');
        } catch (\Exception $e) {
            $message = 'Stay hungry, stay foolish';
            $author = 'Steve Jobs';
        }

        return [
            ...parent::share($request),
            'name' => config('app.name', 'ERNIE'),
            'quote' => ['message' => trim((string) $message), 'author' => trim((string) $author)],
            'auth' => [
                'user' => $request->user(),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'appUrl' => $this->getBaseUrl($request),
            'baseUrl' => $this->getBaseUrl($request),
            'pathPrefix' => $this->getPathPrefix($request),
        ];
    }

    /**
     * Get the correct base URL for the application
     */
    private function getBaseUrl(Request $request): string
    {
        try {
            // In production behind proxy, use the configured URL
            if (app()->environment('production')) {
                $appUrl = config('app.url');
                if ($appUrl) {
                    return $appUrl;
                }
            }

            // Otherwise use the request URL
            return $request->getSchemeAndHttpHost();
        } catch (\Exception $e) {
            return $request->getSchemeAndHttpHost();
        }
    }

    /**
     * Get the path prefix from the configured URL
     */
    private function getPathPrefix(Request $request): string
    {
        try {
            // In production, extract path prefix from configured URL
            if (app()->environment('production')) {
                $appUrl = config('app.url');
                if ($appUrl) {
                    $parsedUrl = parse_url($appUrl);
                    return $parsedUrl['path'] ?? '/';
                }
            }

            return '/';
        } catch (\Exception $e) {
            return '/';
        }
    }
}
