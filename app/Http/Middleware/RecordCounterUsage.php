<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\LandingPage;
use App\Services\Statistics\PortalSearchAnalyticsService;
use App\Services\Usage\CounterEventRecorderService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Wrap Inertia so its final status (including version conflicts) is respected. */
final class RecordCounterUsage
{
    public function __construct(private readonly CounterEventRecorderService $recorder) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        try {
            $this->record($request, $response);
        } catch (Throwable $exception) {
            Log::warning('COUNTER response recording failed.', ['exception_type' => $exception::class]);
        }

        return $response;
    }

    private function record(Request $request, Response $response): void
    {
        if (! $this->recorder->eligible($request)) {
            return;
        }
        $name = $request->route()?->getName() ?? '';
        $status = $response->getStatusCode();
        if (in_array($name, ['portal.doi', 'portal.igsn'], true) && in_array($status, [200, 304], true)) {
            $this->recorder->search($request, $name === 'portal.igsn' ? 'igsn' : 'doi');
            // New clients no longer send a second analytics POST. Old POST clients
            // remain compatible until activation; unmarked Inertia navigation is ignored.
            if (! $request->headers->has('X-Inertia') || $request->header('X-Ernie-Search-Intent') === 'submit') {
                $term = $request->query('q');
                app(PortalSearchAnalyticsService::class)->recordSearch($request, is_string($term) ? $term : null);
            }

            return;
        }
        $formats = [
            'landing-page.show' => 'landing-page',
            'landing-page.metadata.datacite-xml' => 'datacite-xml',
            'landing-page.metadata.datacite-json' => 'datacite-json',
            'landing-page.metadata.datacite-jsonld' => 'datacite-jsonld',
            'landing-page.export-jsonld' => 'datacite-jsonld',
            'landing-page.metadata.iso19115-3' => 'iso19115-3',
            'landing-page.metadata.iso19115-4' => 'iso19115-4',
            'landing-page.metadata.igsn-core' => 'igsn-core-2.0',
        ];
        if (isset($formats[$name]) && in_array($status, [200, 304], true)) {
            $page = LandingPage::query()->with('resource.resourceType')
                ->where('doi_prefix', $request->route('doiPrefix'))->where('slug', $request->route('slug'))->first();
            if ($page !== null) {
                $this->recorder->landingPage($request, $page, 'investigation', $formats[$name]);
            }
        } elseif (in_array($name, ['landing-page.download.primary', 'landing-page.download.file', 'landing-page.download.link'], true) && $status === 302) {
            $page = $request->route('landingPage');
            if ($page instanceof LandingPage) {
                $this->recorder->landingPage($request, $page, 'request', (string) $response->headers->get('Location'));
            }
        }
    }
}
