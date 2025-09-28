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
            // Fallback auf Demo-Daten bei Datenbankproblemen
            $demoDatasets = $this->generateDemoData();
            
            return Inertia::render('old-datasets', [
                'datasets' => $demoDatasets,
                'pagination' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => count($demoDatasets),
                    'total' => count($demoDatasets),
                    'from' => 1,
                    'to' => count($demoDatasets),
                    'has_more' => false,
                ],
                'demo' => true,
                'error' => 'SUMARIOPMD-Datenbankverbindung fehlgeschlagen: ' . $e->getMessage(),
                'message' => 'Demo-Daten werden angezeigt',
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
                'error' => 'Fehler beim Laden der Datasets: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Generate demo data for development environment.
     *
     * @return array
     */
    private function generateDemoData(): array
    {
        return [
            [
                'id' => 1,
                'title' => 'Seismic Data Collection 2023',
                'description' => 'Comprehensive seismic measurement data from the Alpine region',
                'doi' => '10.5880/GFZ.2023.001',
                'created_date' => '2023-09-15 10:30:00',
                'updated_date' => '2023-09-16 14:20:00',
                'status' => 'published',
                'author' => 'Dr. Schmidt, M.',
                'keywords' => 'seismology, alpine, monitoring',
                'file_size' => '2.5 GB',
            ],
            [
                'id' => 2,
                'title' => 'Groundwater Monitoring Dataset',
                'description' => 'Long-term groundwater level measurements across Germany',
                'doi' => '10.5880/GFZ.2023.002',
                'created_date' => '2023-08-22 09:15:00',
                'updated_date' => '2023-08-23 11:45:00',
                'status' => 'under_review',
                'author' => 'Prof. Weber, K.',
                'keywords' => 'hydrology, groundwater, germany',
                'file_size' => '150 MB',
            ],
            [
                'id' => 3,
                'title' => 'Magnetic Field Observations',
                'description' => 'Geomagnetic observatory data from polar regions',
                'doi' => '10.5880/GFZ.2023.003',
                'created_date' => '2023-07-10 16:45:00',
                'updated_date' => '2023-07-11 08:30:00',
                'status' => 'published',
                'author' => 'Dr. Johnson, A.',
                'keywords' => 'geomagnetism, polar, observatory',
                'file_size' => '890 MB',
            ],
            [
                'id' => 4,
                'title' => 'Climate Data Archive 2022-2023',
                'description' => 'Meteorological measurements and climate indicators',
                'doi' => '10.5880/GFZ.2023.004',
                'created_date' => '2023-06-05 13:20:00',
                'updated_date' => '2023-06-06 10:15:00',
                'status' => 'published',
                'author' => 'Dr. Mueller, T.',
                'keywords' => 'climate, meteorology, archive',
                'file_size' => '3.2 GB',
            ],
            [
                'id' => 5,
                'title' => 'Gravity Anomaly Survey',
                'description' => 'Regional gravity measurements for geological mapping',
                'doi' => '10.5880/GFZ.2023.005',
                'created_date' => '2023-05-18 11:00:00',
                'updated_date' => '2023-05-19 14:30:00',
                'status' => 'draft',
                'author' => 'Prof. Brown, S.',
                'keywords' => 'gravity, geology, survey',
                'file_size' => '420 MB',
            ],
        ];
    }
}