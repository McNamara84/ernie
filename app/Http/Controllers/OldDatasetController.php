<?php

namespace App\Http\Controllers;

use App\Models\OldDataset;
use App\Models\OldDatasetTitle;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\JsonResponse;

class OldDatasetController extends Controller
{
    /**
     * Display a listing of the datasets.
     *
     * @return \Inertia\Response
     */
    public function index(Request $request): Response
    {
        try {
            // Paginierungsparameter aus der Anfrage
            $page = $request->get('page', 1);
            $perPage = $request->get('per_page', 50);
            
            // Validierung der Parameter
            $page = max(1, (int) $page);
            $perPage = min(200, max(10, (int) $perPage)); // Min 10, Max 200
            
            // Resources aus der SUMARIOPMD-Datenbank abrufen (paginiert)
            $paginatedDatasets = OldDataset::getPaginatedOrderedByCreatedDate($page, $perPage);

            return Inertia::render('old-datasets', [
                'datasets' => $paginatedDatasets->items(),
                'pagination' => [
                    'current_page' => $paginatedDatasets->currentPage(),
                    'last_page' => $paginatedDatasets->lastPage(),
                    'per_page' => $paginatedDatasets->perPage(),
                    'total' => $paginatedDatasets->total(),
                    'from' => $paginatedDatasets->firstItem(),
                    'to' => $paginatedDatasets->lastItem(),
                    'has_more' => $paginatedDatasets->hasMorePages(),
                ],
            ]);
        } catch (\Exception $e) {
            // Bei Datenbankproblemen leere Resultate mit Fehlermeldung zurückgeben
            return Inertia::render('old-datasets', [
                'datasets' => [],
                'pagination' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => 50,
                    'total' => 0,
                    'from' => 0,
                    'to' => 0,
                    'has_more' => false,
                ],
                'error' => 'SUMARIOPMD-Datenbankverbindung fehlgeschlagen: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * API endpoint for loading more datasets (for infinite scrolling).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function loadMore(Request $request)
    {
        try {
            $page = $request->get('page', 1);
            $perPage = $request->get('per_page', 50);
            
            // Validierung der Parameter
            $page = max(1, (int) $page);
            $perPage = min(200, max(10, (int) $perPage));
            
            $paginatedDatasets = OldDataset::getPaginatedOrderedByCreatedDate($page, $perPage);

            return response()->json([
                'datasets' => $paginatedDatasets->items(),
                'pagination' => [
                    'current_page' => $paginatedDatasets->currentPage(),
                    'last_page' => $paginatedDatasets->lastPage(),
                    'per_page' => $paginatedDatasets->perPage(),
                    'total' => $paginatedDatasets->total(),
                    'from' => $paginatedDatasets->firstItem(),
                    'to' => $paginatedDatasets->lastItem(),
                    'has_more' => $paginatedDatasets->hasMorePages(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error loading datasets:ss ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display a specific dataset with all of its titles.
     */
    public function show(int $datasetId): JsonResponse
    {
        try {
            $dataset = OldDataset::findOrFail($datasetId);

            $titles = OldDatasetTitle::query()
                ->where('resource_id', $dataset->id)
                ->orderBy('id')
                ->get()
                ->map(function ($title) {
                    $rawType = $title->titleType ?? $title->titletype ?? null;

                    $normalisedType = $rawType
                        ? Str::slug((string) $rawType, '-')
                        : 'main-title';

                    return [
                        'title' => $title->title,
                        'titleType' => $normalisedType,
                    ];
                })
                ->filter(fn (array $entry) => filled($entry['title']))
                ->values();

            return response()->json([
                'id' => $dataset->id,
                'identifier' => $dataset->identifier,
                'resourcetypegeneral' => $dataset->resourcetypegeneral,
                'curator' => $dataset->curator,
                'created_at' => $dataset->created_at,
                'updated_at' => $dataset->updated_at,
                'publicstatus' => $dataset->publicstatus,
                'publisher' => $dataset->publisher,
                'publicationyear' => $dataset->publicationyear,
                'version' => $dataset->version,
                'language' => $dataset->language,
                'titles' => $titles,
            ]);
        } catch (ModelNotFoundException) {
            return response()->json([
                'error' => 'Dataset not found.',
            ], 404);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Failed to load dataset: ' . $e->getMessage(),
            ], 500);
        }
    }
}