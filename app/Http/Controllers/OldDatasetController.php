<?php

namespace App\Http\Controllers;

use App\Models\OldDataset;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

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
}