<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\LandingPage;
use App\Models\Resource;
use App\Services\LandingPageResourceTransformer;
use Illuminate\Http\RedirectResponse;
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
     * Regex pattern for valid slug characters.
     * Must match the route constraint in web.php.
     */
    private const SLUG_PATTERN = '/^[a-z0-9-]+$/';

    /**
     * Display a public landing page for a resource with DOI.
     * URL pattern: /{doiPrefix}/{slug}
     *
     * @param  string  $doiPrefix  DOI prefix (validated by route constraint)
     * @param  string  $slug  URL slug (validated by route constraint and method)
     */
    public function show(
        Request $request,
        LandingPageResourceTransformer $transformer,
        string $doiPrefix,
        string $slug
    ): Response {
        // Validate slug format explicitly (defense in depth)
        abort_unless(
            preg_match(self::SLUG_PATTERN, $slug) === 1,
            HttpResponse::HTTP_BAD_REQUEST,
            'Invalid slug format'
        );

        $previewToken = $request->query('preview');

        // Find landing page by DOI prefix and slug
        $landingPage = LandingPage::where('doi_prefix', $doiPrefix)
            ->where('slug', $slug)
            ->first();

        abort_if($landingPage === null, HttpResponse::HTTP_NOT_FOUND, 'Landing page not found');

        return $this->renderLandingPage($landingPage, $transformer, $previewToken);
    }

    /**
     * Display a public landing page for a draft resource (without DOI).
     * URL pattern: /draft-{resourceId}/{slug}
     *
     * @param  int  $resourceId  Resource ID (validated by route constraint)
     * @param  string  $slug  URL slug (validated by route constraint and method)
     */
    public function showDraft(
        Request $request,
        LandingPageResourceTransformer $transformer,
        int $resourceId,
        string $slug
    ): Response {
        // Validate slug format explicitly (defense in depth)
        abort_unless(
            preg_match(self::SLUG_PATTERN, $slug) === 1,
            HttpResponse::HTTP_BAD_REQUEST,
            'Invalid slug format'
        );

        $previewToken = $request->query('preview');

        // Find landing page by resource ID and slug (no DOI)
        $landingPage = LandingPage::where('resource_id', $resourceId)
            ->whereNull('doi_prefix')
            ->where('slug', $slug)
            ->first();

        abort_if($landingPage === null, HttpResponse::HTTP_NOT_FOUND, 'Landing page not found');

        return $this->renderLandingPage($landingPage, $transformer, $previewToken);
    }

    /**
     * Legacy route handler - redirects to new URL format.
     * URL pattern: /datasets/{resourceId}
     */
    public function showLegacy(
        LandingPageResourceTransformer $transformer,
        int $resourceId
    ): Response|RedirectResponse {
        $landingPage = LandingPage::where('resource_id', $resourceId)->first();

        abort_if($landingPage === null, HttpResponse::HTTP_NOT_FOUND, 'Landing page not found');

        // Redirect to new URL format
        return redirect()->to($landingPage->public_url, HttpResponse::HTTP_MOVED_PERMANENTLY);
    }

    /**
     * Common rendering logic for landing pages.
     */
    private function renderLandingPage(
        LandingPage $landingPage,
        LandingPageResourceTransformer $transformer,
        ?string $previewToken
    ): Response {
        // Check access permissions
        if (! $landingPage->isPublished()) {
            if ($previewToken === null || $previewToken === '') {
                abort(HttpResponse::HTTP_NOT_FOUND, 'Landing page not found');
            }
            if ($previewToken !== $landingPage->preview_token) {
                abort(HttpResponse::HTTP_FORBIDDEN, 'Invalid preview token');
            }
        }

        // Increment view count only for published pages without preview token
        if ($landingPage->isPublished() && ($previewToken === null || $previewToken === '')) {
            $landingPage->incrementViewCount();
        }

        // Load resource with all necessary relationships
        $resource = Resource::with($transformer->requiredRelations())
            ->findOrFail($landingPage->resource_id);

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
