<?php

namespace App\Http\Controllers;

use App\Models\OldDataset;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class OldDatasetController extends Controller
{
    private const DATASET_CONNECTION = 'metaworks';

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

            // Load licenses for each dataset
            $datasetsWithLicenses = $paginatedDatasets->items();
            foreach ($datasetsWithLicenses as $dataset) {
                $dataset->licenses = $dataset->getLicenses();
            }

            return Inertia::render('old-datasets', [
                'datasets' => $datasetsWithLicenses,
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
        } catch (Throwable $e) {
            $debugInfo = $this->buildConnectionDebugInfo($e);

            Log::error('SUMARIOPMD connection failure when rendering old datasets', $debugInfo + [
                'exception' => $e,
            ]);

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
                'debug' => $debugInfo,
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

            // Load licenses for each dataset
            $datasetsWithLicenses = $paginatedDatasets->items();
            foreach ($datasetsWithLicenses as $dataset) {
                $dataset->licenses = $dataset->getLicenses();
            }

            return response()->json([
                'datasets' => $datasetsWithLicenses,
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
        } catch (Throwable $e) {
            $debugInfo = $this->buildConnectionDebugInfo($e);

            Log::error('SUMARIOPMD connection failure when loading more old datasets', $debugInfo + [
                'exception' => $e,
            ]);

            return response()->json([
                'error' => 'Error loading datasets:ss ' . $e->getMessage(),
                'debug' => $debugInfo,
            ], 500);
        }
    }

    /**
     * Build sanitized debug information for the SUMARIOPMD connection failure.
     *
     * @return array<string, mixed>
     */
    private function buildConnectionDebugInfo(Throwable $exception): array
    {
        $connectionName = self::DATASET_CONNECTION;
        $connectionConfig = config("database.connections.{$connectionName}", []);

        $hosts = $this->extractHosts($connectionConfig);

        $debugInfo = [
            'connection' => $connectionName,
            'driver' => $connectionConfig['driver'] ?? null,
            'hosts' => $hosts,
            'port' => $connectionConfig['port'] ?? null,
            'database' => $connectionConfig['database'] ?? null,
            'username' => $connectionConfig['username'] ?? null,
            'unix_socket' => $connectionConfig['unix_socket'] ?? null,
            'error_code' => $exception->getCode(),
            'previous_exception' => $exception->getPrevious()?->getMessage(),
        ];

        return array_filter($debugInfo, static function ($value) {
            if (is_array($value)) {
                return !empty($value);
            }

            return $value !== null && $value !== '';
        });
    }

    /**
     * Extract hosts from a Laravel database connection configuration.
     *
     * @param array<string, mixed> $connectionConfig
     * @return list<string>
     */
    private function extractHosts(array $connectionConfig): array
    {
        $hosts = [];

        $host = $connectionConfig['host'] ?? null;

        if ($host !== null) {
            $hosts = array_merge($hosts, Arr::wrap($host));
        }

        foreach (['read', 'write'] as $mode) {
            $modeHost = $connectionConfig[$mode]['host'] ?? null;

            if ($modeHost !== null) {
                $hosts = array_merge($hosts, Arr::wrap($modeHost));
            }
        }

        $hosts = array_values(array_unique(array_filter($hosts, static fn ($value) => $value !== null && $value !== '')));

        return $hosts;
    }
}
