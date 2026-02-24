<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\LandingPageDomain;
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
 * Access is restricted to users with 'access-editor-settings' gate
 * (Admin, Group Leader).
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
        $validated = $request->validate([
            'domain' => ['required', 'string', 'url', 'max:2048', 'unique:landing_page_domains,domain'],
        ]);

        // Normalize: ensure trailing slash
        $domain = $validated['domain'];
        if (! str_ends_with($domain, '/')) {
            $domain .= '/';
        }

        // Check uniqueness again after normalization
        if (LandingPageDomain::where('domain', $domain)->exists()) {
            return response()->json([
                'message' => 'This domain already exists.',
                'errors' => ['domain' => ['This domain already exists.']],
            ], 422);
        }

        $landingPageDomain = LandingPageDomain::create(['domain' => $domain]);

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
