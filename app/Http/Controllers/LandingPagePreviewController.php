<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\LandingPage\StoreLandingPagePreviewRequest;
use App\Models\LandingPage;
use App\Models\LandingPageTemplate;
use App\Models\Resource;
use App\Services\Citations\LandingPageCitationService;
use App\Services\LandingPageDocumentMetadataService;
use App\Services\LandingPageResourceTransformer;
use App\Services\LandingPageTemplateResolverService;
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
        $previewFiles = [];
        if (is_array($validated['files'] ?? null) && $resource->landingPage !== null) {
            $filesById = $resource->landingPage->files()
                ->whereIn('id', collect($validated['files'])->pluck('id')->filter()->all())
                ->get()
                ->keyBy('id');

            foreach ($validated['files'] as $fileData) {
                $file = $filesById->get((int) $fileData['id']);
                if ($file === null) {
                    continue;
                }

                $previewFiles[] = [
                    ...$file->toArray(),
                    'label' => $this->normalizeOptionalLabel(
                        array_key_exists('label', $fileData) ? $fileData['label'] : $file->label,
                    ),
                    'format_id' => $fileData['format_id'] ?? null,
                    'size_id' => $fileData['size_id'] ?? null,
                ];
            }
        }

        $effectiveFtpUrl = array_key_exists('ftp_url', $validated)
            ? $validated['ftp_url']
            : $resource->landingPage?->ftp_url;
        $effectivePrimaryDownloadLabel = array_key_exists('primary_download_label', $validated)
            ? $this->normalizeOptionalLabel($validated['primary_download_label'])
            : $resource->landingPage?->primary_download_label;

        Session::put($sessionKey, [
            'template' => $validated['template'],
            'landing_page_template_id' => LandingPageController::templateSupportsCustomTemplateId($validated['template'])
                ? ($validated['landing_page_template_id'] ?? null)
                : null,
            'ftp_url' => LandingPageController::templateSupportsFtpUrl($validated['template'])
                ? $effectiveFtpUrl
                : null,
            'primary_download_label' => LandingPageController::templateSupportsFtpUrl($validated['template'])
                && ! empty($effectiveFtpUrl)
                ? $effectivePrimaryDownloadLabel
                : null,
            'ftp_format_id' => LandingPageController::templateSupportsFtpUrl($validated['template'])
                ? ($validated['ftp_format_id'] ?? null)
                : null,
            'ftp_size_id' => LandingPageController::templateSupportsFtpUrl($validated['template'])
                ? ($validated['ftp_size_id'] ?? null)
                : null,
            'downloads_unavailable' => LandingPageController::templateSupportsDownloadsUnavailable($validated['template'])
                ? ($validated['downloads_unavailable'] ?? false)
                : false,
            'links' => $isLinksTemplate ? ($validated['links'] ?? []) : [],
            'files' => $previewFiles,
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
        LandingPageCitationService $citationService,
        LandingPageTemplateResolverService $templateResolver,
        LandingPageDocumentMetadataService $documentMetadataService,
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
        $documentMetadata = $documentMetadataService->resolve($resourceData, $template, true);

        $sectionOrder = null;
        $customLogoUrl = null;
        $landingPageTemplateId = LandingPageController::templateSupportsCustomTemplateId($template)
            && is_numeric($previewData['landing_page_template_id'] ?? null)
            ? (int) $previewData['landing_page_template_id']
            : null;

        $resolvedTemplate = $templateResolver->resolve($resource, $landingPageTemplateId);
        $templateConfig = $resolvedTemplate['template'];

        if ($templateConfig->template_type === LandingPageTemplate::TEMPLATE_TYPE_IGSN) {
            $igsnOrders = LandingPageTemplate::normalizeIgsnSectionOrders(
                $templateConfig->left_column_order,
                $templateConfig->right_column_order,
            );
            $sectionOrder = ['rightColumn' => $igsnOrders['right'], 'leftColumn' => $igsnOrders['left']];
        } else {
            $sectionOrder = [
                'rightColumn' => $templateConfig->right_column_order,
                'leftColumn' => LandingPageTemplate::normalizeLeftColumnOrder(
                    $templateConfig->left_column_order,
                    $templateConfig->template_type,
                ),
            ];
        }
        $customLogoUrl = $templateConfig->logo_url;

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
            'primary_download_label' => $ftpUrl !== null
                ? ($previewData['primary_download_label'] ?? null)
                : null,
            'ftp_format_id' => $previewData['ftp_format_id'] ?? null,
            'ftp_size_id' => $previewData['ftp_size_id'] ?? null,
            'downloads_unavailable' => $downloadsUnavailable,
            'files' => is_array($previewData['files'] ?? null) ? $previewData['files'] : [],
            'links' => $links,
            'status' => 'preview',
            'preview_token' => null,
            'published_at' => null,
            'view_count' => 0,
        ];

        return Inertia::render("LandingPages/{$template}", [
            'resource' => $resourceData,
            'documentTitle' => $documentMetadata['title'],
            'citationStyles' => $citationService->format($resource),
            'landingPage' => $tempLandingPage,
            'isPreview' => true,
            'sectionOrder' => $sectionOrder,
            'customLogoUrl' => $customLogoUrl,
            'landingPageTemplateSource' => $resolvedTemplate['source'],
            'effectiveLandingPageTemplate' => $templateConfig->only(['id', 'name', 'slug']),
            'displayLimits' => [
                'creators' => $templateConfig->creator_display_limit,
                'contributors' => $templateConfig->contributor_display_limit,
                'citationAuthors' => $templateConfig->citation_author_display_limit,
            ],
        ])->withViewData([
            'landingPageDocumentMetadata' => $documentMetadata,
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

    private function normalizeOptionalLabel(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $label = trim($value);

        return $label === '' ? null : $label;
    }
}
