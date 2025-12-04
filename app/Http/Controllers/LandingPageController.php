<?php

namespace App\Http\Controllers;

use App\Models\LandingPage;
use App\Models\Resource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class LandingPageController extends Controller
{
    /**
     * Display the public landing page.
     */
    public function show(Request $request, int $resourceId): Response
    {
        $previewToken = $request->query('preview');

        // Cache key based on resource ID and preview mode
        $cacheKey = "landing-page.{$resourceId}".($previewToken ? ".preview.{$previewToken}" : '');

        $data = Cache::remember($cacheKey, now()->addHours(24), function () use ($resourceId, $previewToken) {
            $landingPage = LandingPage::with([
                'resource' => function ($query) {
                    $query->with([
                        'titles',
                        'resourceType',
                        'language',
                        'creators.creatorable',
                        'creators.affiliations',
                        'contributors.contributorable',
                        'contributors.contributorType',
                        'contributors.affiliations',
                        'rights',
                        'descriptions',
                        'dates',
                        'subjects',
                        'geoLocations',
                        'fundingReferences',
                        'relatedIdentifiers',
                    ]);
                },
            ])->where('resource_id', $resourceId)->firstOrFail();

            // Access validation
            if (! $landingPage->isPublished()) {
                if (! $previewToken || $previewToken !== $landingPage->preview_token) {
                    abort(404, 'Landing page not found or not published');
                }
            }

            return $landingPage;
        });

        // Increment view count (outside cache)
        if (! $previewToken && $data->isPublished()) {
            $data->incrementViewCount();
        }

        return Inertia::render('landing-page', [
            'landingPage' => $data,
            'resource' => $data->resource,
            'isPreview' => (bool) $previewToken,
        ]);
    }

    /**
     * Store a newly created landing page configuration.
     */
    public function store(Request $request, Resource $resource): JsonResponse
    {
        $validated = $request->validate([
            'template' => 'required|in:'.implode(',', array_keys(LandingPage::TEMPLATES)),
            'ftp_url' => 'nullable|url|max:2048',
            'status' => 'required|in:'.LandingPage::STATUS_DRAFT.','.LandingPage::STATUS_PUBLISHED,
        ]);

        // Check if landing page already exists
        if ($resource->landingPage) {
            return response()->json([
                'message' => 'Landing page already exists for this resource',
            ], 409);
        }

        $landingPage = $resource->landingPage()->create($validated);

        // Generate preview token
        $landingPage->generatePreviewToken();

        // If published, set published_at timestamp
        if ($validated['status'] === LandingPage::STATUS_PUBLISHED) {
            $landingPage->publish();
        }

        return response()->json([
            'message' => 'Landing page created successfully',
            'landing_page' => $landingPage->fresh(),
            'preview_url' => $landingPage->preview_url,
        ], 201);
    }

    /**
     * Update the landing page configuration.
     */
    public function update(Request $request, Resource $resource): JsonResponse
    {
        $validated = $request->validate([
            'template' => 'sometimes|in:'.implode(',', array_keys(LandingPage::TEMPLATES)),
            'ftp_url' => 'nullable|url|max:2048',
            'status' => 'sometimes|in:'.LandingPage::STATUS_DRAFT.','.LandingPage::STATUS_PUBLISHED,
        ]);

        $landingPage = $resource->landingPage;

        if (! $landingPage) {
            return response()->json([
                'message' => 'Landing page not found',
            ], 404);
        }

        $landingPage->update($validated);

        // Handle status change
        if (isset($validated['status'])) {
            if ($validated['status'] === LandingPage::STATUS_PUBLISHED) {
                $landingPage->publish();
            } else {
                $landingPage->unpublish();
            }
        }

        // Invalidate cache
        $this->invalidateCache($resource->id);

        return response()->json([
            'message' => 'Landing page updated successfully',
            'landing_page' => $landingPage->fresh(),
        ]);
    }

    /**
     * Remove the landing page configuration.
     */
    public function destroy(Resource $resource): JsonResponse
    {
        $landingPage = $resource->landingPage;

        if (! $landingPage) {
            return response()->json([
                'message' => 'Landing page not found',
            ], 404);
        }

        $landingPage->delete();

        // Invalidate cache
        $this->invalidateCache($resource->id);

        return response()->json([
            'message' => 'Landing page deleted successfully',
        ]);
    }

    /**
     * Get the landing page configuration for a resource.
     */
    public function get(Resource $resource): JsonResponse
    {
        $landingPage = $resource->landingPage;

        if (! $landingPage) {
            return response()->json([
                'message' => 'Landing page not found',
            ], 404);
        }

        return response()->json([
            'landing_page' => $landingPage,
        ]);
    }

    /**
     * Invalidate landing page cache.
     */
    private function invalidateCache(int $resourceId): void
    {
        // Forget main cache
        Cache::forget("landing-page.{$resourceId}");

        // Also try to forget preview caches (pattern matching would require Redis tags)
        // For now, we'll clear individual cache entries when we know the token
        // In production with Redis, you could use Cache::tags()
    }
}
