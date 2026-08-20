<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\EditorLoadStage;
use App\Models\Resource;
use App\Models\User;
use App\Services\Editor\EditorDataTransformer;
use App\Services\Editor\EditorLoadProgressService;
use App\Services\OldDatasetEditorLoader;
use Illuminate\Http\RedirectResponse;
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
    public const RESOURCE_LOAD_TOKEN_HEADER = 'X-Editor-Load-Token';

    /**
     * Required array keys in upload session data (XML and JSON uploads).
     *
     * @var array<int, string>
     */
    private const UPLOAD_SESSION_REQUIRED_ARRAY_KEYS = [
        'titles', 'licenses', 'rawRights', 'authors', 'contributors', 'descriptions',
        'dates', 'gcmdKeywords', 'freeKeywords', 'mslKeywords', 'gemetKeywords',
        'coverages', 'relatedWorks', 'relatedItems', 'instruments', 'fundingReferences', 'mslLaboratories',
    ];

    /**
     * Scalar keys that must be string/numeric in upload session data.
     *
     * @var array<int, string>
     */
    private const UPLOAD_SESSION_SCALAR_KEYS = [
        'doi', 'year', 'version', 'language', 'resourceType',
    ];

    public function __construct(
        private readonly EditorDataTransformer $transformer,
        private readonly OldDatasetEditorLoader $oldDatasetLoader,
        private readonly EditorLoadProgressService $progressTracker,
    ) {}

    /**
     * Display the metadata editor.
     *
     * Determines data source from request parameters and renders editor.
     */
    public function show(Request $request): Response|RedirectResponse
    {
        $xmlSessionKey = $request->query('xmlSession');
        $jsonSessionKey = $request->query('jsonSession');
        $oldDatasetId = $request->query('oldDatasetId');
        $resourceId = $request->query('resourceId');

        // Priority 1: XML session data
        if ($xmlSessionKey !== null && is_string($xmlSessionKey)) {
            return $this->loadFromXmlSession($xmlSessionKey);
        }

        // Priority 1b: JSON session data
        if ($jsonSessionKey !== null && is_string($jsonSessionKey)) {
            return $this->loadFromUploadSession($jsonSessionKey, 'json_upload_', 'JSON');
        }

        // Priority 2: Legacy database
        if ($oldDatasetId !== null) {
            return $this->loadFromOldDataset($oldDatasetId);
        }

        // Priority 3: Existing resource
        if ($resourceId !== null) {
            return $this->loadExistingResource($request, $resourceId);
        }

        // Priority 4: Query parameters (import/new mode)
        return $this->loadFromQueryParams($request);
    }

    /**
     * Load editor data from XML upload session.
     *
     * @param  string  $sessionKey  Session key starting with 'xml_upload_'
     */
    private function loadFromXmlSession(string $sessionKey): Response|RedirectResponse
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
        foreach (self::UPLOAD_SESSION_REQUIRED_ARRAY_KEYS as $key) {
            if (isset($sessionData[$key]) && ! is_array($sessionData[$key])) {
                abort(HttpResponse::HTTP_BAD_REQUEST, 'Invalid session data structure: '.$key.' must be an array');
            }
        }

        // Validate scalar fields are strings if present
        foreach (self::UPLOAD_SESSION_SCALAR_KEYS as $key) {
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
                'initialRawRights' => $sessionData['rawRights'] ?? [],
                'authors' => $sessionData['authors'] ?? [],
                'contributors' => $sessionData['contributors'] ?? [],
                'descriptions' => $sessionData['descriptions'] ?? [],
                'dates' => $sessionData['dates'] ?? [],
                'gcmdKeywords' => $sessionData['gcmdKeywords'] ?? [],
                'freeKeywords' => $sessionData['freeKeywords'] ?? [],
                'mslKeywords' => $sessionData['mslKeywords'] ?? [],
                'gemetKeywords' => $sessionData['gemetKeywords'] ?? [],
                'coverages' => $sessionData['coverages'] ?? [],
                'relatedWorks' => $sessionData['relatedWorks'] ?? [],
                'relatedItems' => $sessionData['relatedItems'] ?? [],
                'instruments' => $sessionData['instruments'] ?? [],
                'fundingReferences' => $sessionData['fundingReferences'] ?? [],
                'mslLaboratories' => $sessionData['mslLaboratories'] ?? [],
            ]
        ));
    }

    /**
     * Load editor data from a generic upload session (JSON, etc.).
     *
     * @param  string  $sessionKey  Session key to look up
     * @param  string  $prefix  Required prefix (e.g. 'json_upload_')
     * @param  string  $label  Human-readable format label for error messages
     */
    private function loadFromUploadSession(string $sessionKey, string $prefix, string $label): Response|RedirectResponse
    {
        if (! str_starts_with($sessionKey, $prefix)) {
            abort(HttpResponse::HTTP_BAD_REQUEST, 'Invalid session key format');
        }

        $sessionData = session()->pull($sessionKey);

        if (! is_array($sessionData)) {
            return redirect()->route('dashboard')
                ->with('error', $label.' upload session expired. Please upload the file again.');
        }

        // Validate session data structure
        foreach (self::UPLOAD_SESSION_REQUIRED_ARRAY_KEYS as $key) {
            if (isset($sessionData[$key]) && ! is_array($sessionData[$key])) {
                abort(HttpResponse::HTTP_BAD_REQUEST, 'Invalid session data structure: '.$key.' must be an array');
            }
        }

        foreach (self::UPLOAD_SESSION_SCALAR_KEYS as $key) {
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
                'initialRawRights' => $sessionData['rawRights'] ?? [],
                'authors' => $sessionData['authors'] ?? [],
                'contributors' => $sessionData['contributors'] ?? [],
                'descriptions' => $sessionData['descriptions'] ?? [],
                'dates' => $sessionData['dates'] ?? [],
                'gcmdKeywords' => $sessionData['gcmdKeywords'] ?? [],
                'freeKeywords' => $sessionData['freeKeywords'] ?? [],
                'mslKeywords' => $sessionData['mslKeywords'] ?? [],
                'gemetKeywords' => $sessionData['gemetKeywords'] ?? [],
                'coverages' => $sessionData['coverages'] ?? [],
                'relatedWorks' => $sessionData['relatedWorks'] ?? [],
                'relatedItems' => $sessionData['relatedItems'] ?? [],
                'instruments' => $sessionData['instruments'] ?? [],
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
    private function loadFromOldDataset(mixed $oldDatasetId): Response|RedirectResponse
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
     * Render the lightweight loader first, then load the resource when the
     * browser repeats the request with its user-bound progress token.
     */
    private function loadExistingResource(Request $request, mixed $resourceId): Response
    {
        $normalizedResourceId = filter_var($resourceId, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if ($normalizedResourceId === false) {
            abort(HttpResponse::HTTP_BAD_REQUEST, 'Invalid resource ID');
        }

        /** @var User $user */
        $user = $request->user();
        $token = $request->header(self::RESOURCE_LOAD_TOKEN_HEADER);

        if (is_string($token) && trim($token) !== '') {
            $state = $this->progressTracker->findForUser($token, $user->id, $normalizedResourceId);
            abort_if($state === null, HttpResponse::HTTP_NOT_FOUND);

            return $this->loadFromResource($normalizedResourceId, $user->id, $token);
        }

        Resource::query()->select('id')->findOrFail($normalizedResourceId);
        $state = $this->progressTracker->begin($user->id, $normalizedResourceId);

        return Inertia::render('editor-loading', [
            'editorLoad' => $this->editorLoadProps(
                token: (string) $state['token'],
                resourceId: $normalizedResourceId,
                serverProgress: (int) $state['progress'],
            ),
            'loadError' => null,
        ]);
    }

    private function loadFromResource(int $resourceId, int $userId, string $token): Response
    {
        try {
            $commonProps = $this->transformer->getCommonProps();
            $this->progressTracker->advance($token, $userId, $resourceId, EditorLoadStage::COMMON_PROPS_LOADED);

            /** @var Resource $resource */
            $resource = Resource::query()->findOrFail($resourceId);
            $this->progressTracker->advance($token, $userId, $resourceId, EditorLoadStage::RESOURCE_LOADED);

            $resource->load([
                'resourceType',
                'language',
                'titles.titleType',
                'rights',
                'resourceRights.right',
                'descriptions.descriptionType',
                'dates.dateType',
            ]);
            $this->progressTracker->advance($token, $userId, $resourceId, EditorLoadStage::CONTENT_RELATIONS_LOADED);

            $resource->load([
                'creators.creatorable',
                'creators.affiliations',
                'contributors.contributorable',
                'contributors.affiliations',
                'contributors.contributorTypes',
            ]);
            $this->progressTracker->advance($token, $userId, $resourceId, EditorLoadStage::PEOPLE_RELATIONS_LOADED);

            $resource->load([
                'subjects',
                'geoLocations',
                'relatedIdentifiers.identifierType',
                'relatedIdentifiers.relationType',
                'fundingReferences.funderIdentifierType',
                'instruments',
                'datacenter',
                'landingPage.externalDomain',
            ]);
            $this->progressTracker->advance($token, $userId, $resourceId, EditorLoadStage::SUPPLEMENTAL_RELATIONS_LOADED);

            $editorData = $this->transformer->transformResource(
                $resource,
                function (string $phase) use ($token, $userId, $resourceId): void {
                    $stage = match ($phase) {
                        'people' => EditorLoadStage::PEOPLE_TRANSFORMED,
                        'identification' => EditorLoadStage::IDENTIFICATION_TRANSFORMED,
                        'content' => EditorLoadStage::CONTENT_TRANSFORMED,
                        'related' => EditorLoadStage::RELATED_METADATA_TRANSFORMED,
                        default => null,
                    };

                    if ($stage !== null) {
                        $this->progressTracker->advance($token, $userId, $resourceId, $stage);
                    }
                },
            );
            $this->progressTracker->advance($token, $userId, $resourceId, EditorLoadStage::SERVER_READY);

            return Inertia::render('editor', array_merge(
                $commonProps,
                $editorData,
                [
                    'editorLoad' => $this->editorLoadProps(
                        token: $token,
                        resourceId: $resourceId,
                        serverProgress: EditorLoadStage::SERVER_READY->progress(),
                    ),
                ],
            ));
        } catch (\Throwable $exception) {
            $message = 'Unable to load this resource in the Data Editor. Please try again.';
            report($exception);
            $serverProgress = 0;

            try {
                $this->progressTracker->fail($token, $userId, $resourceId, $message);
                $progressState = $this->progressTracker->findForUser($token, $userId, $resourceId);

                if ($progressState !== null) {
                    $serverProgress = (int) ($progressState['progress'] ?? 0);
                }
            } catch (\Throwable $trackingException) {
                report($trackingException);
            }

            return Inertia::render('editor-loading', [
                'editorLoad' => $this->editorLoadProps(
                    token: $token,
                    resourceId: $resourceId,
                    serverProgress: $serverProgress,
                ),
                'loadError' => $message,
            ]);
        }
    }

    /**
     * @return array{token: string, resourceId: int, serverProgress: int, slowThresholdMs: int}
     */
    private function editorLoadProps(string $token, int $resourceId, int $serverProgress): array
    {
        return [
            'token' => $token,
            'resourceId' => $resourceId,
            'serverProgress' => $serverProgress,
            'slowThresholdMs' => EditorLoadProgressService::SLOW_THRESHOLD_MS,
        ];
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
        // Filter out non-array elements to prevent errors and ensure sequential keys
        $validRelatedWorks = array_filter($relatedWorksArray, fn ($item): bool => is_array($item));
        $relatedWorks = array_values(array_map(function (array $item): array {
            if (isset($item['identifierType'])) {
                $item['identifier_type'] = $item['identifierType'];
                unset($item['identifierType']);
            }
            if (isset($item['relationType'])) {
                $item['relation_type'] = $item['relationType'];
                unset($item['relationType']);
            }

            return $item;
        }, $validRelatedWorks));

        // Get funding references from query parameters
        $fundingReferencesRaw = $request->query('fundingReferences', []);
        $fundingReferences = $this->decodeJsonArrayParam($fundingReferencesRaw);

        // Get MSL Laboratories from query parameters
        $mslLaboratoriesRaw = $request->query('mslLaboratories', []);
        $mslLaboratories = $this->decodeJsonArrayParam($mslLaboratoriesRaw);

        // Get MSL Keywords from query parameters (for consistency with XML upload path)
        $mslKeywordsRaw = $request->query('mslKeywords', []);
        $mslKeywords = $this->decodeJsonArrayParam($mslKeywordsRaw);

        // Get GEMET Keywords from query parameters
        $gemetKeywordsRaw = $request->query('gemetKeywords', []);
        $gemetKeywords = $this->decodeJsonArrayParam($gemetKeywordsRaw);

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
                'initialRawRights' => $request->query('rawRights', []),
                'authors' => $request->query('authors', []),
                'contributors' => $request->query('contributors', []),
                'descriptions' => $request->query('descriptions', []),
                'dates' => $request->query('dates', []),
                'gcmdKeywords' => $request->query('gcmdKeywords', []),
                'freeKeywords' => $request->query('freeKeywords', []),
                'mslKeywords' => $mslKeywords,
                'gemetKeywords' => $gemetKeywords,
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
     * Logs a warning if JSON decoding fails to help diagnose data integrity issues.
     *
     * @param  mixed  $value  Raw query parameter value
     * @return array<int|string, mixed>
     */
    private function decodeJsonArrayParam(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::warning('Failed to decode JSON query parameter', [
                    'error' => json_last_error_msg(),
                    'value_preview' => mb_substr($value, 0, 100),
                ]);

                return [];
            }

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($value) ? $value : [];
    }
}
