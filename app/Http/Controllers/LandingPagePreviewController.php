<?php

namespace App\Http\Controllers;

use App\Models\Resource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Handles temporary landing page previews stored in session
 */
class LandingPagePreviewController extends Controller
{
    /**
     * Store preview data in session and redirect to preview
     */
    public function store(Request $request, Resource $resource): Response
    {
        $validated = $request->validate([
            'template' => 'required|string',
            'ftp_url' => 'nullable|url|max:2048',
        ]);

        // Store preview data in session
        $sessionKey = "landing_page_preview.{$resource->id}";
        Session::put($sessionKey, [
            'template' => $validated['template'],
            'ftp_url' => $validated['ftp_url'] ?? null,
            'resource_id' => $resource->id,
        ]);

        // Return preview page
        return $this->show($resource);
    }

    /**
     * Show temporary preview from session
     */
    public function show(Resource $resource): Response
    {
        $sessionKey = "landing_page_preview.{$resource->id}";
        $previewData = Session::get($sessionKey);

        if (! $previewData) {
            abort(404, 'Preview session expired. Please open preview again from the setup modal.');
        }

        // Load resource with all relationships
        $resource->load([
            'titles',
            'resourceType',
            'language',
            'authors.authorable',
            'authors.roles',
            'authors.affiliations',
            'licenses',
            'descriptions',
            'dates',
            'keywords',
            'controlledKeywords',
            'coverages',
            'fundingReferences',
            'relatedIdentifiers',
        ]);

        // Create temporary landing page object
        $tempLandingPage = (object) [
            'id' => null,
            'resource_id' => $resource->id,
            'template' => $previewData['template'],
            'ftp_url' => $previewData['ftp_url'],
            'status' => 'preview',
            'preview_token' => null,
            'published_at' => null,
            'view_count' => 0,
        ];

        return Inertia::render("LandingPages/{$previewData['template']}", [
            'resource' => $resource,
            'landingPage' => $tempLandingPage,
            'isPreview' => true,
        ]);
    }

    /**
     * Clear preview session
     */
    public function destroy(Resource $resource): JsonResponse
    {
        $sessionKey = "landing_page_preview.{$resource->id}";
        Session::forget($sessionKey);

        return response()->json([
            'message' => 'Preview session cleared',
        ]);
    }
}
