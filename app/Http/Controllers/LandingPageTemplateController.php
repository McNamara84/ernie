<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\LandingPageTemplate\UploadLandingPageTemplateLogoRequest;
use App\Http\Requests\StoreLandingPageTemplateRequest;
use App\Http\Requests\UpdateLandingPageTemplateRequest;
use App\Models\LandingPageTemplate;
use App\Services\BotProtection\LandingPageRenderDataCacheService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class LandingPageTemplateController extends Controller
{
    use AuthorizesRequests;

    /**
     * Display the template management page.
     */
    public function index(): Response
    {
        $this->authorize('viewAny', LandingPageTemplate::class);

        // Ensure both system-owned default templates (resource + IGSN) exist.
        LandingPageTemplate::ensureSystemTemplatesExist();

        $templates = LandingPageTemplate::query()
            ->orderedForDisplay()
            ->with('creator:id,name')
            ->withCount('landingPages')
            ->get();

        return Inertia::render('landing-page-templates', [
            'templates' => $this->serializeTemplates($templates),
        ]);
    }

    /**
     * Clone the default template (of the requested type) with a new name.
     */
    public function store(StoreLandingPageTemplateRequest $request): JsonResponse
    {
        $this->authorize('create', LandingPageTemplate::class);

        $validated = $request->validated();

        $templateType = $validated['template_type'] ?? LandingPageTemplate::TEMPLATE_TYPE_RESOURCE;
        $defaultTemplate = LandingPageTemplate::defaultForType($templateType);

        $template = LandingPageTemplate::create([
            'name' => $validated['name'],
            'slug' => Str::slug($validated['name']).'-'.Str::random(6),
            'is_default' => false,
            'template_type' => $templateType,
            'logo_path' => null,
            'logo_filename' => null,
            'right_column_order' => $defaultTemplate->right_column_order,
            'left_column_order' => $defaultTemplate->left_column_order,
            'creator_display_limit' => $defaultTemplate->creator_display_limit,
            'contributor_display_limit' => $defaultTemplate->contributor_display_limit,
            'created_by' => $request->user()?->id,
        ]);

        $template->loadCount('landingPages');

        return response()->json([
            'message' => 'Template created successfully',
            'template' => $this->serializeTemplate($template),
        ], 201);
    }

    /**
     * Update a custom template (name, section order).
     */
    public function update(UpdateLandingPageTemplateRequest $request, LandingPageTemplate $landingPageTemplate): JsonResponse
    {
        $this->authorize('update', $landingPageTemplate);

        $validated = $request->validated();

        if ($landingPageTemplate->isDefault()) {
            $editableDefaultFields = ['creator_display_limit', 'contributor_display_limit'];
            $unsupportedFields = array_diff(array_keys($validated), $editableDefaultFields);

            if ($unsupportedFields !== []) {
                return response()->json([
                    'message' => 'Only creator and contributor display limits can be updated on default templates.',
                    'error' => 'default_template_immutable',
                ], 403);
            }
        }

        $updateData = [];

        if (! $landingPageTemplate->isDefault() && isset($validated['name'])) {
            $updateData['name'] = $validated['name'];
        }

        if (! $landingPageTemplate->isDefault() && isset($validated['right_column_order'])) {
            $updateData['right_column_order'] = $validated['right_column_order'];
        }

        if (! $landingPageTemplate->isDefault() && isset($validated['left_column_order'])) {
            $updateData['left_column_order'] = $validated['left_column_order'];
        }

        if (isset($validated['creator_display_limit'])) {
            $updateData['creator_display_limit'] = $validated['creator_display_limit'];
        }

        if (isset($validated['contributor_display_limit'])) {
            $updateData['contributor_display_limit'] = $validated['contributor_display_limit'];
        }

        $landingPageTemplate->fill($updateData);

        if ($landingPageTemplate->isDirty()) {
            $landingPageTemplate->save();
            app(LandingPageRenderDataCacheService::class)->flush();
        }

        $landingPageTemplate->loadCount('landingPages');

        return response()->json([
            'message' => 'Template updated successfully',
            'template' => $this->serializeTemplate($landingPageTemplate),
        ]);
    }

    /**
     * Delete a custom template.
     */
    public function destroy(LandingPageTemplate $landingPageTemplate): JsonResponse
    {
        $this->authorize('delete', $landingPageTemplate);

        if ($landingPageTemplate->isDefault()) {
            return response()->json([
                'message' => 'The default template cannot be deleted.',
                'error' => 'default_template_immutable',
            ], 403);
        }

        if ($landingPageTemplate->isInUse()) {
            return response()->json([
                'message' => 'This template is currently in use by '.$landingPageTemplate->getUsageCount().' landing page(s) and cannot be deleted.',
                'error' => 'template_in_use',
            ], 422);
        }

        // Delete logo file if exists
        if ($landingPageTemplate->logo_path !== null) {
            Storage::disk('public')->delete($landingPageTemplate->logo_path);
        }

        $landingPageTemplate->delete();

        return response()->json([
            'message' => 'Template deleted successfully',
        ]);
    }

    /**
     * Upload a custom logo for a template.
     */
    public function uploadLogo(UploadLandingPageTemplateLogoRequest $request, LandingPageTemplate $landingPageTemplate): JsonResponse
    {
        $this->authorize('update', $landingPageTemplate);

        if ($landingPageTemplate->isDefault()) {
            return response()->json([
                'message' => 'The default template cannot be modified.',
                'error' => 'default_template_immutable',
            ], 403);
        }

        $request->validated();

        $file = $request->file('logo');

        if ($file === null) {
            return response()->json(['message' => 'No file provided'], 422);
        }

        $oldLogoPath = $landingPageTemplate->logo_path;

        // Store new file first to avoid losing the old logo on failure
        $directory = 'landing-page-logos/'.$landingPageTemplate->slug;

        try {
            $path = $file->store($directory, 'public');
        } catch (\Throwable) {
            return response()->json(['message' => 'Failed to store logo file'], 500);
        }

        $landingPageTemplate->update([
            'logo_path' => $path,
            'logo_filename' => $file->getClientOriginalName(),
        ]);
        app(LandingPageRenderDataCacheService::class)->flush();

        // Delete old logo only after new one is persisted
        if ($oldLogoPath !== null) {
            Storage::disk('public')->delete($oldLogoPath);
        }

        return response()->json([
            'message' => 'Logo uploaded successfully',
            'template' => $this->serializeTemplate($landingPageTemplate),
        ]);
    }

    /**
     * Remove the custom logo and revert to default GFZ logo.
     */
    public function deleteLogo(LandingPageTemplate $landingPageTemplate): JsonResponse
    {
        $this->authorize('update', $landingPageTemplate);

        if ($landingPageTemplate->isDefault()) {
            return response()->json([
                'message' => 'The default template cannot be modified.',
                'error' => 'default_template_immutable',
            ], 403);
        }

        if ($landingPageTemplate->logo_path !== null) {
            Storage::disk('public')->delete($landingPageTemplate->logo_path);
        }

        $landingPageTemplate->update([
            'logo_path' => null,
            'logo_filename' => null,
        ]);
        app(LandingPageRenderDataCacheService::class)->flush();

        return response()->json([
            'message' => 'Logo removed successfully',
            'template' => $this->serializeTemplate($landingPageTemplate),
        ]);
    }

    /**
     * List all templates for API dropdown.
     * Accessible to all authenticated users (needed for template selection in SetupLandingPageModal).
     */
    public function list(): JsonResponse
    {
        // Ensure both system-owned default templates (resource + IGSN) exist
        // so the SetupLandingPageModal always has the canonical defaults
        // available for selection / filtering.
        LandingPageTemplate::ensureSystemTemplatesExist();

        $templates = LandingPageTemplate::query()
            ->orderedForDisplay()
            ->get([
                'id',
                'name',
                'slug',
                'is_default',
                'template_type',
                'logo_path',
                'right_column_order',
                'left_column_order',
                'creator_display_limit',
                'contributor_display_limit',
            ]);

        return response()->json([
            'templates' => $this->serializeTemplates($templates),
        ]);
    }

    /**
     * @param  iterable<LandingPageTemplate>  $templates
     * @return list<array<string, mixed>>
     */
    private function serializeTemplates(iterable $templates): array
    {
        $serialized = [];

        foreach ($templates as $template) {
            $serialized[] = $this->serializeTemplate($template);
        }

        return $serialized;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeTemplate(LandingPageTemplate $template): array
    {
        $payload = $template->toArray();
        $payload['left_column_order'] = LandingPageTemplate::normalizeLeftColumnOrder(
            $template->left_column_order,
            $template->template_type,
        );

        return $payload;
    }
}
