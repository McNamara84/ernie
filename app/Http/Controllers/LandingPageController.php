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
        // Cache key based on resource ID
        $cacheKey = "landing-page.{$resourceId}";

        $data = Cache::remember($cacheKey, now()->addHours(24), function () use ($resourceId) {
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

            // Only show published landing pages
            if (! $landingPage->isPublished()) {
                abort(404, 'Landing page not found or not published');
            }

            return $landingPage;
        });

        return Inertia::render('landing-page', [
            'landingPage' => $data,
            'resource' => $data->resource,
        ]);
    }

    /**
     * Store a newly created landing page configuration.
     */
    public function store(Request $request, Resource $resource): JsonResponse
    {
        $validated = $request->validate([
            'is_published' => 'boolean',
        ]);

        // Check if landing page already exists
        if ($resource->landingPage) {
            return response()->json([
                'message' => 'Landing page already exists for this resource',
            ], 409);
        }

        // Generate slug from resource
        $slug = \Illuminate\Support\Str::slug($resource->titles->first()->value ?? 'dataset-'.$resource->id);

        $landingPage = $resource->landingPage()->create([
            'slug' => $slug,
            'is_published' => $validated['is_published'] ?? false,
            'published_at' => ($validated['is_published'] ?? false) ? now() : null,
        ]);

        return response()->json([
            'message' => 'Landing page created successfully',
            'landing_page' => $landingPage->fresh(),
        ], 201);
    }

    /**
     * Update the landing page configuration.
     */
    public function update(Request $request, Resource $resource): JsonResponse
    {
        $validated = $request->validate([
            'is_published' => 'sometimes|boolean',
        ]);

        $landingPage = $resource->landingPage;

        if (! $landingPage) {
            return response()->json([
                'message' => 'Landing page not found',
            ], 404);
        }

        // Handle publication status change
        if (isset($validated['is_published'])) {
            if ($validated['is_published']) {
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
