<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\LandingPageDomain;
use App\Rules\SafeDomainUrl;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manages landing page domains for external landing page URLs.
 *
 * Domains are used in the "Setup Landing Page" modal when curators select
 * the "External Landing Page" template. The external URL is composed from
 * a domain (managed here) and a free-text path.
 *
 * Access:
 * - Listing (index): available to all authenticated users (used by
 *   the SetupLandingPageModal for curators via /api/landing-page-domains/list).
 * - Create/Delete (store, destroy): restricted to users with 'access-editor-settings'
 *   gate (Admin, Group Leader) via route middleware.
 */
class LandingPageDomainController extends Controller
{
    use AuthorizesRequests;

    /**
     * List all landing page domains.
     */
    public function index(): JsonResponse
    {
        $domains = LandingPageDomain::orderBy('domain')->get(['id', 'domain']);

        return response()->json(['domains' => $domains]);
    }

    /**
     * Store a new landing page domain.
     */
    public function store(Request $request): JsonResponse
    {
        // Normalize before validation: trim whitespace and ensure trailing slash
        // so that max:2048 and unique checks apply to the stored form.
        $domain = trim((string) $request->input('domain'));
        if ($domain !== '' && ! str_ends_with($domain, '/')) {
            $domain .= '/';
        }
        $request->merge(['domain' => $domain]);

        $validated = $request->validate([
            'domain' => ['required', 'string', new SafeDomainUrl, 'max:768', 'unique:landing_page_domains,domain'],
        ]);

        // Use try/catch to handle the race condition where another request
        // inserts the same normalized domain between validation and insert.
        // The unique DB constraint on the 'domain' column guarantees atomicity.
        try {
            $landingPageDomain = LandingPageDomain::create(['domain' => $domain]);
        } catch (QueryException $e) {
            // Detect unique constraint violation via errorInfo vendor codes
            // (consistent with LandingPageController pattern):
            // MySQL: errorInfo[1] = 1062 (ER_DUP_ENTRY)
            // SQLite: errorInfo[1] = 19 (SQLITE_CONSTRAINT)
            $errorCode = (int) ($e->errorInfo[1] ?? 0);

            if ($errorCode === 1062 || ($errorCode === 19 && str_contains($e->getMessage(), 'UNIQUE constraint'))) {
                return response()->json([
                    'message' => 'This domain already exists.',
                    'errors' => ['domain' => ['This domain already exists.']],
                ], 422);
            }
            throw $e;
        }

        return response()->json([
            'message' => 'Domain added successfully.',
            'domain' => $landingPageDomain,
        ], 201);
    }

    /**
     * Delete a landing page domain.
     *
     * Fails if the domain is still referenced by any landing page
     * (defense-in-depth on top of the database restrictOnDelete constraint).
     */
    public function destroy(LandingPageDomain $landing_page_domain): JsonResponse
    {
        $usageCount = $landing_page_domain->landingPages()->count();

        if ($usageCount > 0) {
            return response()->json([
                'message' => "Cannot delete domain. It is still used by {$usageCount} landing page(s).",
                'error' => 'domain_in_use',
            ], 422);
        }

        $landing_page_domain->delete();

        return response()->json([
            'message' => 'Domain deleted successfully.',
        ]);
    }
}
