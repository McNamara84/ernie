<?php

namespace App\Http\Controllers;

use App\Models\Resource;
use App\Services\LandingPageResourceTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Handles temporary landing page previews stored in session
 */
class LandingPagePreviewController extends Controller
{
    /**
     * When adding a new landing page template, update this list.
     * The value must match an Inertia page under resources/js/Pages/LandingPages/.
     *
     * @var array<int, string>
     */
    private const ALLOWED_TEMPLATES = [
        'default_gfz',
    ];

    /**
    * Store preview data in session and return a preview URL
     */
    public function store(Request $request, Resource $resource): JsonResponse
    {
        $validated = $request->validate([
            'template' => ['required', 'string', Rule::in(self::ALLOWED_TEMPLATES)],
            'ftp_url' => 'nullable|url|max:2048',
        ]);

        // Store preview data in session
        $sessionKey = "landing_page_preview.{$resource->id}";
        Session::put($sessionKey, [
            'template' => $validated['template'],
            'ftp_url' => $validated['ftp_url'] ?? null,
            'resource_id' => $resource->id,
        ]);

        return response()->json([
            'preview_url' => route('landing-page.preview.show', ['resource' => $resource->id]),
        ], 201);
    }

    /**
     * Show temporary preview from session
     */
    public function show(Resource $resource, LandingPageResourceTransformer $transformer): Response
    {
        $sessionKey = "landing_page_preview.{$resource->id}";
        $previewData = Session::get($sessionKey);

        if (! $previewData) {
            abort(404, 'Preview session expired. Please open preview again from the setup modal.');
        }

        if (! is_array($previewData)) {
            abort(404, 'Preview session is invalid. Please open preview again from the setup modal.');
        }

        $template = is_string($previewData['template'] ?? null) ? $previewData['template'] : 'default_gfz';
        if (! in_array($template, self::ALLOWED_TEMPLATES, true)) {
            $template = 'default_gfz';
        }

        // Load the same shape used for public landing pages, because the React template expects it.
        $resource->load($transformer->requiredRelations());

        // Prepare the same frontend payload as LandingPagePublicController
        $resourceData = $transformer->transform($resource);

        // Temporary landing page array for preview rendering.
        $tempLandingPage = [
            'id' => null,
            'resource_id' => $resource->id,
            'template' => $template,
            'ftp_url' => $previewData['ftp_url'] ?? null,
            'status' => 'preview',
            'preview_token' => null,
            'published_at' => null,
            'view_count' => 0,
        ];

        return Inertia::render("LandingPages/{$template}", [
            'resource' => $resourceData,
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
