<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\LandingPageTemplate\UploadLandingPageTemplateLogoRequest;
use App\Http\Requests\StoreLandingPageTemplateRequest;
use App\Http\Requests\UpdateLandingPageTemplateRequest;
use App\Models\Datacenter;
use App\Models\LandingPageTemplate;
use App\Models\Resource;
use App\Services\BotProtection\LandingPageRenderDataCacheService;
use App\Services\LandingPageTemplateResolverService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
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
            ->with([
                'creator:id,name',
                'datacenters:id,name,landing_page_template_id',
            ])
            ->withCount(['landingPages', 'datacenters'])
            ->get();

        return Inertia::render('landing-page-templates', [
            'templates' => $this->serializeTemplates($templates),
            'datacenters' => Datacenter::query()
                ->orderBy('name')
                ->with('landingPageTemplate:id,name')
                ->get(['id', 'name', 'landing_page_template_id'])
                ->map(fn (Datacenter $datacenter): array => [
                    'id' => $datacenter->id,
                    'name' => $datacenter->name,
                    'landing_page_template_id' => $datacenter->landing_page_template_id,
                    'landing_page_template_name' => $datacenter->landingPageTemplate?->name,
                ]),
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

        $template = DB::transaction(function () use ($validated, $templateType, $defaultTemplate, $request): LandingPageTemplate {
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
                'citation_author_display_limit' => $defaultTemplate->citation_author_display_limit,
                'created_by' => $request->user()?->id,
            ]);

            $this->syncDatacenters($template, $validated['datacenter_ids'] ?? []);

            return $template;
        });

        $this->loadTemplateUsage($template);

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
            $editableDefaultFields = ['creator_display_limit', 'contributor_display_limit', 'citation_author_display_limit', 'datacenter_ids'];
            $unsupportedFields = array_diff(array_keys($validated), $editableDefaultFields);

            if ($unsupportedFields !== []) {
                return response()->json([
                    'message' => 'Only display limits and datacenter assignments can be updated on default templates.',
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

        if (isset($validated['citation_author_display_limit'])) {
            $updateData['citation_author_display_limit'] = $validated['citation_author_display_limit'];
        }

        DB::transaction(function () use ($landingPageTemplate, $updateData, $validated): void {
            $landingPageTemplate->fill($updateData);

            if ($landingPageTemplate->isDirty()) {
                $landingPageTemplate->save();
                DB::afterCommit(
                    fn () => app(LandingPageRenderDataCacheService::class)->forgetForTemplate($landingPageTemplate),
                );
            }

            if (array_key_exists('datacenter_ids', $validated)) {
                $this->syncDatacenters($landingPageTemplate, $validated['datacenter_ids']);
            }
        });

        $this->loadTemplateUsage($landingPageTemplate);

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

        $landingPageUsage = $landingPageTemplate->getUsageCount();
        if ($landingPageUsage > 0) {
            return response()->json([
                'message' => 'This template is currently in use by '.$landingPageUsage.' landing page(s) and cannot be deleted.',
                'error' => 'template_in_use',
            ], 422);
        }

        $datacenterUsage = $landingPageTemplate->getDatacenterUsageCount();
        if ($datacenterUsage > 0) {
            return response()->json([
                'message' => 'This template is assigned to '.$datacenterUsage.' datacenter(s) and cannot be deleted.',
                'error' => 'template_assigned_to_datacenters',
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
        app(LandingPageRenderDataCacheService::class)->forgetForTemplate($landingPageTemplate);

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
        app(LandingPageRenderDataCacheService::class)->forgetForTemplate($landingPageTemplate);

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
            ->with('datacenters:id,name,landing_page_template_id')
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
                'citation_author_display_limit',
            ]);

        return response()->json([
            'templates' => $this->serializeTemplates($templates),
        ]);
    }

    /**
     * Return the templates and automatic inheritance context for one resource.
     */
    public function options(Resource $resource, LandingPageTemplateResolverService $resolver): JsonResponse
    {
        $this->authorize('view', $resource);

        LandingPageTemplate::ensureSystemTemplatesExist();
        $resource->loadMissing(['resourceType:id,slug', 'datacenter.landingPageTemplate']);
        $expectedType = LandingPageTemplate::expectedTemplateTypeForResource($resource->resourceType?->slug);
        $supportsDatacenterInheritance = $expectedType === LandingPageTemplate::TEMPLATE_TYPE_RESOURCE;
        $automatic = $resolver->automatic($resource);
        $systemDefault = LandingPageTemplate::defaultForType($expectedType);
        $datacenterTemplate = $supportsDatacenterInheritance
            && $resource->datacenter?->landingPageTemplate?->template_type === LandingPageTemplate::TEMPLATE_TYPE_RESOURCE
                ? $resource->datacenter->landingPageTemplate
                : null;

        $templates = LandingPageTemplate::query()
            ->where('template_type', $expectedType)
            ->orderedForDisplay()
            ->get()
            ->map(fn (LandingPageTemplate $template): array => [
                'id' => $template->id,
                'name' => $template->name,
                'slug' => $template->slug,
                'is_default' => $template->is_default,
                'template_type' => $template->template_type,
                'logo_url' => $template->logo_url,
            ])
            ->values();

        return response()->json([
            'templates' => $templates,
            'datacenter' => $resource->datacenter?->only(['id', 'name']),
            'datacenter_template' => $datacenterTemplate?->only(['id', 'name', 'slug']),
            'system_default' => $systemDefault->only(['id', 'name', 'slug']),
            'automatic_template' => $automatic['template']->only(['id', 'name', 'slug']),
            'automatic_source' => $automatic['source'],
            'supports_datacenter_inheritance' => $supportsDatacenterInheritance,
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
        $payload['datacenters'] = $template->relationLoaded('datacenters')
            ? $template->datacenters->map(fn (Datacenter $datacenter): array => [
                'id' => $datacenter->id,
                'name' => $datacenter->name,
            ])->values()->all()
            : [];
        $payload['datacenters_count'] = $template->getAttribute('datacenters_count')
            ?? count($payload['datacenters']);
        $payload['left_column_order'] = LandingPageTemplate::normalizeLeftColumnOrder(
            $template->left_column_order,
            $template->template_type,
        );

        return $payload;
    }

    /**
     * Assign any number of datacenters to a regular template.
     *
     * Assigning a datacenter already used by another template moves it. The
     * canonical GFZ assignment is always retained on the resource default.
     *
     * @param  list<int>  $datacenterIds
     */
    private function syncDatacenters(LandingPageTemplate $template, array $datacenterIds): void
    {
        if ($template->template_type !== LandingPageTemplate::TEMPLATE_TYPE_RESOURCE) {
            return;
        }

        $selectedIds = array_values(array_unique(array_map('intval', $datacenterIds)));

        if ($template->isDefault()) {
            $gfzId = Datacenter::query()->where('name', Datacenter::GFZ_NAME)->value('id');
            if ($gfzId !== null && ! in_array((int) $gfzId, $selectedIds, true)) {
                $selectedIds[] = (int) $gfzId;
            }
        }

        $currentIds = Datacenter::query()
            ->where('landing_page_template_id', $template->id)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $affectedIds = array_values(array_unique([...$currentIds, ...$selectedIds]));

        if ($affectedIds !== []) {
            Datacenter::query()->whereKey($affectedIds)->lockForUpdate()->get(['id']);
        }

        Datacenter::query()
            ->where('landing_page_template_id', $template->id)
            ->when($selectedIds !== [], fn ($query) => $query->whereNotIn('id', $selectedIds))
            ->update(['landing_page_template_id' => null]);

        if ($selectedIds !== []) {
            Datacenter::query()->whereKey($selectedIds)->update(['landing_page_template_id' => $template->id]);
        }

        DB::afterCommit(
            fn () => app(LandingPageRenderDataCacheService::class)->forgetForDatacenters($affectedIds),
        );
    }

    private function loadTemplateUsage(LandingPageTemplate $template): void
    {
        $template->load(['datacenters:id,name,landing_page_template_id'])
            ->loadCount(['landingPages', 'datacenters']);
    }
}
