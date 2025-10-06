<?php

namespace App\Http\Controllers;

use App\Models\OldDataset;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class OldDatasetController extends Controller
{
    private const DATASET_CONNECTION = 'metaworks';
    private const DEFAULT_SORT_KEY = 'updated_at';
    private const DEFAULT_SORT_DIRECTION = 'desc';
    /**
     * @var list<string>
     */
    private const ALLOWED_SORT_KEYS = ['id', 'created_at', 'updated_at'];
    /**
     * @var list<string>
     */
    private const ALLOWED_SORT_DIRECTIONS = ['asc', 'desc'];

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
            
            [$sortKey, $sortDirection] = $this->resolveSortState($request);

            // Resources aus der SUMARIOPMD-Datenbank abrufen (paginiert)
            $paginatedDatasets = OldDataset::getPaginatedOrdered($page, $perPage, $sortKey, $sortDirection);

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
                'sort' => [
                    'key' => $sortKey,
                    'direction' => $sortDirection,
                ],
            ]);
        } catch (\Throwable $e) {
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
                'sort' => [
                    'key' => self::DEFAULT_SORT_KEY,
                    'direction' => self::DEFAULT_SORT_DIRECTION,
                ],
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
            
            [$sortKey, $sortDirection] = $this->resolveSortState($request);

            $paginatedDatasets = OldDataset::getPaginatedOrdered($page, $perPage, $sortKey, $sortDirection);

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
                'sort' => [
                    'key' => $sortKey,
                    'direction' => $sortDirection,
                ],
            ]);
        } catch (\Throwable $e) {
            $debugInfo = $this->buildConnectionDebugInfo($e);

            Log::error('SUMARIOPMD connection failure when loading more old datasets', $debugInfo + [
                'exception' => $e,
            ]);

            return response()->json([
                'error' => 'Error loading datasets:ss ' . $e->getMessage(),
                'debug' => $debugInfo,
                'sort' => [
                    'key' => self::DEFAULT_SORT_KEY,
                    'direction' => self::DEFAULT_SORT_DIRECTION,
                ],
            ], 500);
        }
    }

    /**
     * Resolve the requested sort state, falling back to the default when invalid.
     *
     * @return array{string, string}
     */
    private function resolveSortState(Request $request): array
    {
        $requestedKey = strtolower((string) $request->get('sort_key', self::DEFAULT_SORT_KEY));
        $requestedDirection = strtolower((string) $request->get('sort_direction', self::DEFAULT_SORT_DIRECTION));

        $sortKey = in_array($requestedKey, self::ALLOWED_SORT_KEYS, true)
            ? $requestedKey
            : self::DEFAULT_SORT_KEY;

        $sortDirection = in_array($requestedDirection, self::ALLOWED_SORT_DIRECTIONS, true)
            ? $requestedDirection
            : self::DEFAULT_SORT_DIRECTION;

        return [$sortKey, $sortDirection];
    }

    /**
     * API endpoint to get authors for a specific old dataset.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAuthors(Request $request, int $id)
    {
        try {
            $dataset = OldDataset::find($id);

            if (!$dataset) {
                return response()->json([
                    'error' => 'Dataset not found',
                ], 404);
            }

            $authors = $dataset->getAuthors();

            return response()->json([
                'authors' => $authors,
            ]);
        } catch (\Throwable $e) {
            $debugInfo = $this->buildConnectionDebugInfo($e);

            Log::error('SUMARIOPMD connection failure when loading authors for dataset ' . $id, $debugInfo + [
                'exception' => $e,
                'dataset_id' => $id,
            ]);

            return response()->json([
                'error' => 'Failed to load authors from legacy database. Please check the database connection.',
                'debug' => $debugInfo,
            ], 500);
        }
    }

    /**
     * Build sanitized debug information for the SUMARIOPMD connection failure.
     *
     * @return array<string, mixed>
     */
    private function buildConnectionDebugInfo(\Throwable $exception): array
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
