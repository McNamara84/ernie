<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreResourceRequest;
use App\Models\Language;
use App\Models\License;
use App\Models\Resource;
use App\Models\ResourceTitle;
use App\Models\TitleType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ResourceController extends Controller
{
    private const DEFAULT_PER_PAGE = 25;

    private const MIN_PER_PAGE = 5;

    private const MAX_PER_PAGE = 100;

    public function index(Request $request): Response
    {
        $page = max(1, (int) $request->query('page', 1));
        $perPage = (int) $request->query('per_page', self::DEFAULT_PER_PAGE);

        $perPage = max(self::MIN_PER_PAGE, min(self::MAX_PER_PAGE, $perPage));

        $resources = Resource::query()
            ->with([
                'resourceType:id,name,slug',
                'language:id,code,name',
                'titles' => function ($query): void {
                    $query->select(['id', 'resource_id', 'title', 'title_type_id'])
                        ->with(['titleType:id,name,slug']);
                },
                'licenses:id,identifier,name',
            ])
            ->latest('created_at')
            ->paginate($perPage, ['*'], 'page', $page)
            ->through(static function (Resource $resource): array {
                return [
                    'id' => $resource->id,
                    'doi' => $resource->doi,
                    'year' => $resource->year,
                    'version' => $resource->version,
                    'created_at' => $resource->created_at?->toIso8601String(),
                    'updated_at' => $resource->updated_at?->toIso8601String(),
                    'resource_type' => $resource->resourceType ? [
                        'name' => $resource->resourceType->name,
                        'slug' => $resource->resourceType->slug,
                    ] : null,
                    'language' => $resource->language ? [
                        'code' => $resource->language->code,
                        'name' => $resource->language->name,
                    ] : null,
                    'titles' => $resource->titles
                        ->map(static function (ResourceTitle $title): array {
                            return [
                                'title' => $title->title,
                                'title_type' => $title->titleType ? [
                                    'name' => $title->titleType->name,
                                    'slug' => $title->titleType->slug,
                                ] : null,
                            ];
                        })
                        ->values()
                        ->all(),
                    'licenses' => $resource->licenses
                        ->map(static function (License $license): array {
                            return [
                                'identifier' => $license->identifier,
                                'name' => $license->name,
                            ];
                        })
                        ->values()
                        ->all(),
                ];
            });

        return Inertia::render('resources', [
            'resources' => $resources->items(),
            'pagination' => [
                'current_page' => $resources->currentPage(),
                'last_page' => $resources->lastPage(),
                'per_page' => $resources->perPage(),
                'total' => $resources->total(),
                'from' => $resources->firstItem(),
                'to' => $resources->lastItem(),
                'has_more' => $resources->hasMorePages(),
            ],
        ]);
    }

    public function store(StoreResourceRequest $request): JsonResponse
    {
        try {
            $resource = DB::transaction(function () use ($request): Resource {
                $validated = $request->validated();

                $languageId = null;

                if (! empty($validated['language'])) {
                    $languageId = Language::query()
                        ->where('code', $validated['language'])
                        ->value('id');
                }

                $resource = Resource::query()->create([
                    'doi' => $validated['doi'] ?? null,
                    'year' => $validated['year'],
                    'resource_type_id' => $validated['resourceType'],
                    'version' => $validated['version'] ?? null,
                    'language_id' => $languageId,
                ]);

                $titleTypeSlugs = [];

                foreach ($validated['titles'] as $titleData) {
                    $titleTypeSlugs[] = $titleData['titleType'];
                }

                /** @var array<string, int> $titleTypeMap */
                $titleTypeMap = TitleType::query()
                    ->whereIn('slug', $titleTypeSlugs)
                    ->pluck('id', 'slug')
                    ->all();

                $resourceTitles = [];

                foreach ($validated['titles'] as $title) {
                    $resourceTitles[] = [
                        'title' => $title['title'],
                        'title_type_id' => $titleTypeMap[$title['titleType']],
                    ];
                }

                $resource->titles()->createMany($resourceTitles);

                /** @var array<int, int> $licenseIds */
                $licenseIds = License::query()
                    ->whereIn('identifier', $validated['licenses'])
                    ->pluck('id')
                    ->all();

                $resource->licenses()->sync($licenseIds);

                return $resource->load(['titles', 'licenses']);
            });
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Unable to save resource. Please try again later.',
            ], 500);
        }

        return response()->json([
            'message' => 'Successfully saved resource.',
            'resource' => [
                'id' => $resource->id,
            ],
        ], 201);
    }
}
