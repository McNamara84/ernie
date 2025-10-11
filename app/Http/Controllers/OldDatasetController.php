<?php

namespace App\Http\Controllers;

use App\Models\OldDataset;
use App\Services\OldDatasetKeywordTransformer;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
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

            // Convert datasets to arrays and add licenses
            $datasetsWithLicenses = collect($paginatedDatasets->items())->map(function ($dataset) {
                $data = $dataset->toArray();
                $data['licenses'] = $dataset->getLicenses();
                return $data;
            })->all();

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

            // Convert datasets to arrays and add licenses
            $datasetsWithLicenses = collect($paginatedDatasets->items())->map(function ($dataset) {
                $data = $dataset->toArray();
                $data['licenses'] = $dataset->getLicenses();
                return $data;
            })->all();

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
     * API endpoint to get contributors for a specific old dataset.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getContributors(Request $request, int $id)
    {
        try {
            $dataset = OldDataset::find($id);

            if (!$dataset) {
                return response()->json([
                    'error' => 'Dataset not found',
                ], 404);
            }

            $contributors = $dataset->getContributors();

            return response()->json([
                'contributors' => $contributors,
            ]);
        } catch (\Throwable $e) {
            $debugInfo = $this->buildConnectionDebugInfo($e);

            Log::error('SUMARIOPMD connection failure when loading contributors for dataset ' . $id, $debugInfo + [
                'exception' => $e,
                'dataset_id' => $id,
            ]);

            return response()->json([
                'error' => 'Failed to load contributors from legacy database. Please check the database connection.',
                'debug' => $debugInfo,
            ], 500);
        }
    }

    /**
     * API endpoint to get descriptions for a specific old dataset.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDescriptions(Request $request, int $id)
    {
        try {
            $dataset = OldDataset::find($id);

            if (!$dataset) {
                return response()->json([
                    'error' => 'Dataset not found',
                ], 404);
            }

            $descriptions = $dataset->getDescriptions();

            return response()->json([
                'descriptions' => $descriptions,
            ]);
        } catch (\Throwable $e) {
            $debugInfo = $this->buildConnectionDebugInfo($e);

            Log::error('SUMARIOPMD connection failure when loading descriptions for dataset ' . $id, $debugInfo + [
                'exception' => $e,
                'dataset_id' => $id,
            ]);

            return response()->json([
                'error' => 'Failed to load descriptions from legacy database. Please check the database connection.',
                'debug' => $debugInfo,
            ], 500);
        }
    }

    /**
     * API endpoint to get dates for a specific old dataset.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDates(Request $request, int $id)
    {
        try {
            $dataset = OldDataset::find($id);

            if (!$dataset) {
                return response()->json([
                    'error' => 'Dataset not found',
                ], 404);
            }

            $dates = $dataset->getResourceDates();

            return response()->json([
                'dates' => $dates,
            ]);
        } catch (\Throwable $e) {
            $debugInfo = $this->buildConnectionDebugInfo($e);

            Log::error('SUMARIOPMD connection failure when loading dates for dataset ' . $id, $debugInfo + [
                'exception' => $e,
                'dataset_id' => $id,
            ]);

            return response()->json([
                'error' => 'Failed to load dates from legacy database. Please check the database connection.',
                'debug' => $debugInfo,
            ], 500);
        }
    }

    /**
     * API endpoint to get controlled keywords (GCMD) for a specific old dataset.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getControlledKeywords(Request $request, int $id)
    {
        try {
            $dataset = OldDataset::find($id);

            if (!$dataset) {
                return response()->json([
                    'error' => 'Dataset not found',
                ], 404);
            }

            // Get supported GCMD thesauri
            $supportedThesauri = OldDatasetKeywordTransformer::getSupportedThesauri();

            // Load keywords from old database with JOIN
            $oldKeywords = DB::connection(self::DATASET_CONNECTION)
                ->table('thesauruskeyword as tk')
                ->join('thesaurusvalue as tv', function ($join) {
                    $join->on('tk.keyword', '=', 'tv.keyword')
                         ->on('tk.thesaurus', '=', 'tv.thesaurus');
                })
                ->where('tk.resource_id', $id)
                ->whereIn('tk.thesaurus', $supportedThesauri)
                ->select('tv.keyword', 'tv.thesaurus', 'tv.uri', 'tv.description')
                ->get();

            // Transform to new format
            $transformedKeywords = OldDatasetKeywordTransformer::transformMany($oldKeywords->all());

            return response()->json([
                'keywords' => $transformedKeywords,
            ]);
        } catch (\Throwable $e) {
            $debugInfo = $this->buildConnectionDebugInfo($e);

            Log::error('SUMARIOPMD connection failure when loading controlled keywords for dataset ' . $id, $debugInfo + [
                'exception' => $e,
                'dataset_id' => $id,
            ]);

            return response()->json([
                'error' => 'Failed to load controlled keywords from legacy database. Please check the database connection.',
                'debug' => $debugInfo,
            ], 500);
        }
    }

    /**
     * API endpoint to get free keywords for a specific old dataset.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getFreeKeywords(Request $request, int $id)
    {
        try {
            $dataset = OldDataset::find($id);

            if (!$dataset) {
                return response()->json([
                    'error' => 'Dataset not found',
                ], 404);
            }

            // Get keywords from the keywords column
            $keywordsString = $dataset->keywords;

            // Parse comma-separated keywords
            $keywords = [];
            if (!empty($keywordsString)) {
                $keywords = array_map(
                    fn($keyword) => trim($keyword),
                    explode(',', $keywordsString)
                );
                
                // Remove empty strings
                $keywords = array_filter($keywords, fn($keyword) => $keyword !== '');
                
                // Re-index array to ensure sequential numeric keys
                $keywords = array_values($keywords);
            }

            return response()->json([
                'keywords' => $keywords,
            ]);
        } catch (\Throwable $e) {
            $debugInfo = $this->buildConnectionDebugInfo($e);

            Log::error('SUMARIOPMD connection failure when loading free keywords for dataset ' . $id, $debugInfo + [
                'exception' => $e,
                'dataset_id' => $id,
            ]);

            return response()->json([
                'error' => 'Failed to load free keywords from legacy database. Please check the database connection.',
                'debug' => $debugInfo,
            ], 500);
        }
    }

    /**
     * API endpoint to get spatial and temporal coverage entries for a specific old dataset.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCoverages(Request $request, int $id)
    {
        try {
            $dataset = OldDataset::find($id);

            if (!$dataset) {
                return response()->json([
                    'error' => 'Dataset not found',
                ], 404);
            }

            $coverages = $dataset->getCoverages();

            return response()->json([
                'coverages' => $coverages,
            ]);
        } catch (\Throwable $e) {
            $debugInfo = $this->buildConnectionDebugInfo($e);

            Log::error('SUMARIOPMD connection failure when loading coverages for dataset ' . $id, $debugInfo + [
                'exception' => $e,
                'dataset_id' => $id,
            ]);

            return response()->json([
                'error' => 'Failed to load spatial and temporal coverages from legacy database. Please check the database connection.',
                'debug' => $debugInfo,
            ], 500);
        }
    }

    /**
     * Get related identifiers for a specific old dataset.
     *
     * @param \Illuminate\Http\Request $request
     * @param int $id The dataset ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function getRelatedIdentifiers(Request $request, int $id)
    {
        try {
            $dataset = OldDataset::find($id);

            if (!$dataset) {
                return response()->json([
                    'error' => 'Dataset not found',
                ], 404);
            }

            $relatedIdentifiers = $dataset->getRelatedIdentifiers();

            return response()->json([
                'relatedIdentifiers' => $relatedIdentifiers,
            ]);
        } catch (\Throwable $e) {
            $debugInfo = $this->buildConnectionDebugInfo($e);

            Log::error('SUMARIOPMD connection failure when loading related identifiers for dataset ' . $id, $debugInfo + [
                'exception' => $e,
                'dataset_id' => $id,
            ]);

            return response()->json([
                'error' => 'Failed to load related identifiers from legacy database. Please check the database connection.',
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
