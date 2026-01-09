<?php

namespace App\Http\Controllers;

use App\Exceptions\ResourceAlreadyExistsException;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Rules\SafeUrl;
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
    public function store(Request $request, Resource $resource): JsonResponse
    {
        $validated = $request->validate([
            'template' => 'required|string|in:default_gfz,minimal,detailed',
            'ftp_url' => ['nullable', new SafeUrl, 'max:2048'],
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

        // Wrap entire creation in transaction for atomicity.
        // The existence check is INSIDE the transaction to prevent race conditions:
        // Without this, two concurrent requests could both pass the check, then both
        // try to create, causing a constraint violation on resource_id unique index.
        // The try-catch handles both resource_id and slug uniqueness violations.
        try {
            $landingPage = DB::transaction(function () use ($validated, $resource) {
                // Check if landing page already exists - INSIDE transaction
                // Use lockForUpdate to prevent race conditions with concurrent requests
                $existingLandingPage = LandingPage::where('resource_id', $resource->id)
                    ->lockForUpdate()
                    ->first();

                if ($existingLandingPage !== null) {
                    // Throw exception to signal "already exists" condition.
                    // This maintains proper transaction semantics: if an exception occurs,
                    // the transaction is rolled back. Using exceptions instead of null return
                    // ensures atomicity - the exception is thrown BEFORE commit, so either
                    // the create succeeds and commits, or the exception aborts the transaction.
                    throw new ResourceAlreadyExistsException('landing page', $resource->id);
                }

                // Determine publication status.
                // API supports both 'status' (preferred) and 'is_published' (legacy) fields.
                $isPublished = false;
                if (isset($validated['status'])) {
                    $isPublished = $validated['status'] === 'published';
                } elseif (isset($validated['is_published'])) {
                    $isPublished = $validated['is_published'];
                }

                // Create landing page.
                // Note: slug and doi_prefix are set automatically in the model's boot() method.
                // The model will load titles.titleType if needed for slug generation.
                // We don't set them here to avoid redundancy and ensure single source of truth.
                return $resource->landingPage()->create([
                    'template' => $validated['template'],
                    'ftp_url' => $validated['ftp_url'] ?? null,
                    'is_published' => $isPublished,
                    'published_at' => $isPublished ? now() : null,
                ]);
            });
        } catch (ResourceAlreadyExistsException) {
            // Handle "already exists" condition from inside the transaction.
            // The exception is thrown BEFORE commit, so the transaction was never committed.
            return response()->json([
                'message' => 'Landing page already exists for this resource',
                'error' => 'already_exists',
            ], 409);
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
            // so we use null coalescing for safety. We cast to int for consistent comparison.
            $errorCode = (int) ($e->errorInfo[1] ?? 0);
            $errorMessage = $e->getMessage();

            // MySQL unique constraint violation
            if ($errorCode === 1062) {
                // Differentiate between resource_id constraint and slug constraint
                // by checking the error message for the constraint name or column
                if (str_contains($errorMessage, 'resource_id') || str_contains($errorMessage, 'landing_pages_resource_id')) {
                    return response()->json([
                        'message' => 'Landing page already exists for this resource',
                        'error' => 'already_exists',
                    ], 409);
                }

                return response()->json([
                    'message' => 'A landing page with this URL slug already exists. Please modify the resource title or try again.',
                    'error' => 'slug_conflict',
                ], 409);
            }

            // SQLite constraint violations (error code 19).
            // We return specific messages where we can identify the constraint type,
            // and a generic constraint error for unrecognized SQLite constraint failures.
            if ($errorCode === 19) {
                if (str_contains($errorMessage, 'UNIQUE constraint failed')) {
                    // Differentiate between resource_id and slug constraints
                    if (str_contains($errorMessage, 'resource_id')) {
                        return response()->json([
                            'message' => 'Landing page already exists for this resource',
                            'error' => 'already_exists',
                        ], 409);
                    }

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

        // Note: If we reach this point, the transaction succeeded and $landingPage
        // is guaranteed to be a valid LandingPage instance. The catch blocks above
        // handle all exception cases by returning early, so we never reach
        // refresh() after a failed transaction.
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
            'ftp_url' => ['nullable', new SafeUrl, 'max:2048'],
            'is_published' => 'sometimes|boolean',
            'status' => 'sometimes|string|in:draft,published',
        ]);

        $landingPage = $resource->landingPage;

        if (! $landingPage) {
            return response()->json([
                'message' => 'Landing page not found',
            ], 404);
        }

        // Determine requested publication status change (if any).
        // Support both 'status' (preferred) and 'is_published' (legacy) fields.
        $currentlyPublished = $landingPage->isPublished();
        $requestedStatus = null;

        if (isset($validated['status'])) {
            $requestedStatus = $validated['status'] === 'published';
        } elseif (isset($validated['is_published'])) {
            $requestedStatus = $validated['is_published'];
        }

        // Validate publication status BEFORE saving any changes.
        // This ensures atomicity: if unpublishing is not allowed, no changes are persisted.
        // IMPORTANT: Published landing pages cannot be unpublished because DOIs are persistent
        // and must always resolve to a valid landing page.
        if ($requestedStatus !== null && $currentlyPublished && ! $requestedStatus) {
            return response()->json([
                'message' => 'Cannot unpublish a published landing page. DOIs are persistent and must always resolve to a valid landing page.',
                'error' => 'cannot_unpublish',
            ], 422);
        }

        // Update template and ftp_url if provided
        // Note: contact_url is a computed accessor (public_url + '/contact'), not a database field
        if (isset($validated['template'])) {
            $landingPage->template = $validated['template'];
        }
        if (array_key_exists('ftp_url', $validated)) {
            $landingPage->ftp_url = $validated['ftp_url'];
        }

        $landingPage->save();

        // Handle publication status change: allow publishing a draft
        if ($requestedStatus !== null && $requestedStatus && ! $currentlyPublished) {
            $landingPage->publish();
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
     *
     * IMPORTANT: Published landing pages cannot be deleted because DOIs are persistent
     * and must always resolve to a valid landing page. Only draft (preview) landing pages
     * can be deleted.
     */
    public function destroy(Resource $resource): JsonResponse
    {
        $landingPage = $resource->landingPage;

        if (! $landingPage) {
            return response()->json([
                'message' => 'Landing page not found',
            ], 404);
        }

        // Prevent deletion of published landing pages
        if ($landingPage->isPublished()) {
            return response()->json([
                'message' => 'Cannot delete a published landing page. DOIs are persistent and must always resolve to a valid landing page.',
                'error' => 'cannot_delete_published',
            ], 422);
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
