<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\LandingPage;
use App\Models\Resource;
use App\Services\LandingPageResourceTransformer;
use Illuminate\Http\Request;
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
     */
    public function show(Request $request, LandingPageResourceTransformer $transformer, int $resourceId): Response
    {
        $previewToken = $request->query('preview');

        // Load landing page configuration first to check status
        $landingPage = LandingPage::where('resource_id', $resourceId)->first();

        // Landing page must exist
        abort_if(! $landingPage, HttpResponse::HTTP_NOT_FOUND, 'Landing page not found');

        // Check access permissions
        if (! $landingPage->isPublished()) {
            // For unpublished pages, require valid preview token
            if (! $previewToken) {
                abort(HttpResponse::HTTP_NOT_FOUND, 'Landing page not found');
            }
            if ($previewToken !== $landingPage->preview_token) {
                abort(HttpResponse::HTTP_FORBIDDEN, 'Invalid preview token');
            }
        }

        // Increment view count only for published pages without preview token
        if ($landingPage->isPublished() && ! $previewToken) {
            $landingPage->incrementViewCount();
        }

        // Load resource with all necessary relationships
        $resource = Resource::with($transformer->requiredRelations())->findOrFail($resourceId);

        $resourceData = $transformer->transform($resource);

        $data = [
            'resource' => $resourceData,
            'landingPage' => $landingPage->toArray(),
            'isPreview' => (bool) $previewToken,
        ];

        // Use the template specified in landing page configuration
        $template = $landingPage->template ?? 'default_gfz';

        return Inertia::render("LandingPages/{$template}", $data);
    }
}
