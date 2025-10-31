<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\LandingPage;
use App\Models\Resource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Public Landing Page Controller
 *
 * Handles public-facing landing pages for research datasets.
 * Supports preview mode via token for draft pages.
 */
class LandingPagePublicController extends Controller
{
    /**
     * Display a public landing page for a resource
     *
     * @param Request $request
     * @param int $resourceId
     * @return Response
     */
    public function show(Request $request, int $resourceId): Response
    {
        $previewToken = $request->query('preview');

        // Try to get from cache first (only for published pages without preview token)
        if (! $previewToken) {
            $cached = Cache::get("landing_page.{$resourceId}");
            if ($cached) {
                return $cached;
            }
        }

        // Load resource with all necessary relationships
        $resource = Resource::with([
            'authors.authorable',
            'authors.affiliations',
            'authors.roles',
            'titles',
            'descriptions',
            'licenses',
            'keywords',
            'controlledKeywords',
            'coverages',
            'dates',
            'relatedIdentifiers',
            'fundingReferences',
            'resourceType',
            'language',
        ])->findOrFail($resourceId);

        // Load landing page configuration
        $landingPage = LandingPage::where('resource_id', $resourceId)->first();

        // If preview token is provided, validate it
        if ($previewToken) {
            abort_if(
                ! $landingPage || $landingPage->preview_token !== $previewToken,
                HttpResponse::HTTP_FORBIDDEN,
                'Invalid preview token'
            );
        } else {
            // For public access, landing page must exist and be published
            abort_if(
                ! $landingPage || $landingPage->status !== 'published',
                HttpResponse::HTTP_NOT_FOUND,
                'Landing page not found or not published'
            );

            // Increment view count for published pages
            $landingPage->incrementViewCount();
        }

        // Prepare data for template
        $data = [
            'resource' => $resource->toArray(),
            'landingPage' => $landingPage->toArray(),
            'isPreview' => (bool) $previewToken,
        ];

        // Render via template system (will be implemented in Sprint 3 Step 12)
        $response = Inertia::render("LandingPages/{$landingPage->template}", $data);

        // Cache published pages for 24 hours
        if (! $previewToken && $landingPage->status === 'published') {
            Cache::put("landing_page.{$resourceId}", $response, now()->addDay());
        }

        return $response;
    }
}
