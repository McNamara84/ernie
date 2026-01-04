<?php

namespace App\Http\Controllers;

use App\Models\LandingPage;
use App\Models\Resource;
use App\Services\SlugGeneratorService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
     *
     * The entire creation is wrapped in a transaction to ensure atomicity:
     * - Landing page creation and observer hooks (e.g., DOI sync) either all succeed or all fail
     * - Prevents partial state where landing page exists but related operations failed
     */
    public function store(Request $request, Resource $resource, SlugGeneratorService $slugGenerator): JsonResponse
    {
        $validated = $request->validate([
            'template' => 'required|string|in:default_gfz,minimal,detailed',
            'ftp_url' => 'nullable|url',
            'is_published' => 'boolean',
            'status' => 'sometimes|string|in:draft,published',
        ]);

        // Detect conflicting status/is_published values.
        // If both are provided with conflicting values, this may indicate a client bug.
        if (isset($validated['status']) && isset($validated['is_published'])) {
            $statusImpliesPublished = $validated['status'] === 'published';
            if ($statusImpliesPublished !== $validated['is_published']) {
                \Illuminate\Support\Facades\Log::warning(
                    'LandingPageController: Conflicting status and is_published values received',
                    [
                        'resource_id' => $resource->id,
                        'status' => $validated['status'],
                        'is_published' => $validated['is_published'],
                        'using' => 'status (preferred field)',
                    ]
                );
            }
        }

        // Check if landing page already exists
        if ($resource->landingPage) {
            return response()->json([
                'message' => 'Landing page already exists for this resource',
            ], 409);
        }

        // Wrap entire creation in transaction for atomicity.
        // The try-catch handles potential slug uniqueness violations that could occur
        // in a race condition where two requests try to create landing pages for resources
        // with identical titles at the same moment.
        try {
            $landingPage = DB::transaction(function () use ($validated, $resource, $slugGenerator) {
                // Load titles for slug generation
                $resource->load('titles.titleType');

                // Get main title for slug generation
                $mainTitle = $resource->titles
                    ->first(fn ($title) => $title->isMainTitle());
                $titleValue = $mainTitle !== null ? $mainTitle->value : "dataset-{$resource->id}";

                // Generate slug using the SlugGeneratorService
                $slug = $slugGenerator->generateFromTitle($titleValue);

                // Determine publication status.
                // API supports both 'status' (preferred) and 'is_published' (legacy) fields.
                $isPublished = false;
                if (isset($validated['status'])) {
                    $isPublished = $validated['status'] === 'published';
                } elseif (isset($validated['is_published'])) {
                    $isPublished = $validated['is_published'];
                }

                // Create landing page.
                // Note: doi_prefix is set automatically in the model's boot() method
                // from the resource's DOI. We don't set it here to avoid redundancy.
                return $resource->landingPage()->create([
                    'slug' => $slug,
                    'template' => $validated['template'],
                    'ftp_url' => $validated['ftp_url'] ?? null,
                    'is_published' => $isPublished,
                    'published_at' => $isPublished ? now() : null,
                ]);
            });
        } catch (QueryException $e) {
            // Check for unique constraint violation on slug.
            // We need to handle both MySQL and SQLite differently:
            //
            // MySQL: errorInfo[1] = 1062 (ER_DUP_ENTRY) for unique violations
            // SQLite: errorInfo[1] = 19 (SQLITE_CONSTRAINT) covers ALL constraint types
            //         (UNIQUE, NOT NULL, FOREIGN KEY, CHECK). We check the message for
            //         'UNIQUE constraint failed' for specificity, but also handle other
            //         SQLite constraint violations gracefully since they likely indicate
            //         data integrity issues that should be reported to the user.
            //
            // Note: errorInfo may be null or have missing indices in edge cases,
            // so we use null coalescing for safety.
            $errorCode = (string) ($e->errorInfo[1] ?? '');
            $errorMessage = $e->getMessage();

            // MySQL unique constraint violation
            if ($errorCode === '1062') {
                return response()->json([
                    'message' => 'A landing page with this URL slug already exists. Please modify the resource title or try again.',
                    'error' => 'slug_conflict',
                ], 409);
            }

            // SQLite constraint violations (error code 19).
            // We return specific messages where we can identify the constraint type,
            // and a generic constraint error for unrecognized SQLite constraint failures.
            if ($errorCode === '19') {
                if (str_contains($errorMessage, 'UNIQUE constraint failed')) {
                    return response()->json([
                        'message' => 'A landing page with this URL slug already exists. Please modify the resource title or try again.',
                        'error' => 'slug_conflict',
                    ], 409);
                }
                // Other SQLite constraint violations (NOT NULL, FOREIGN KEY, CHECK).
                // These indicate data integrity issues that the user should know about.
                \Illuminate\Support\Facades\Log::warning(
                    'LandingPageController: SQLite constraint violation (non-UNIQUE)',
                    [
                        'error_message' => $errorMessage,
                        'error_code' => $errorCode,
                    ]
                );

                return response()->json([
                    'message' => 'Unable to create landing page due to a data constraint. Please check your input and try again.',
                    'error' => 'constraint_violation',
                ], 409);
            }

            throw $e;
        }

        $landingPage->refresh();

        // Determine status string for API response
        $status = $landingPage->is_published ? 'published' : 'draft';

        return response()->json([
            'message' => 'Landing page created successfully',
            'landing_page' => [
                'id' => $landingPage->id,
                'resource_id' => $landingPage->resource_id,
                'doi_prefix' => $landingPage->doi_prefix,
                'slug' => $landingPage->slug,
                'template' => $landingPage->template,
                'ftp_url' => $landingPage->ftp_url,
                'status' => $status,
                'preview_token' => $landingPage->preview_token,
                'preview_url' => $landingPage->preview_url,
                'public_url' => $landingPage->public_url,
            ],
        ], 201);
    }

    /**
     * Update the landing page configuration.
     */
    public function update(Request $request, Resource $resource): JsonResponse
    {
        $validated = $request->validate([
            'template' => 'sometimes|string|in:default_gfz,minimal,detailed',
            'ftp_url' => 'nullable|url',
            'is_published' => 'sometimes|boolean',
            'status' => 'sometimes|string|in:draft,published',
        ]);

        $landingPage = $resource->landingPage;

        if (! $landingPage) {
            return response()->json([
                'message' => 'Landing page not found',
            ], 404);
        }

        // Update template and ftp_url if provided
        if (isset($validated['template'])) {
            $landingPage->template = $validated['template'];
        }
        if (array_key_exists('ftp_url', $validated)) {
            $landingPage->ftp_url = $validated['ftp_url'];
        }

        $landingPage->save();

        // Handle publication status change (support both 'status' and 'is_published' fields).
        //
        // Note: We intentionally do NOT wrap publish()/unpublish() in transactions here.
        // Currently these methods only update a single row, so transactions would add
        // overhead without benefit. If these methods evolve to need atomicity (e.g., for
        // multi-table operations, event dispatch, or audit logging), the transaction
        // should be added INSIDE the publish()/unpublish() methods themselves where the
        // atomic boundary is clear. This follows the principle of encapsulating
        // transactional needs within the methods that require them.
        if (isset($validated['status'])) {
            $shouldPublish = $validated['status'] === 'published';
            $shouldPublish ? $landingPage->publish() : $landingPage->unpublish();
        } elseif (isset($validated['is_published'])) {
            $validated['is_published'] ? $landingPage->publish() : $landingPage->unpublish();
        }
        // If neither 'status' nor 'is_published' is provided, publication status remains unchanged

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
