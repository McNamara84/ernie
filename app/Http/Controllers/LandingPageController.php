<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CacheKey;
use App\Exceptions\ResourceAlreadyExistsException;
use App\Http\Requests\LandingPage\StoreLandingPageRequest;
use App\Http\Requests\LandingPage\UpdateLandingPageRequest;
use App\Models\LandingPage;
use App\Models\LandingPageTemplate;
use App\Models\Resource;
use App\Services\KeywordSuggestionService;
use App\Support\Traits\ChecksCacheTagging;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class LandingPageController extends Controller
{
    use AuthorizesRequests;
    use ChecksCacheTagging;

    public static function templateSupportsCustomTemplateId(string $template): bool
    {
        return in_array($template, [
            LandingPageTemplate::DEFAULT_TEMPLATE_SLUG,
            LandingPageTemplate::IGSN_DEFAULT_TEMPLATE_SLUG,
        ], true);
    }

    public static function templateSupportsFtpUrl(string $template): bool
    {
        return $template === LandingPageTemplate::DEFAULT_TEMPLATE_SLUG;
    }

    private static function templateSupportsLinks(string $template): bool
    {
        return $template !== 'external'
            && ! in_array($template, self::IGSN_ONLY_TEMPLATES, true);
    }

    private static function templateSupportsExternalFields(string $template): bool
    {
        return $template === 'external';
    }

    public function __construct(
        private readonly KeywordSuggestionService $keywordService,
    ) {}

    /**
     * Allowed landing page templates.
     *
     * When adding new templates, update this list and create the corresponding
     * React component in resources/js/Pages/LandingPages/.
     *
     * Note: This constant is also used by LandingPagePreviewController for
     * session-based preview validation to ensure template consistency.
     *
     * @var list<string>
     */
    public const ALLOWED_TEMPLATES = [
        LandingPageTemplate::DEFAULT_TEMPLATE_SLUG,
        LandingPageTemplate::IGSN_DEFAULT_TEMPLATE_SLUG,
        'external',
    ];

    /**
     * Templates restricted to PhysicalObject resources (IGSNs).
     *
     * @var list<string>
     */
    public const IGSN_ONLY_TEMPLATES = [LandingPageTemplate::IGSN_DEFAULT_TEMPLATE_SLUG];

    /**
     * Templates restricted to non-PhysicalObject resources.
     *
     * @var list<string>
     */
    public const RESOURCE_ONLY_TEMPLATES = [LandingPageTemplate::DEFAULT_TEMPLATE_SLUG];

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
                        'contributors.contributorTypes',
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
    public function store(StoreLandingPageRequest $request, Resource $resource): JsonResponse
    {
        $this->authorize('create', LandingPage::class);

        $validated = $request->validated();

        $resource->loadMissing('resourceType');

        if ($templateError = LandingPageTemplate::builtInTemplateScopeError($validated['template'], $resource->resourceType?->slug)) {
            return response()->json([
                'message' => $templateError,
                'error' => 'invalid_template_for_resource_type',
            ], 422);
        }

        if (self::templateSupportsCustomTemplateId($validated['template'])
            && ($customTemplateError = LandingPageTemplate::customTemplateScopeError($validated['landing_page_template_id'] ?? null, $resource->resourceType?->slug))) {
            return response()->json([
                'message' => $customTemplateError,
                'error' => 'invalid_template_for_resource_type',
            ], 422);
        }

        // Detect conflicting status/is_published values.
        // If both are provided with conflicting values, this may indicate a client bug.
        if (isset($validated['status']) && isset($validated['is_published'])) {
            $statusImpliesPublished = $validated['status'] === 'published';
            if ($statusImpliesPublished !== $validated['is_published']) {
                Log::warning(
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
                $createData = [
                    'template' => $validated['template'],
                    'landing_page_template_id' => self::templateSupportsCustomTemplateId($validated['template'])
                        ? ($validated['landing_page_template_id'] ?? null)
                        : null,
                    'ftp_url' => self::templateSupportsFtpUrl($validated['template'])
                        ? ($validated['ftp_url'] ?? null)
                        : null,
                    'is_published' => $isPublished,
                    'published_at' => $isPublished ? now() : null,
                ];

                // Add external landing page fields when template is 'external'
                if ($validated['template'] === 'external') {
                    $createData['external_domain_id'] = $validated['external_domain_id'];
                    $createData['external_path'] = $validated['external_path'];
                    $createData['ftp_url'] = null; // FTP URL not relevant for external pages
                }

                $landingPage = $resource->landingPage()->create($createData);

                // Create additional links inside the transaction for atomicity
                if (! empty($validated['links']) && $validated['template'] !== 'external' && ! in_array($validated['template'], self::IGSN_ONLY_TEMPLATES, true)) {
                    $landingPage->links()->createMany($validated['links']);
                }

                return $landingPage;
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
                Log::warning(
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
        $landingPage->load(['externalDomain', 'links', 'landingPageTemplate']);

        // Invalidate keyword suggestions cache if landing page was created as published
        if ($landingPage->is_published) {
            $this->keywordService->invalidateCache();
        }

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
                'landing_page_template_id' => $landingPage->landing_page_template_id,
                'ftp_url' => $landingPage->ftp_url,
                'external_domain_id' => $landingPage->external_domain_id,
                'external_path' => $landingPage->external_path,
                'external_url' => $landingPage->external_url,
                'external_domain' => $landingPage->externalDomain,
                'links' => $landingPage->links,
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
    public function update(UpdateLandingPageRequest $request, Resource $resource): JsonResponse
    {
        $landingPage = $resource->landingPage;

        if (! $landingPage) {
            return response()->json([
                'message' => 'Landing page not found',
            ], 404);
        }

        $this->authorize('update', $landingPage);

        $validated = $request->validated();

        $resource->loadMissing('resourceType');

        if (isset($validated['template'])) {
            if ($templateError = LandingPageTemplate::builtInTemplateScopeError($validated['template'], $resource->resourceType?->slug)) {
                return response()->json([
                    'message' => $templateError,
                    'error' => 'invalid_template_for_resource_type',
                ], 422);
            }
        }

        $effectiveTemplate = array_key_exists('template', $validated)
            ? $validated['template']
            : LandingPageTemplate::normalizeBuiltInTemplateForResource($landingPage->template, $resource->resourceType?->slug);
        $templateChanged = $effectiveTemplate !== $landingPage->template;

        if (! array_key_exists('template', $validated) && $templateChanged) {
            $unsupportedFields = [];

            if (array_key_exists('ftp_url', $validated) && ! self::templateSupportsFtpUrl($effectiveTemplate)) {
                $unsupportedFields['ftp_url'] = [
                    'The ftp_url field is not supported when this landing page is normalized to the IGSN template.',
                ];
            }

            if (array_key_exists('links', $validated) && ! self::templateSupportsLinks($effectiveTemplate)) {
                $unsupportedFields['links'] = [
                    'The links field is not supported when this landing page is normalized to the IGSN template.',
                ];
            }

            if (array_key_exists('external_domain_id', $validated) && ! self::templateSupportsExternalFields($effectiveTemplate)) {
                $unsupportedFields['external_domain_id'] = [
                    'The external_domain_id field is not supported when this landing page is normalized to the IGSN template.',
                ];
            }

            if (array_key_exists('external_path', $validated) && ! self::templateSupportsExternalFields($effectiveTemplate)) {
                $unsupportedFields['external_path'] = [
                    'The external_path field is not supported when this landing page is normalized to the IGSN template.',
                ];
            }

            if ($unsupportedFields !== []) {
                return response()->json([
                    'message' => 'The request includes fields that are not supported for the normalized landing page template.',
                    'errors' => $unsupportedFields,
                ], 422);
            }
        }

        $effectiveLandingPageTemplateId = null;

        if (self::templateSupportsCustomTemplateId($effectiveTemplate)) {
            $effectiveLandingPageTemplateId = array_key_exists('landing_page_template_id', $validated)
                ? $validated['landing_page_template_id']
                : $landingPage->landing_page_template_id;

            if ($customTemplateError = LandingPageTemplate::customTemplateScopeError($effectiveLandingPageTemplateId, $resource->resourceType?->slug)) {
                if (array_key_exists('landing_page_template_id', $validated)) {
                    return response()->json([
                        'message' => $customTemplateError,
                        'error' => 'invalid_template_for_resource_type',
                    ], 422);
                }

                $effectiveLandingPageTemplateId = null;
            }
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

        // Wrap all mutations in a transaction for atomicity.
        // This ensures the landing page + links are updated together.
        DB::transaction(function () use ($landingPage, $validated, $effectiveLandingPageTemplateId, $effectiveTemplate, $templateChanged): void {
            // Update template and ftp_url if provided
            // Note: contact_url is a computed accessor (public_url + '/contact'), not a database field
            if ($templateChanged) {
                $landingPage->template = $effectiveTemplate;
            }

            if (self::templateSupportsCustomTemplateId($effectiveTemplate)) {
                $landingPage->landing_page_template_id = $effectiveLandingPageTemplateId;
            } else {
                $landingPage->landing_page_template_id = null;
            }

            if (self::templateSupportsFtpUrl($effectiveTemplate) && array_key_exists('ftp_url', $validated)) {
                $landingPage->ftp_url = $validated['ftp_url'];
            } elseif (! self::templateSupportsFtpUrl($effectiveTemplate)) {
                $landingPage->ftp_url = null;
            }

            // Update external landing page fields
            if (self::templateSupportsExternalFields($effectiveTemplate)) {
                if (array_key_exists('external_domain_id', $validated)) {
                    $landingPage->external_domain_id = $validated['external_domain_id'];
                }
                if (array_key_exists('external_path', $validated)) {
                    $landingPage->external_path = $validated['external_path'];
                }
                // Clear FTP URL for external pages (not relevant)
                $landingPage->ftp_url = null;
            } else {
                // Clear external fields when switching away from external template
                $landingPage->external_domain_id = null;
                $landingPage->external_path = null;
            }

            $landingPage->save();

            // Sync additional links: determine once whether this template supports links
            if (! self::templateSupportsLinks($effectiveTemplate)) {
                // Template does not support links – clear any existing ones
                $landingPage->links()->delete();
            } elseif (array_key_exists('links', $validated)) {
                // Template supports links and payload includes link data – replace all
                $landingPage->links()->delete();

                if (! empty($validated['links'])) {
                    $landingPage->links()->createMany($validated['links']);
                }
            }
        });

        // Handle publication status change: allow publishing a draft
        if ($requestedStatus !== null && $requestedStatus && ! $currentlyPublished) {
            $landingPage->publish();
            $this->keywordService->invalidateCache();
        }

        // Invalidate cache
        $this->invalidateCache($resource->id);

        $freshLandingPage = $landingPage->fresh();
        $freshLandingPage?->load(['externalDomain', 'files', 'links', 'landingPageTemplate']);

        return response()->json([
            'message' => 'Landing page updated successfully',
            'landing_page' => $freshLandingPage,
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

        $this->authorize('delete', $landingPage);

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

        $landingPage->load(['externalDomain', 'files', 'links', 'landingPageTemplate']);

        return response()->json([
            'landing_page' => self::serializeLandingPagePayload($resource, $landingPage),
        ]);
    }

    /**
     * Return the normalized landing page contract exposed to API consumers.
     *
     * Legacy Physical Object pages may still be stored with the old default
     * resource renderer and stale field combinations. The GET endpoint exposes
     * the same effective contract that update()/preview/public rendering use,
     * so clients can safely round-trip the payload without reimplementing the
     * normalization logic on their side.
     *
     * @return array<string, mixed>
     */
    public static function serializeLandingPagePayload(Resource $resource, LandingPage $landingPage): array
    {
        $resource->loadMissing('resourceType');

        $resourceTypeSlug = $resource->resourceType?->slug;
        $effectiveTemplate = LandingPageTemplate::normalizeBuiltInTemplateForResource($landingPage->template, $resourceTypeSlug);
        $effectiveLandingPageTemplate = self::templateSupportsCustomTemplateId($effectiveTemplate)
            ? LandingPageTemplate::resolveCustomTemplate($landingPage->landingPageTemplate, $resourceTypeSlug)
            : null;

        $payload = $landingPage->toArray();
        $payload['template'] = $effectiveTemplate;
        $payload['landing_page_template_id'] = $effectiveLandingPageTemplate?->id;
        $payload['landing_page_template'] = $effectiveLandingPageTemplate?->toArray();
        $payload['ftp_url'] = self::templateSupportsFtpUrl($effectiveTemplate)
            ? $landingPage->ftp_url
            : null;
        $payload['links'] = self::templateSupportsLinks($effectiveTemplate)
            ? $landingPage->links->values()->toArray()
            : [];

        if (self::templateSupportsExternalFields($effectiveTemplate)) {
            $payload['external_domain_id'] = $landingPage->external_domain_id;
            $payload['external_path'] = $landingPage->external_path;
            $payload['external_domain'] = $landingPage->externalDomain?->toArray();
            $payload['external_url'] = $landingPage->external_url;
        } else {
            $payload['external_domain_id'] = null;
            $payload['external_path'] = null;
            $payload['external_domain'] = null;
            $payload['external_url'] = null;
        }

        return $payload;
    }

    /**
     * Invalidate landing page cache.
     */
    private function invalidateCache(int $resourceId): void
    {
        // Forget main cache
        Cache::forget("landing-page.{$resourceId}");

        // Invalidate portal facets (datacenter + resource type) because
        // publishing/unpublishing changes which resources are "published".
        $this->invalidatePortalFacets();

        // Also try to forget preview caches (pattern matching would require Redis tags)
        // For now, we'll clear individual cache entries when we know the token
        // In production with Redis, you could use Cache::tags()
    }

    /**
     * Invalidate portal facet caches (datacenter + resource type).
     */
    private function invalidatePortalFacets(): void
    {
        foreach ([CacheKey::PORTAL_DATACENTER_FACETS, CacheKey::PORTAL_RESOURCE_TYPE_FACETS] as $cacheKey) {
            if ($this->supportsTagging()) {
                Cache::tags($cacheKey->tags())->flush();
            } else {
                Cache::forget($cacheKey->key());
            }
        }
    }
}
