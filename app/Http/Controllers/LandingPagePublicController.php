<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CacheKey;
use App\Models\LandingPage;
use App\Models\LandingPageTemplate;
use App\Models\Resource;
use App\Services\DataCiteLinkedDataExporter;
use App\Services\LandingPageResourceTransformer;
use App\Services\SchemaOrgJsonLdExporter;
use App\Support\Traits\ChecksCacheTagging;
use Illuminate\Http\JsonResponse;
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
    use ChecksCacheTagging;
    /**
     * Regex pattern for valid slug characters.
     * Must match the route constraint in web.php.
     *
     * Defense-in-depth: While the route constraint already validates the slug format,
     * this additional check provides an extra layer of security in case:
     * - Route constraints are modified without updating controller
     * - Custom routing bypasses the constraint
     * - Future refactoring introduces new entry points
     */
    private const SLUG_PATTERN = '/^[a-z0-9-]+$/';

    /**
     * Pattern for basic DOI prefix format validation.
     *
     * This is more restrictive than the route constraint to catch edge cases
     * the permissive route regex might allow:
     * - No consecutive slashes (//)
     * - No leading/trailing dots in path segments
     * - No empty segments between slashes
     *
     * Format: 10.NNNN/suffix where suffix contains valid DOI characters.
     * Does NOT validate that the DOI actually exists - only format sanity.
     *
     * Pattern breakdown (segment-by-segment validation):
     * - ^10\.[0-9]+\/ : Must start with '10.' followed by digits and a slash
     * - [a-zA-Z0-9_-] : Each segment must start with an alphanumeric, underscore, or hyphen
     * - [a-zA-Z0-9._-]* : Segment can contain alphanumerics, dots, underscores, hyphens
     * - (\/ ... )* : Optional additional path segments following same rules
     * - $ : Must match to end of string
     *
     * Valid examples: 10.5880/test.data/subset, 10.5880/GFZ.1.2.2024.001
     * Invalid examples: 10.5880//test, 10.5880/./test, 10.5880/.test, 10.5880/test.
     */
    private const DOI_PREFIX_PATTERN = '/^10\.[0-9]+\/[a-zA-Z0-9_-][a-zA-Z0-9._-]*(\/[a-zA-Z0-9_-][a-zA-Z0-9._-]*)*$/';

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
    ): Response|RedirectResponse {
        // Validate slug format using shared helper method (defense in depth)
        $this->validateSlugFormat($slug, ['doi_prefix_length' => strlen($doiPrefix)]);

        // Validate DOI prefix format (defense in depth).
        // The route constraint is permissive to handle valid edge cases, but we
        // reject obviously malformed DOIs here (e.g., consecutive slashes, empty segments).
        $this->validateDoiPrefixFormat($doiPrefix);

        $previewToken = $request->query('preview');

        // Find landing page by DOI prefix and slug
        $landingPage = LandingPage::where('doi_prefix', $doiPrefix)
            ->where('slug', $slug)
            ->first();

        abort_if($landingPage === null, HttpResponse::HTTP_NOT_FOUND, 'Landing page not found');

        // Data consistency check: Verify the landing page's resource has the matching DOI.
        // This catches cases where database data is inconsistent (e.g., landing page has
        // doi_prefix='10.5880/A' but resource.doi='10.5880/B'). Such inconsistency would
        // mean the landing page is accessible via an incorrect DOI path.
        // We load only the doi column to minimize query overhead.
        //
        // Note: We only log this as an error for monitoring, but still serve the page.
        // The landing_page.doi_prefix is the source of truth for URL routing.
        // In production, this helps detect sync issues between resources and landing pages.
        $resourceDoi = $landingPage->resource()->value('doi');
        if ($resourceDoi !== null && $resourceDoi !== $doiPrefix) {
            \Illuminate\Support\Facades\Log::error(
                'LandingPagePublicController: DOI mismatch between landing page and resource',
                [
                    'landing_page_id' => $landingPage->id,
                    'landing_page_doi_prefix' => $doiPrefix,
                    'resource_doi' => $resourceDoi,
                ]
            );
            // Note: We continue serving the page using landing_page.doi_prefix as the
            // canonical source. The ResourceObserver should keep these in sync, but
            // if they drift, we prioritize availability over strict consistency.
        }

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
    ): Response|RedirectResponse {
        // Validate slug format using shared helper method (defense in depth)
        $this->validateSlugFormat($slug, ['resource_id' => $resourceId]);

        $previewToken = $request->query('preview');

        // Find landing page by resource ID and slug (no DOI).
        // Note: The query uses both resource_id AND slug in the WHERE clause, which
        // provides implicit validation that these values belong together. If a landing
        // page exists with a matching slug but different resource_id, or matching
        // resource_id but different slug, no result is returned (404). This prevents
        // URL manipulation attacks where someone might try to access a slug with a
        // different resource_id.
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
     * Export a landing page resource as DataCite Linked Data JSON-LD.
     * URL pattern: /{doiPrefix}/{slug}/jsonld
     */
    public function exportJsonLd(
        Request $request,
        string $doiPrefix,
        string $slug
    ): JsonResponse {
        $this->validateSlugFormat($slug, ['doi_prefix_length' => strlen($doiPrefix)]);
        $this->validateDoiPrefixFormat($doiPrefix);

        $landingPage = LandingPage::where('doi_prefix', $doiPrefix)
            ->where('slug', $slug)
            ->first();

        abort_if($landingPage === null, HttpResponse::HTTP_NOT_FOUND, 'Landing page not found');

        // Only published landing pages can be exported publicly
        if (! $landingPage->isPublished()) {
            abort(HttpResponse::HTTP_NOT_FOUND, 'Landing page not found');
        }

        $resource = Resource::findOrFail($landingPage->resource_id);

        $exporter = new DataCiteLinkedDataExporter;
        $jsonLd = $exporter->export($resource);

        $safeSlug = preg_replace('/[^a-z0-9-]/', '', $slug) ?: 'resource';
        $filename = "{$safeSlug}-datacite-ld.jsonld";

        return response()->json($jsonLd, 200, [
            'Content-Type' => 'application/ld+json',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Common rendering logic for landing pages.
     *
     * For external landing pages (template='external'), this issues a 301
     * permanent redirect to the composed external URL instead of rendering
     * an Inertia template. The 301 is used because the DOI is registered
     * with DataCite pointing to the external URL directly, so this redirect
     * is only a safety net for users accessing the internal route.
     */
    private function renderLandingPage(
        LandingPage $landingPage,
        LandingPageResourceTransformer $transformer,
        ?string $previewToken
    ): Response|RedirectResponse {
        // Normalize preview token: treat empty string as null for consistent checks
        $previewToken = $this->normalizePreviewToken($previewToken);

        // Check access permissions
        if (! $landingPage->isPublished()) {
            if ($previewToken === null) {
                abort(HttpResponse::HTTP_NOT_FOUND, 'Landing page not found');
            }
            if ($previewToken !== $landingPage->preview_token) {
                abort(HttpResponse::HTTP_FORBIDDEN, 'Invalid preview token');
            }
        }

        // External landing pages: 301 redirect to the configured external URL
        if ($landingPage->isExternal()) {
            // Eager-load externalDomain to avoid a lazy-load N+1 query
            // when accessing the external_url attribute below.
            $landingPage->loadMissing('externalDomain');

            $externalUrl = $landingPage->external_url;

            if ($externalUrl === null) {
                \Illuminate\Support\Facades\Log::error(
                    'LandingPagePublicController: External landing page has no external URL',
                    ['landing_page_id' => $landingPage->id, 'resource_id' => $landingPage->resource_id]
                );
                abort(HttpResponse::HTTP_INTERNAL_SERVER_ERROR, 'External landing page URL is not configured');
            }

            // Increment view count for published pages (before redirect)
            if ($landingPage->isPublished() && $previewToken === null) {
                $landingPage->incrementViewCount();
            }

            // Use 301 (permanent) for published pages accessed without a preview token,
            // and 302 (temporary) for draft previews. Browsers/proxies cache 301 responses,
            // which would cause issues when previewing drafts that may change or be removed.
            $statusCode = ($landingPage->isPublished() && $previewToken === null)
                ? HttpResponse::HTTP_MOVED_PERMANENTLY
                : HttpResponse::HTTP_FOUND;

            return redirect()->to($externalUrl, $statusCode);
        }

        // Increment view count only for published pages without preview token
        if ($landingPage->isPublished() && $previewToken === null) {
            $landingPage->incrementViewCount();
        }

        // Load resource with all necessary relationships
        $resource = Resource::with($transformer->requiredRelations())
            ->findOrFail($landingPage->resource_id);

        // Eager-load file and link entries for download URL display
        $landingPage->loadMissing(['files', 'links', 'landingPageTemplate']);

        $resourceData = $transformer->transform($resource);

        // Build section order and logo from custom template (if set)
        $sectionOrder = null;
        $customLogoUrl = null;

        if ($landingPage->landing_page_template_id !== null && $landingPage->landingPageTemplate !== null) {
            $tmpl = $landingPage->landingPageTemplate;
            $sectionOrder = [
                'rightColumn' => $tmpl->right_column_order,
                'leftColumn' => $tmpl->left_column_order,
            ];
            $customLogoUrl = $tmpl->logo_url;
        }

        // Generate Schema.org JSON-LD for inline SEO embedding (cached per resource)
        $cacheKey = CacheKey::SCHEMA_ORG_JSONLD->key($resource->id);
        $schemaOrgJsonLd = $this->getCacheInstance(CacheKey::SCHEMA_ORG_JSONLD->tags())
            ->remember($cacheKey, CacheKey::SCHEMA_ORG_JSONLD->ttl(), function () use ($resource): array {
                $exporter = new SchemaOrgJsonLdExporter;

                return $exporter->export($resource);
            });

        $data = [
            'resource' => $resourceData,
            'landingPage' => $landingPage->toArray(),
            'isPreview' => (bool) $previewToken,
            'schemaOrgJsonLd' => $schemaOrgJsonLd,
            'sectionOrder' => $sectionOrder,
            'customLogoUrl' => $customLogoUrl,
        ];

        // Use the template specified in landing page configuration
        $template = $landingPage->template ?? 'default_gfz';

        return Inertia::render("LandingPages/{$template}", $data);
    }

    /**
     * Validate slug format (defense in depth).
     *
     * Since route constraints should have already filtered invalid slugs,
     * reaching this point with an invalid slug indicates routing misconfiguration
     * or potential tampering. We return 404 instead of 500 because:
     * - The end result is the same (resource not found)
     * - 404 won't pollute error monitoring with false alarms
     * - We still log a warning to help debug routing issues
     *
     * @param  string  $slug  The slug to validate
     * @param  array<string, int|string>  $context  Additional context for logging (no sensitive data)
     */
    private function validateSlugFormat(string $slug, array $context = []): void
    {
        $pregResult = preg_match(self::SLUG_PATTERN, $slug);

        // Merge common slug metadata with caller-provided context.
        // Using hash instead of raw value to allow correlation without exposure.
        $logContext = array_merge($context, [
            'slug_length' => strlen($slug),
            'slug_hash' => substr(hash('sha256', $slug), 0, 8),
        ]);

        if ($pregResult === false) {
            // preg_match failed due to PCRE error - this is an internal error.
            \Illuminate\Support\Facades\Log::error(
                'LandingPagePublicController: preg_match failed with PCRE error',
                $logContext
            );
            abort(HttpResponse::HTTP_INTERNAL_SERVER_ERROR, 'Internal validation error');
        }

        if ($pregResult === 0) {
            // Slug doesn't match pattern - log warning but return 404 to user.
            // This avoids polluting error monitoring while still aiding debugging.
            \Illuminate\Support\Facades\Log::warning(
                'LandingPagePublicController: Invalid slug bypassed route constraint',
                $logContext
            );
            abort(HttpResponse::HTTP_NOT_FOUND, 'Landing page not found');
        }
    }

    /**
     * Validate DOI prefix format as defense in depth.
     *
     * The route constraint pattern is intentionally permissive to handle valid DOI
     * edge cases. This validation catches obviously malformed DOIs that the route
     * might allow, such as:
     * - Consecutive slashes: 10.5880//test
     * - Empty segments: 10.5880/test//suffix
     * - Leading/trailing dots that could cause parsing issues
     *
     * @param  string  $doiPrefix  The DOI prefix to validate
     */
    private function validateDoiPrefixFormat(string $doiPrefix): void
    {
        // Quick checks for common malformed patterns before full regex.
        // These are fast string operations that catch obvious issues.
        $quickCheckFailed = str_contains($doiPrefix, '//')
            || str_contains($doiPrefix, '/.')
            || str_contains($doiPrefix, './');

        if ($quickCheckFailed) {
            \Illuminate\Support\Facades\Log::warning(
                'LandingPagePublicController: Malformed DOI prefix (quick check)',
                [
                    'doi_prefix_length' => strlen($doiPrefix),
                    'doi_prefix_hash' => substr(hash('sha256', $doiPrefix), 0, 8),
                    'check_type' => 'quick',
                    'issue' => 'consecutive_slashes_or_dot_boundary',
                ]
            );
            abort(HttpResponse::HTTP_NOT_FOUND, 'Landing page not found');
        }

        // Full regex validation for DOI format
        $pregResult = preg_match(self::DOI_PREFIX_PATTERN, $doiPrefix);

        // Handle PCRE errors first (pregResult === false)
        if ($pregResult === false) {
            \Illuminate\Support\Facades\Log::error(
                'LandingPagePublicController: DOI prefix preg_match failed with PCRE error',
                ['doi_prefix_length' => strlen($doiPrefix)]
            );
            abort(HttpResponse::HTTP_INTERNAL_SERVER_ERROR, 'Internal validation error');
        }

        // Pattern didn't match (pregResult === 0) - DOI format is invalid.
        // Note: preg_match only returns false, 0, or 1. After checking for false above,
        // a value of 0 means no match. This is defense in depth - the route constraint
        // should catch most cases, but this protects against route bypasses.
        if ($pregResult === 0) {
            \Illuminate\Support\Facades\Log::warning(
                'LandingPagePublicController: Invalid DOI prefix (regex check)',
                [
                    'doi_prefix_length' => strlen($doiPrefix),
                    'doi_prefix_hash' => substr(hash('sha256', $doiPrefix), 0, 8),
                    'check_type' => 'regex',
                ]
            );
            abort(HttpResponse::HTTP_NOT_FOUND, 'Landing page not found');
        }
    }

    /**
     * Normalize preview token: treat empty string as null.
     * This ensures consistent null checks throughout the controller.
     *
     * Note: Empty strings should never come from legitimate requests (query params
     * are either absent or have a value). If this normalization triggers, it may
     * indicate a frontend bug sending empty strings instead of omitting the param.
     */
    private function normalizePreviewToken(?string $token): ?string
    {
        if ($token === '') {
            // Log in development to help identify potential frontend bugs.
            // Empty string preview tokens are unexpected from valid requests.
            if (config('app.debug')) {
                \Illuminate\Support\Facades\Log::debug(
                    'LandingPagePublicController: Empty preview token normalized to null',
                    ['possible_frontend_bug' => true]
                );
            }

            return null;
        }

        return $token;
    }
}
