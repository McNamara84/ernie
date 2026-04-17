<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreLandingPageTemplateRequest;
use App\Http\Requests\UpdateLandingPageTemplateRequest;
use App\Models\LandingPageTemplate;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class LandingPageTemplateController extends Controller
{
    use AuthorizesRequests;

    /**
     * Maximum logo file size in kilobytes.
     */
    private const MAX_LOGO_SIZE_KB = 2048;

    /**
     * Allowed MIME types for logo uploads.
     *
     * @var list<string>
     */
    private const ALLOWED_LOGO_MIMES = ['png', 'jpg', 'jpeg', 'svg', 'webp'];

    /**
     * Display the template management page.
     */
    public function index(): Response
    {
        $this->authorize('viewAny', LandingPageTemplate::class);

        $templates = LandingPageTemplate::query()
            ->with('creator:id,name')
            ->withCount('landingPages')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return Inertia::render('landing-page-templates', [
            'templates' => $templates,
        ]);
    }

    /**
     * Clone the default template with a new name.
     */
    public function store(StoreLandingPageTemplateRequest $request): JsonResponse
    {
        $this->authorize('create', LandingPageTemplate::class);

        $validated = $request->validated();

        $defaultTemplate = LandingPageTemplate::where('is_default', true)->firstOrFail();

        $template = LandingPageTemplate::create([
            'name' => $validated['name'],
            'slug' => Str::slug($validated['name']) . '-' . Str::random(6),
            'is_default' => false,
            'logo_path' => null,
            'logo_filename' => null,
            'right_column_order' => $defaultTemplate->right_column_order,
            'left_column_order' => $defaultTemplate->left_column_order,
            'created_by' => $request->user()?->id,
        ]);

        $template->loadCount('landingPages');

        return response()->json([
            'message' => 'Template created successfully',
            'template' => $template,
        ], 201);
    }

    /**
     * Update a custom template (name, section order).
     */
    public function update(UpdateLandingPageTemplateRequest $request, LandingPageTemplate $landingPageTemplate): JsonResponse
    {
        $this->authorize('update', $landingPageTemplate);

        if ($landingPageTemplate->isDefault()) {
            return response()->json([
                'message' => 'The default template cannot be modified.',
                'error' => 'default_template_immutable',
            ], 403);
        }

        $validated = $request->validated();

        $updateData = [];

        if (isset($validated['name'])) {
            $updateData['name'] = $validated['name'];
        }

        if (isset($validated['right_column_order'])) {
            $updateData['right_column_order'] = $validated['right_column_order'];
        }

        if (isset($validated['left_column_order'])) {
            $updateData['left_column_order'] = $validated['left_column_order'];
        }

        $landingPageTemplate->update($updateData);
        $landingPageTemplate->loadCount('landingPages');

        return response()->json([
            'message' => 'Template updated successfully',
            'template' => $landingPageTemplate,
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
                'message' => 'This template is currently in use by ' . $landingPageTemplate->getUsageCount() . ' landing page(s) and cannot be deleted.',
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
    public function uploadLogo(Request $request, LandingPageTemplate $landingPageTemplate): JsonResponse
    {
        $this->authorize('update', $landingPageTemplate);

        if ($landingPageTemplate->isDefault()) {
            return response()->json([
                'message' => 'The default template cannot be modified.',
                'error' => 'default_template_immutable',
            ], 403);
        }

        $request->validate([
            'logo' => ['required', 'file', 'mimes:' . implode(',', self::ALLOWED_LOGO_MIMES), 'max:' . self::MAX_LOGO_SIZE_KB],
        ]);

        $file = $request->file('logo');

        if ($file === null) {
            return response()->json(['message' => 'No file provided'], 422);
        }

        $oldLogoPath = $landingPageTemplate->logo_path;

        // Store new file first to avoid losing the old logo on failure
        $directory = 'landing-page-logos/' . $landingPageTemplate->slug;

        try {
            $path = $file->store($directory, 'public');
        } catch (\Throwable) {
            return response()->json(['message' => 'Failed to store logo file'], 500);
        }

        $landingPageTemplate->update([
            'logo_path' => $path,
            'logo_filename' => $file->getClientOriginalName(),
        ]);

        // Delete old logo only after new one is persisted
        if ($oldLogoPath !== null) {
            Storage::disk('public')->delete($oldLogoPath);
        }

        return response()->json([
            'message' => 'Logo uploaded successfully',
            'template' => $landingPageTemplate,
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

        return response()->json([
            'message' => 'Logo removed successfully',
            'template' => $landingPageTemplate,
        ]);
    }

    /**
     * List all templates for API dropdown.
     * Accessible to all authenticated users (needed for template selection in SetupLandingPageModal).
     */
    public function list(): JsonResponse
    {
        $templates = LandingPageTemplate::query()
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'is_default', 'logo_path', 'right_column_order', 'left_column_order']);

        return response()->json([
            'templates' => $templates,
        ]);
    }
}
