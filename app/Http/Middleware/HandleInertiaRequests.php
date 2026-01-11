<?php

namespace App\Http\Middleware;

use App\Support\UriHelper;
use App\Support\UrlNormalizer;
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
                'user' => $request->user() ? [
                    ...$request->user()->toArray(),
                    'role' => $request->user()->role->value,
                    'role_label' => $request->user()->role->label(),
                    // Gate-based permissions (Issue #379)
                    'can_manage_users' => $request->user()->can('manage-users'),
                    'can_register_production_doi' => $request->user()->can('register-production-doi'),
                    'can_delete_logs' => $request->user()->can('delete-logs'),
                    // Granular access permissions (Issue #379)
                    'can_access_logs' => $request->user()->can('access-logs'),
                    'can_access_old_datasets' => $request->user()->can('access-old-datasets'),
                    'can_access_statistics' => $request->user()->can('access-statistics'),
                    'can_access_users' => $request->user()->can('access-users'),
                    'can_access_editor_settings' => $request->user()->can('access-editor-settings'),
                ] : null,
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'fontSizePreference' => $request->user() ? $request->user()->font_size_preference : 'regular',
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
                $appUrl = UrlNormalizer::normalizeAppUrl(config('app.url'));
                if ($appUrl !== null) {
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
                $appUrl = UrlNormalizer::normalizeAppUrl(config('app.url'));
                if ($appUrl !== null) {
                    // Use PHP 8.5's RFC 3986 compliant URI parser
                    $path = UriHelper::getPath($appUrl) ?? '';
                    $path = rtrim($path, '/');

                    return $path === '' ? '' : $path;
                }
            }

            return '';
        } catch (\Exception $e) {
            return '';
        }
    }
}
