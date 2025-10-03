<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDatasetRequest;
use App\Models\Dataset;
use App\Models\Language;
use App\Models\License;
use App\Models\TitleType;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class DatasetController extends Controller
{
    public function store(StoreDatasetRequest $request): JsonResponse
    {
        try {
            $dataset = DB::transaction(function () use ($request): Dataset {
                $validated = $request->validated();

                $languageId = null;

                if (! empty($validated['language'])) {
                    $languageId = Language::query()
                        ->where('code', $validated['language'])
                        ->value('id');
                }

                $dataset = Dataset::query()->create([
                    'doi' => $validated['doi'] ?? null,
                    'year' => $validated['year'],
                    'resource_type_id' => $validated['resourceType'],
                    'version' => $validated['version'] ?? null,
                    'language_id' => $languageId,
                ]);

                $titleTypeMap = TitleType::query()
                    ->whereIn('slug', collect($validated['titles'])->pluck('titleType')->all())
                    ->pluck('id', 'slug');

                $dataset->titles()->createMany(
                    collect($validated['titles'])
                        ->map(fn (array $title) => [
                            'title' => $title['title'],
                            'title_type_id' => $titleTypeMap[$title['titleType']],
                        ])
                        ->all(),
                );

                $licenseIds = License::query()
                    ->whereIn('identifier', $validated['licenses'])
                    ->pluck('id')
                    ->all();

                $dataset->licenses()->sync($licenseIds);

                return $dataset->load(['titles', 'licenses']);
            });
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Unable to save dataset. Please try again later.',
            ], 500);
        }

        return response()->json([
            'message' => 'Successfully saved dataset.',
            'dataset' => [
                'id' => $dataset->id,
            ],
        ], 201);
    }
}
