<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Resource;
use App\Services\Editor\EditorDataTransformer;
use App\Services\OldDatasetEditorLoader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Controller for the metadata editor page.
 *
 * Handles multiple data sources for editor initialization:
 * - XML session data from file uploads
 * - Legacy database (OldDataset) via OldDatasetEditorLoader
 * - Existing Resource from new database
 * - Query parameters for import/new mode
 */
class EditorController extends Controller
{
    /**
     * Required array keys in XML session data.
     *
     * @var array<int, string>
     */
    private const XML_SESSION_REQUIRED_ARRAY_KEYS = [
        'titles', 'licenses', 'authors', 'contributors', 'descriptions',
        'dates', 'gcmdKeywords', 'freeKeywords', 'mslKeywords', 'coverages',
        'fundingReferences', 'mslLaboratories',
    ];

    /**
     * Scalar keys that must be string/numeric in XML session data.
     *
     * @var array<int, string>
     */
    private const XML_SESSION_SCALAR_KEYS = [
        'doi', 'year', 'version', 'language', 'resourceType',
    ];

    public function __construct(
        private readonly EditorDataTransformer $transformer,
        private readonly OldDatasetEditorLoader $oldDatasetLoader,
    ) {}

    /**
     * Display the metadata editor.
     *
     * Determines data source from request parameters and renders editor.
     */
    public function show(Request $request): Response|\Illuminate\Http\RedirectResponse
    {
        $xmlSessionKey = $request->query('xmlSession');
        $oldDatasetId = $request->query('oldDatasetId');
        $resourceId = $request->query('resourceId');

        // Priority 1: XML session data
        if ($xmlSessionKey !== null && is_string($xmlSessionKey)) {
            return $this->loadFromXmlSession($xmlSessionKey);
        }

        // Priority 2: Legacy database
        if ($oldDatasetId !== null) {
            return $this->loadFromOldDataset($oldDatasetId);
        }

        // Priority 3: Existing resource
        if ($resourceId !== null) {
            return $this->loadFromResource($resourceId);
        }

        // Priority 4: Query parameters (import/new mode)
        return $this->loadFromQueryParams($request);
    }

    /**
     * Load editor data from XML upload session.
     *
     * @param  string  $sessionKey  Session key starting with 'xml_upload_'
     */
    private function loadFromXmlSession(string $sessionKey): Response|\Illuminate\Http\RedirectResponse
    {
        // Security: Validate session key starts with expected prefix
        if (! str_starts_with($sessionKey, 'xml_upload_')) {
            abort(HttpResponse::HTTP_BAD_REQUEST, 'Invalid session key format');
        }

        $sessionData = session()->pull($sessionKey);

        if (! is_array($sessionData)) {
            // Session expired or invalid
            return redirect()->route('dashboard')
                ->with('error', 'XML upload session expired. Please upload the file again.');
        }

        // Validate session data structure to prevent tampering
        foreach (self::XML_SESSION_REQUIRED_ARRAY_KEYS as $key) {
            if (isset($sessionData[$key]) && ! is_array($sessionData[$key])) {
                abort(HttpResponse::HTTP_BAD_REQUEST, 'Invalid session data structure: '.$key.' must be an array');
            }
        }

        // Validate scalar fields are strings if present
        foreach (self::XML_SESSION_SCALAR_KEYS as $key) {
            if (isset($sessionData[$key]) && ! is_string($sessionData[$key]) && ! is_numeric($sessionData[$key])) {
                abort(HttpResponse::HTTP_BAD_REQUEST, 'Invalid session data structure: '.$key.' must be a string or numeric');
            }
        }

        return Inertia::render('editor', array_merge(
            $this->transformer->getCommonProps(),
            [
                'doi' => $sessionData['doi'] ?? '',
                'year' => $sessionData['year'] ?? '',
                'version' => $sessionData['version'] ?? '',
                'language' => $sessionData['language'] ?? '',
                'resourceType' => $sessionData['resourceType'] ?? '',
                'titles' => $sessionData['titles'] ?? [],
                'initialLicenses' => $sessionData['licenses'] ?? [],
                'authors' => $sessionData['authors'] ?? [],
                'contributors' => $sessionData['contributors'] ?? [],
                'descriptions' => $sessionData['descriptions'] ?? [],
                'dates' => $sessionData['dates'] ?? [],
                'gcmdKeywords' => $sessionData['gcmdKeywords'] ?? [],
                'freeKeywords' => $sessionData['freeKeywords'] ?? [],
                'mslKeywords' => $sessionData['mslKeywords'] ?? [],
                'coverages' => $sessionData['coverages'] ?? [],
                'relatedWorks' => [], // XML upload doesn't support related works yet
                'fundingReferences' => $sessionData['fundingReferences'] ?? [],
                'mslLaboratories' => $sessionData['mslLaboratories'] ?? [],
            ]
        ));
    }

    /**
     * Load editor data from legacy SUMARIOPMD database.
     *
     * @param  mixed  $oldDatasetId  Dataset ID (will be validated)
     */
    private function loadFromOldDataset(mixed $oldDatasetId): Response|\Illuminate\Http\RedirectResponse
    {
        // Validate oldDatasetId
        if (! is_numeric($oldDatasetId) || (int) $oldDatasetId <= 0) {
            abort(HttpResponse::HTTP_BAD_REQUEST, 'Invalid dataset ID');
        }

        try {
            $editorData = $this->oldDatasetLoader->loadForEditor((int) $oldDatasetId);

            return Inertia::render('editor', array_merge(
                $this->transformer->getCommonProps(),
                $editorData
            ));
        } catch (\Exception $e) {
            // Log error and redirect back with error message
            Log::error('Failed to load old dataset in editor', [
                'old_dataset_id' => $oldDatasetId,
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('old-datasets')
                ->with('error', 'Failed to load dataset from legacy database. Please try again or contact support.');
        }
    }

    /**
     * Load editor data from existing Resource.
     *
     * @param  mixed  $resourceId  Resource ID (will be validated by findOrFail)
     */
    private function loadFromResource(mixed $resourceId): Response
    {
        /** @var Resource $resource */
        $resource = Resource::query()
            ->with([
                'resourceType',
                'language',
                'titles.titleType',
                'rights',
                'creators.creatorable',
                'creators.affiliations',
                'descriptions',
                'dates',
                'subjects',
                'geoLocations',
                'relatedIdentifiers.identifierType',
                'relatedIdentifiers.relationType',
                'fundingReferences',
            ])
            ->findOrFail($resourceId);

        return Inertia::render('editor', array_merge(
            $this->transformer->getCommonProps(),
            $this->transformer->transformResource($resource)
        ));
    }

    /**
     * Load editor with query parameters (import/new mode).
     */
    private function loadFromQueryParams(Request $request): Response
    {
        // Decode relatedWorks from JSON if it's a string (to handle large datasets)
        $relatedWorksRaw = $request->query('relatedWorks', []);
        $relatedWorksArray = $this->decodeJsonArrayParam($relatedWorksRaw);

        // Transform relatedWorks from camelCase to snake_case if needed
        // (legacy import uses camelCase, but frontend expects snake_case)
        // Filter out non-array elements to prevent errors
        $validRelatedWorks = array_filter($relatedWorksArray, fn ($item): bool => is_array($item));
        $relatedWorks = array_map(function (array $item): array {
            if (isset($item['identifierType'])) {
                $item['identifier_type'] = $item['identifierType'];
                unset($item['identifierType']);
            }
            if (isset($item['relationType'])) {
                $item['relation_type'] = $item['relationType'];
                unset($item['relationType']);
            }

            return $item;
        }, $validRelatedWorks);

        // Get funding references from query parameters
        $fundingReferencesRaw = $request->query('fundingReferences', []);
        $fundingReferences = $this->decodeJsonArrayParam($fundingReferencesRaw);

        // Get MSL Laboratories from query parameters
        $mslLaboratoriesRaw = $request->query('mslLaboratories', []);
        $mslLaboratories = $this->decodeJsonArrayParam($mslLaboratoriesRaw);

        return Inertia::render('editor', array_merge(
            $this->transformer->getCommonProps(),
            [
                'doi' => $request->query('doi'),
                'year' => $request->query('year'),
                'version' => $request->query('version'),
                'language' => $request->query('language'),
                'resourceType' => $request->query('resourceType'),
                'resourceId' => $request->query('resourceId'),
                'titles' => $request->query('titles', []),
                'initialLicenses' => $request->query('licenses', []),
                'authors' => $request->query('authors', []),
                'contributors' => $request->query('contributors', []),
                'descriptions' => $request->query('descriptions', []),
                'dates' => $request->query('dates', []),
                'gcmdKeywords' => $request->query('gcmdKeywords', []),
                'freeKeywords' => $request->query('freeKeywords', []),
                'coverages' => $request->query('coverages', []),
                'relatedWorks' => $relatedWorks,
                'fundingReferences' => $fundingReferences,
                'mslLaboratories' => $mslLaboratories,
            ]
        ));
    }

    /**
     * Decode a query parameter that may be JSON-encoded or already an array.
     *
     * @param  mixed  $value  Raw query parameter value
     * @return array<int|string, mixed>
     */
    private function decodeJsonArrayParam(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($value) ? $value : [];
    }
}
