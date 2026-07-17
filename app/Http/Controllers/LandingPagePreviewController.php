<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\LandingPage\StoreLandingPagePreviewRequest;
use App\Models\LandingPage;
use App\Models\LandingPageTemplate;
use App\Models\Resource;
use App\Services\Citations\LandingPageCitationService;
use App\Services\LandingPageResourceTransformer;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Session;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Handles temporary landing page previews stored in session.
 *
 * This controller allows users to preview landing page configurations
 * before saving them. The preview data is stored in the session and
 * can be used to test different templates or FTP URLs.
 *
 * Authorization: Users who can create landing pages can create previews,
 * consistent with LandingPageController restrictions.
 */
class LandingPagePreviewController extends Controller
{
    use AuthorizesRequests;

    /**
     * Store preview data in session and return a preview URL
     */
    public function store(StoreLandingPagePreviewRequest $request, Resource $resource): JsonResponse
    {
        $this->authorize('create', LandingPage::class);

        $validated = $request->validated();

        // External templates don't have a renderable preview — the frontend opens the external URL directly
        if ($validated['template'] === 'external') {
            return response()->json([
                'message' => 'External landing pages do not support session-based previews.',
                'error' => 'external_not_previewable',
            ], 422);
        }

        $resource->loadMissing('resourceType');

        if ($templateError = LandingPageTemplate::builtInTemplateScopeError($validated['template'], $resource->resourceType?->slug)) {
            return response()->json([
                'message' => $templateError,
                'error' => 'invalid_template_for_resource_type',
            ], 422);
        }

        if (LandingPageController::templateSupportsCustomTemplateId($validated['template'])
            && ($customTemplateError = LandingPageTemplate::customTemplateScopeError($validated['landing_page_template_id'] ?? null, $resource->resourceType?->slug))) {
            return response()->json([
                'message' => $customTemplateError,
                'error' => 'invalid_template_for_resource_type',
            ], 422);
        }

        // Store preview data in session
        $sessionKey = "landing_page_preview.{$resource->id}";

        // Only include links for templates that support them.
        // Note: external templates already returned early above, so we only check IGSN here.
        $isLinksTemplate = ! in_array($validated['template'], LandingPageController::IGSN_ONLY_TEMPLATES, true);

        Session::put($sessionKey, [
            'template' => $validated['template'],
            'landing_page_template_id' => LandingPageController::templateSupportsCustomTemplateId($validated['template'])
                ? ($validated['landing_page_template_id'] ?? null)
                : null,
            'ftp_url' => LandingPageController::templateSupportsFtpUrl($validated['template'])
                ? ($validated['ftp_url'] ?? null)
                : null,
            'downloads_unavailable' => LandingPageController::templateSupportsDownloadsUnavailable($validated['template'])
                ? ($validated['downloads_unavailable'] ?? false)
                : false,
            'links' => $isLinksTemplate ? ($validated['links'] ?? []) : [],
            'resource_id' => $resource->id,
        ]);

        return response()->json([
            'preview_url' => route('landing-page.preview.show', ['resource' => $resource->id]),
        ], 201);
    }

    /**
     * Show temporary preview from session
     */
    public function show(
        Resource $resource,
        LandingPageResourceTransformer $transformer,
        LandingPageCitationService $citationService
    ): Response {
        $sessionKey = "landing_page_preview.{$resource->id}";
        $previewData = Session::get($sessionKey);

        if (! $previewData) {
            abort(404, 'Preview session expired. Please open preview again from the setup modal.');
        }

        if (! is_array($previewData)) {
            abort(404, 'Preview session is invalid. Please open preview again from the setup modal.');
        }

        $rawTemplate = is_string($previewData['template'] ?? null) ? $previewData['template'] : LandingPageTemplate::DEFAULT_TEMPLATE_SLUG;
        if ($rawTemplate === 'external') {
            abort(404, 'External landing pages do not support session-based previews. Please open the external URL directly from the setup modal.');
        }

        if (! in_array($rawTemplate, LandingPageController::ALLOWED_TEMPLATES, true)) {
            $rawTemplate = LandingPageTemplate::DEFAULT_TEMPLATE_SLUG;
        }

        // Load the same shape used for public landing pages, because the React template expects it.
        $resource->load(array_unique([
            ...$transformer->requiredRelations(),
            'resourceType',
        ]));
        $resourceTypeSlug = $resource->resourceType?->slug;
        $template = LandingPageTemplate::normalizeBuiltInTemplateForResource($rawTemplate, $resourceTypeSlug);

        // Prepare the same frontend payload as LandingPagePublicController
        $resourceData = $transformer->transform($resource);

        $sectionOrder = null;
        $customLogoUrl = null;
        $landingPageTemplateId = LandingPageController::templateSupportsCustomTemplateId($template)
            && is_int($previewData['landing_page_template_id'] ?? null)
            ? $previewData['landing_page_template_id']
            : null;

        $templateConfig = LandingPageTemplate::resolveCustomTemplate($landingPageTemplateId, $resourceTypeSlug);

        if ($templateConfig !== null) {
            $landingPageTemplateId = $templateConfig->id;
            $sectionOrder = [
                'rightColumn' => $templateConfig->right_column_order,
                'leftColumn' => LandingPageTemplate::normalizeLeftColumnOrder($templateConfig->left_column_order, $templateConfig->template_type),
            ];
            $customLogoUrl = $templateConfig->logo_url;
        } else {
            $landingPageTemplateId = null;
        }

        $expectedTemplateType = LandingPageTemplate::expectedTemplateTypeForResource($resourceTypeSlug);
        $displayLimitTemplate = $templateConfig
            ?? LandingPageTemplate::existingDefaultForType($expectedTemplateType)
            ?? LandingPageTemplate::defaultForType($expectedTemplateType);

        $downloadsUnavailable = LandingPageController::templateSupportsDownloadsUnavailable($template)
            && ($previewData['downloads_unavailable'] ?? false) === true;
        $ftpUrl = LandingPageController::templateSupportsFtpUrl($template) && ! $downloadsUnavailable
            ? ($previewData['ftp_url'] ?? null)
            : null;
        $links = $template !== LandingPageTemplate::IGSN_DEFAULT_TEMPLATE_SLUG
            && ! $downloadsUnavailable
            && is_array($previewData['links'] ?? null)
            ? $previewData['links']
            : [];

        // Temporary landing page array for preview rendering.
        // Note: contact_url is not included here because it's computed from the public_url
        // in the LandingPage model. For previews, the ContactSection uses the resource's
        // contact_persons data directly without needing the contact form URL.
        $tempLandingPage = [
            'id' => null,
            'resource_id' => $resource->id,
            'template' => $template,
            'landing_page_template_id' => $landingPageTemplateId,
            'ftp_url' => $ftpUrl,
            'downloads_unavailable' => $downloadsUnavailable,
            'files' => [],
            'links' => $links,
            'status' => 'preview',
            'preview_token' => null,
            'published_at' => null,
            'view_count' => 0,
        ];

        return Inertia::render("LandingPages/{$template}", [
            'resource' => $resourceData,
            'citationStyles' => $citationService->format($resource),
            'landingPage' => $tempLandingPage,
            'isPreview' => true,
            'sectionOrder' => $sectionOrder,
            'customLogoUrl' => $customLogoUrl,
            'displayLimits' => [
                'creators' => $displayLimitTemplate->creator_display_limit,
                'contributors' => $displayLimitTemplate->contributor_display_limit,
                'citationAuthors' => $displayLimitTemplate->citation_author_display_limit,
            ],
        ]);
    }

    /**
     * Clear preview session
     */
    public function destroy(Resource $resource): JsonResponse
    {
        $this->authorize('create', LandingPage::class);

        $sessionKey = "landing_page_preview.{$resource->id}";
        Session::forget($sessionKey);

        return response()->json([
            'message' => 'Preview session cleared',
        ]);
    }
}
