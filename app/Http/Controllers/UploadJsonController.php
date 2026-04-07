<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\UploadErrorCode;
use App\Http\Requests\UploadJsonRequest;
use App\Models\ResourceType;
use App\Services\DataCiteJsonLdToJsonConverter;
use App\Services\JsonSchemaValidator;
use App\Services\UploadLogService;
use App\Support\GcmdUriHelper;
use App\Support\UploadError;
use App\Support\XmlKeywordExtractor;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class UploadJsonController extends Controller
{
    /**
     * Canonical DataCite related identifier types supported by ERNIE editor.
     *
     * @var string[]
     */
    private const RELATED_IDENTIFIER_TYPES = [
        'DOI', 'URL', 'Handle', 'IGSN', 'URN', 'ISBN', 'ISSN', 'PURL', 'ARK',
        'arXiv', 'bibcode', 'CSTR', 'EAN13', 'EISSN', 'ISTC', 'LISSN', 'LSID',
        'PMID', 'RAiD', 'RRID', 'SWHID', 'UPC', 'w3id',
    ];

    /**
     * Canonical DataCite relation types supported by ERNIE editor.
     *
     * @var string[]
     */
    private const RELATED_RELATION_TYPES = [
        'Cites', 'IsCitedBy', 'References', 'IsReferencedBy',
        'Documents', 'IsDocumentedBy', 'Describes', 'IsDescribedBy',
        'IsNewVersionOf', 'IsPreviousVersionOf', 'HasVersion', 'IsVersionOf',
        'HasTranslation', 'IsTranslationOf',
        'Continues', 'IsContinuedBy', 'Obsoletes', 'IsObsoletedBy',
        'IsVariantFormOf', 'IsOriginalFormOf', 'IsIdenticalTo',
        'HasPart', 'IsPartOf', 'Compiles', 'IsCompiledBy',
        'IsSourceOf', 'IsDerivedFrom',
        'IsSupplementTo', 'IsSupplementedBy',
        'Requires', 'IsRequiredBy',
        'HasMetadata', 'IsMetadataFor',
        'Reviews', 'IsReviewedBy',
        'IsPublishedIn', 'Collects', 'IsCollectedBy',
        'Other',
    ];

    /**
     * Contributor role labels mapped from lowercased keys.
     *
     * @var array<string, string>
     */
    private const CONTRIBUTOR_ROLE_LABELS = [
        'contactperson' => 'Contact Person',
        'datacollector' => 'Data Collector',
        'datacurator' => 'Data Curator',
        'datamanager' => 'Data Manager',
        'distributor' => 'Distributor',
        'editor' => 'Editor',
        'hostinginstitution' => 'Hosting Institution',
        'producer' => 'Producer',
        'projectleader' => 'Project Leader',
        'projectmanager' => 'Project Manager',
        'projectmember' => 'Project Member',
        'registrationagency' => 'Registration Agency',
        'registrationauthority' => 'Registration Authority',
        'relatedperson' => 'Related Person',
        'researcher' => 'Researcher',
        'researchgroup' => 'Research Group',
        'rightsholder' => 'Rights Holder',
        'sponsor' => 'Sponsor',
        'supervisor' => 'Supervisor',
        'translator' => 'Translator',
        'workpackageleader' => 'Work Package Leader',
        'other' => 'Other',
    ];

    /**
     * @var string[]
     */
    private const INSTITUTION_ONLY_CONTRIBUTOR_ROLE_KEYS = [
        'distributor',
        'hostinginstitution',
        'registrationagency',
        'registrationauthority',
        'researchgroup',
        'sponsor',
    ];

    /**
     * MSL vocabulary scheme identifier.
     */
    private const MSL_VOCABULARY_SCHEME = 'EPOS MSL vocabulary';

    /**
     * GEMET vocabulary scheme identifier.
     */
    private const GEMET_VOCABULARY_SCHEME = 'GEMET - GEneral Multilingual Environmental Thesaurus';

    public function __construct(
        private readonly UploadLogService $uploadLogService,
        private readonly JsonSchemaValidator $jsonSchemaValidator,
        private readonly DataCiteJsonLdToJsonConverter $jsonLdConverter,
    ) {}

    public function __invoke(UploadJsonRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $filename = $validated['file']->getClientOriginalName();

        $contents = $validated['file']->get();

        if ($contents === false) {
            $error = UploadError::fromCode(UploadErrorCode::FILE_UNREADABLE);
            $this->uploadLogService->logFailure('json', $filename, $error);

            return $this->errorResponse(UploadErrorCode::FILE_UNREADABLE, $filename);
        }

        // Validate file extension
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (! in_array($extension, ['json', 'jsonld'], true)) {
            $error = UploadError::fromCode(UploadErrorCode::INVALID_FILE_TYPE);
            $this->uploadLogService->logFailure('json', $filename, $error);

            return $this->errorResponse(
                UploadErrorCode::INVALID_FILE_TYPE,
                $filename,
                'The file must be a JSON (.json) or JSON-LD (.jsonld) file.',
            );
        }

        // Parse JSON
        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $error = UploadError::withMessage(
                UploadErrorCode::JSON_PARSE_ERROR,
                'The JSON file could not be parsed: ' . $e->getMessage()
            );
            $this->uploadLogService->logFailure('json', $filename, $error);

            return $this->errorResponse(
                UploadErrorCode::JSON_PARSE_ERROR,
                $filename,
                'The JSON file could not be parsed: ' . $e->getMessage()
            );
        }

        if (! is_array($decoded)) {
            $error = UploadError::fromCode(UploadErrorCode::INVALID_JSON_STRUCTURE);
            $this->uploadLogService->logFailure('json', $filename, $error);

            return $this->errorResponse(UploadErrorCode::INVALID_JSON_STRUCTURE, $filename);
        }

        // Auto-detect format and extract attributes
        try {
            $attributes = $this->extractAttributes($decoded);
        } catch (\RuntimeException $e) {
            $error = UploadError::withMessage(
                UploadErrorCode::INVALID_JSON_STRUCTURE,
                $e->getMessage()
            );
            $this->uploadLogService->logFailure('json', $filename, $error);

            return $this->errorResponse(
                UploadErrorCode::INVALID_JSON_STRUCTURE,
                $filename,
                $e->getMessage()
            );
        }

        // Validate against DataCite 4.7 schema (non-strict: DOI optional)
        /** @var array<int, array{path: string, message: string, keyword: string, context: array<string, mixed>}>|null $validationErrors */
        $validationErrors = null;
        if (! $this->jsonSchemaValidator->isValid($attributes, $validationErrors)) {
            $errorMessages = array_map(
                fn (array $e): string => $e['message'],
                $validationErrors ?? []
            );

            $error = UploadError::withMessage(
                UploadErrorCode::JSON_SCHEMA_VALIDATION_ERROR,
                'Schema validation failed: ' . implode('; ', array_slice($errorMessages, 0, 5))
            );
            $this->uploadLogService->logFailure('json', $filename, $error);

            return response()->json([
                'success' => false,
                'message' => 'The JSON file does not conform to the DataCite 4.7 schema.',
                'filename' => $filename,
                'error' => [
                    'category' => 'data',
                    'code' => UploadErrorCode::JSON_SCHEMA_VALIDATION_ERROR->value,
                    'message' => 'The JSON file does not conform to the DataCite 4.7 schema.',
                    'field' => null,
                    'row' => null,
                    'identifier' => null,
                ],
                'errors' => array_map(fn (array $e): array => [
                    'category' => 'data',
                    'code' => UploadErrorCode::JSON_SCHEMA_VALIDATION_ERROR->value,
                    'message' => $e['message'],
                    'field' => $e['path'],
                    'row' => null,
                    'identifier' => null,
                ], $validationErrors ?? []),
            ], 422);
        }

        // Extract all metadata fields
        try {
            $doi = $attributes['doi'] ?? null;
            $year = isset($attributes['publicationYear']) ? (string) $attributes['publicationYear'] : null;
            $version = $attributes['version'] ?? null;
            $language = $attributes['language'] ?? null;

            $resourceType = $this->extractResourceType($attributes);
            $titles = $this->extractTitles($attributes['titles'] ?? []);
            $licenses = $this->extractLicenses($attributes['rightsList'] ?? []);
            $authors = $this->extractAuthors($attributes['creators'] ?? []);

            $contributorsResult = $this->extractContributorsAndMslLaboratories($attributes['contributors'] ?? []);
            $contributors = $contributorsResult['contributors'];
            $mslLaboratories = $contributorsResult['mslLaboratories'];
            $contactPersons = $contributorsResult['contactPersons'];

            // Merge contact persons into authors with isContact flag
            $authors = $this->mergeContactPersonsIntoAuthors($authors, $contactPersons);

            $descriptions = $this->extractDescriptions($attributes['descriptions'] ?? []);
            $dates = $this->extractDates($attributes['dates'] ?? []);
            $coverages = $this->extractCoverages($attributes['geoLocations'] ?? [], $dates);

            $keywordsResult = $this->extractKeywords($attributes['subjects'] ?? []);
            $gcmdKeywords = $keywordsResult['gcmd'];
            $freeKeywords = $keywordsResult['free'];
            $mslKeywords = $keywordsResult['msl'];
            $gemetKeywords = $keywordsResult['gemet'];

            $relatedResult = $this->extractRelatedWorksAndInstruments($attributes['relatedIdentifiers'] ?? [], $filename);
            $relatedWorks = $relatedResult['relatedWorks'];
            $instruments = $relatedResult['instruments'];

            $fundingReferences = $this->extractFundingReferences($attributes['fundingReferences'] ?? []);
        } catch (\Throwable $e) {
            $error = UploadError::withMessage(
                UploadErrorCode::UNEXPECTED_ERROR,
                'An unexpected error occurred while extracting metadata from the JSON file.'
            );
            $this->uploadLogService->logFailure('json', $filename, $error, [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse(
                UploadErrorCode::UNEXPECTED_ERROR,
                $filename,
                'An unexpected error occurred while extracting metadata from the JSON file.'
            );
        }

        // Store data in session
        $sessionKey = 'json_upload_' . Str::random(32);

        session()->put($sessionKey, [
            'doi' => $doi,
            'year' => $year,
            'version' => $version,
            'language' => $language,
            'resourceType' => $resourceType !== null ? (string) $resourceType : null,
            'titles' => $titles,
            'licenses' => $licenses,
            'authors' => $authors,
            'contributors' => $contributors,
            'descriptions' => $descriptions,
            'dates' => $dates,
            'coverages' => $coverages,
            'relatedWorks' => $relatedWorks,
            'instruments' => $instruments,
            'gcmdKeywords' => $gcmdKeywords,
            'freeKeywords' => $freeKeywords,
            'mslKeywords' => $mslKeywords,
            'gemetKeywords' => $gemetKeywords,
            'fundingReferences' => $fundingReferences,
            'mslLaboratories' => $mslLaboratories,
        ]);

        return response()->json([
            'sessionKey' => $sessionKey,
        ]);
    }

    /**
     * Auto-detect format and extract DataCite JSON attributes.
     *
     * @param  array<string, mixed>  $decoded
     * @return array<string, mixed>
     *
     * @throws \RuntimeException If format cannot be detected
     */
    private function extractAttributes(array $decoded): array
    {
        // Check for JSON-LD format (@context key present)
        if (isset($decoded['@context'])) {
            Log::info('Detected DataCite JSON-LD format, converting to JSON');

            return $this->jsonLdConverter->convert($decoded);
        }

        // Check for DataCite JSON API format (data.attributes)
        if (isset($decoded['data']['attributes']) && is_array($decoded['data']['attributes'])) {
            $attributes = $decoded['data']['attributes'];

            // Carry over DOI from data.attributes if present
            if (isset($decoded['data']['attributes']['doi'])) {
                $attributes['doi'] = $decoded['data']['attributes']['doi'];
            }

            return $attributes;
        }

        // Check for flat DataCite JSON attributes (direct attributes without envelope)
        if (isset($decoded['titles']) || isset($decoded['creators'])) {
            return $decoded;
        }

        throw new \RuntimeException(
            'Unrecognized JSON format. Expected DataCite JSON (with data.attributes) or DataCite JSON-LD (with @context).'
        );
    }

    /**
     * Extract resource type ID from attributes.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function extractResourceType(array $attributes): ?int
    {
        $typeName = $attributes['types']['resourceTypeGeneral'] ?? null;

        if ($typeName === null) {
            return null;
        }

        $resourceTypeModel = ResourceType::whereRaw('LOWER(name) = ?', [Str::lower($typeName)])->first();

        return $resourceTypeModel?->id;
    }

    /**
     * @param  array<int, array<string, mixed>>  $titles
     * @return array<int, array{title: string, titleType: string}>
     */
    private function extractTitles(array $titles): array
    {
        $result = [];

        foreach ($titles as $title) {
            $titleText = $title['title'] ?? '';
            if (! is_string($titleText) || trim($titleText) === '') {
                continue;
            }

            $titleType = $title['titleType'] ?? null;
            $result[] = [
                'title' => $titleText,
                'titleType' => $titleType !== null ? Str::kebab($titleType) : 'main-title',
            ];
        }

        // Sort: main titles first
        $mainTitles = array_values(array_filter($result, fn (array $t): bool => $t['titleType'] === 'main-title'));
        $otherTitles = array_values(array_filter($result, fn (array $t): bool => $t['titleType'] !== 'main-title'));

        return array_merge($mainTitles, $otherTitles);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rightsList
     * @return array<int, string>
     */
    private function extractLicenses(array $rightsList): array
    {
        $licenses = [];

        foreach ($rightsList as $rights) {
            $identifier = $rights['rightsIdentifier'] ?? null;
            if (! is_string($identifier) || $identifier === '') {
                continue;
            }
            $licenses[] = $identifier;
        }

        return $licenses;
    }

    /**
     * @param  array<int, array<string, mixed>>  $creators
     * @return array<int, array<string, mixed>>
     */
    private function extractAuthors(array $creators): array
    {
        $authors = [];

        foreach ($creators as $creator) {
            $nameType = $creator['nameType'] ?? null;
            $isInstitution = is_string($nameType) && Str::lower($nameType) === 'organizational';

            $affiliations = $this->extractAffiliationsFromJson($creator['affiliation'] ?? []);

            if ($isInstitution) {
                $authors[] = [
                    'type' => 'institution',
                    'institutionName' => $creator['name'] ?? '',
                    'affiliations' => $affiliations,
                ];

                continue;
            }

            $orcid = $this->extractOrcidFromNameIdentifiers($creator['nameIdentifiers'] ?? []);

            $authors[] = [
                'type' => 'person',
                'orcid' => $orcid,
                'firstName' => $creator['givenName'] ?? '',
                'lastName' => $creator['familyName'] ?? ($creator['name'] ?? ''),
                'affiliations' => $affiliations,
            ];
        }

        return $authors;
    }

    /**
     * @param  array<int, array<string, mixed>>  $contributors
     * @return array{contributors: array<int, array<string, mixed>>, mslLaboratories: array<int, array<string, string>>, contactPersons: array<int, array<string, mixed>>}
     */
    private function extractContributorsAndMslLaboratories(array $contributors): array
    {
        $result = [];
        $mslLaboratories = [];
        $contactPersons = [];

        foreach ($contributors as $contributor) {
            $contributorType = $contributor['contributorType'] ?? null;
            $nameType = $contributor['nameType'] ?? null;

            // Extract role from contributorType
            $roles = $this->extractContributorRoles($contributorType);

            // ContactPerson → extract separately
            if (is_string($contributorType) && strcasecmp($contributorType, 'ContactPerson') === 0) {
                $cp = $this->extractContactPersonFromJson($contributor);
                if ($cp !== null) {
                    $contactPersons[] = $cp;
                }

                continue;
            }

            // MSL Laboratory: HostingInstitution with a nameIdentifier containing labid
            $labId = $this->extractLabIdFromJson($contributor);
            if ($labId !== null && is_string($contributorType) && strcasecmp($contributorType, 'HostingInstitution') === 0) {
                $mslLab = $this->extractMslLaboratoryFromJson($contributor, $labId);
                if ($mslLab !== null) {
                    $mslLaboratories[] = $mslLab;
                }

                continue;
            }

            $isInstitution = $this->isInstitutionContributor($nameType, $roles);
            $affiliations = $this->extractAffiliationsFromJson($contributor['affiliation'] ?? []);

            if ($isInstitution) {
                $result[] = [
                    'type' => 'institution',
                    'institutionName' => $contributor['name'] ?? '',
                    'roles' => $roles,
                    'affiliations' => $affiliations,
                ];

                continue;
            }

            $orcid = $this->extractOrcidFromNameIdentifiers($contributor['nameIdentifiers'] ?? []);

            $result[] = [
                'type' => 'person',
                'roles' => $roles,
                'orcid' => $orcid,
                'firstName' => $contributor['givenName'] ?? '',
                'lastName' => $contributor['familyName'] ?? ($contributor['name'] ?? ''),
                'affiliations' => $affiliations,
            ];
        }

        return [
            'contributors' => $result,
            'mslLaboratories' => $mslLaboratories,
            'contactPersons' => $contactPersons,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $descriptions
     * @return array<int, array{type: string, description: string}>
     */
    private function extractDescriptions(array $descriptions): array
    {
        $result = [];

        foreach ($descriptions as $desc) {
            $text = $desc['description'] ?? '';
            if (! is_string($text) || trim($text) === '') {
                continue;
            }

            $result[] = [
                'type' => $desc['descriptionType'] ?? 'Other',
                'description' => $text,
            ];
        }

        return $result;
    }

    /**
     * @param  array<int, array<string, mixed>>  $dates
     * @return array<int, array{dateType: string, startDate: string, endDate: string}>
     */
    private function extractDates(array $dates): array
    {
        $result = [];

        foreach ($dates as $date) {
            $dateValue = $date['date'] ?? '';
            $dateType = $date['dateType'] ?? 'Other';

            if (! is_string($dateValue) || trim($dateValue) === '') {
                continue;
            }

            $dateValue = trim($dateValue);
            $startDate = '';
            $endDate = '';

            if (str_contains($dateValue, '/')) {
                [$start, $end] = explode('/', $dateValue, 2);
                $startDate = $this->normalizeDateString(trim($start));
                $endDate = $this->normalizeDateString(trim($end));
            } else {
                $startDate = $this->normalizeDateString($dateValue);
            }

            $result[] = [
                'dateType' => Str::kebab($dateType),
                'startDate' => $startDate,
                'endDate' => $endDate,
            ];
        }

        return $result;
    }

    /**
     * @param  array<int, array<string, mixed>>  $geoLocations
     * @param  array<int, array<string, string>>  $dates
     * @return array<int, array<string, mixed>>
     */
    private function extractCoverages(array $geoLocations, array $dates): array
    {
        $coverages = [];

        // Find temporal coverage from dates
        $temporalCoverage = null;
        foreach ($dates as $date) {
            if (($date['dateType'] ?? '') === 'coverage') {
                $temporalCoverage = $date;
                break;
            }
        }

        if (count($geoLocations) === 0 && $temporalCoverage !== null) {
            $coverages[] = [
                'id' => 'coverage-1',
                'type' => 'point',
                'latMin' => '',
                'latMax' => '',
                'lonMin' => '',
                'lonMax' => '',
                'polygonPoints' => [],
                'startDate' => $temporalCoverage['startDate'] ?? '',
                'endDate' => $temporalCoverage['endDate'] ?? '',
                'startTime' => '',
                'endTime' => '',
                'timezone' => 'UTC',
                'description' => '',
            ];

            return $coverages;
        }

        $index = 1;

        foreach ($geoLocations as $geo) {
            $coverage = [
                'id' => 'coverage-' . $index,
                'type' => 'point',
                'latMin' => '',
                'latMax' => '',
                'lonMin' => '',
                'lonMax' => '',
                'polygonPoints' => [],
                'startDate' => $temporalCoverage['startDate'] ?? '',
                'endDate' => $temporalCoverage['endDate'] ?? '',
                'startTime' => '',
                'endTime' => '',
                'timezone' => 'UTC',
                'description' => '',
            ];

            // geoLocationPlace
            if (isset($geo['geoLocationPlace']) && is_string($geo['geoLocationPlace'])) {
                $coverage['description'] = trim($geo['geoLocationPlace']);
            }

            // geoLocationPoint
            if (isset($geo['geoLocationPoint'])) {
                $point = $geo['geoLocationPoint'];
                $coverage['latMin'] = $this->formatCoordinate((string) ($point['pointLatitude'] ?? ''));
                $coverage['lonMin'] = $this->formatCoordinate((string) ($point['pointLongitude'] ?? ''));
            }

            // geoLocationBox (takes precedence)
            if (isset($geo['geoLocationBox'])) {
                $box = $geo['geoLocationBox'];
                $coverage['lonMin'] = $this->formatCoordinate((string) ($box['westBoundLongitude'] ?? ''));
                $coverage['lonMax'] = $this->formatCoordinate((string) ($box['eastBoundLongitude'] ?? ''));
                $coverage['latMin'] = $this->formatCoordinate((string) ($box['southBoundLatitude'] ?? ''));
                $coverage['latMax'] = $this->formatCoordinate((string) ($box['northBoundLatitude'] ?? ''));
            }

            // geoLocationPolygon (highest precedence)
            if (isset($geo['geoLocationPolygon'])) {
                $polygon = $geo['geoLocationPolygon'];
                $points = [];

                $polygonPoints = $polygon['polygonPoints'] ?? [];
                foreach ($polygonPoints as $pt) {
                    $points[] = [
                        'latitude' => (float) ($pt['pointLatitude'] ?? 0),
                        'longitude' => (float) ($pt['pointLongitude'] ?? 0),
                    ];
                }

                if (count($points) > 0) {
                    $coverage['polygonPoints'] = $points;
                }
            }

            // Determine coverage type
            $coverage['type'] = $this->determineCoverageType($coverage);

            // Only include if there's actual data
            if ($coverage['latMin'] !== '' || $coverage['lonMin'] !== '' ||
                ! empty($coverage['polygonPoints']) ||
                $coverage['description'] !== '' || $coverage['startDate'] !== '') {
                $coverages[] = $coverage;
                $index++;
            }
        }

        return $coverages;
    }

    /**
     * @param  array<int, array<string, mixed>>  $subjects
     * @return array{gcmd: array<int, array<string, string>>, free: array<int, string>, msl: array<int, array<string, string>>, gemet: array<int, array<string, string>>}
     */
    private function extractKeywords(array $subjects): array
    {
        $gcmd = [];
        $free = [];
        $msl = [];
        $gemet = [];

        foreach ($subjects as $subject) {
            $text = $subject['subject'] ?? '';
            $scheme = $subject['subjectScheme'] ?? null;
            $schemeUri = $subject['schemeUri'] ?? null;
            $valueUri = $subject['valueUri'] ?? null;
            $classificationCode = $subject['classificationCode'] ?? null;

            if (! is_string($text) || trim($text) === '') {
                continue;
            }

            $text = trim($text);

            // No scheme attributes → free keyword
            if ($scheme === null && $schemeUri === null && $valueUri === null) {
                $free[] = $text;

                continue;
            }

            // MSL vocabulary
            if ($scheme === self::MSL_VOCABULARY_SCHEME) {
                $keyword = [
                    'id' => is_string($valueUri) ? trim($valueUri) : '',
                    'text' => $this->extractLastPathSegment($text),
                    'path' => $text,
                    'language' => 'en',
                    'scheme' => $scheme,
                    'schemeURI' => is_string($schemeUri) && $schemeUri !== '' ? $schemeUri : 'https://epos-msl.uu.nl/voc',
                ];

                if (is_string($classificationCode) && $classificationCode !== '') {
                    $keyword['classificationCode'] = $classificationCode;
                }

                $msl[] = $keyword;

                continue;
            }

            // GEMET vocabulary
            if (is_string($scheme) && $scheme === self::GEMET_VOCABULARY_SCHEME) {
                $keyword = [
                    'id' => is_string($valueUri) ? trim($valueUri) : '',
                    'text' => $this->extractLastPathSegment($text),
                    'path' => $text,
                    'language' => 'en',
                    'scheme' => $scheme,
                    'schemeURI' => is_string($schemeUri) && $schemeUri !== '' ? $schemeUri : 'http://www.eionet.europa.eu/gemet/concept/',
                ];

                if (is_string($classificationCode) && $classificationCode !== '') {
                    $keyword['classificationCode'] = $classificationCode;
                }

                $gemet[] = $keyword;

                continue;
            }

            // GCMD keywords
            if (is_string($scheme) && (
                stripos($scheme, 'Science Keywords') !== false ||
                stripos($scheme, 'Platforms') !== false ||
                stripos($scheme, 'Instruments') !== false
            )) {
                if (! is_string($valueUri) || trim($valueUri) === '') {
                    continue;
                }

                $uuid = GcmdUriHelper::extractUuid(trim($valueUri));
                if (! $uuid) {
                    continue;
                }

                $id = GcmdUriHelper::buildConceptUri($uuid);
                $pathArray = XmlKeywordExtractor::parseGcmdPath($text);
                $pathString = implode(' > ', $pathArray);
                $kwText = array_last($pathArray) ?? $text;

                // Normalize scheme name
                $normalizedScheme = $scheme;
                if (stripos($scheme, 'Science') !== false) {
                    $normalizedScheme = 'Science Keywords';
                } elseif (stripos($scheme, 'Platform') !== false) {
                    $normalizedScheme = 'Platforms';
                } elseif (stripos($scheme, 'Instrument') !== false) {
                    $normalizedScheme = 'Instruments';
                }

                $keyword = [
                    'uuid' => $uuid,
                    'id' => $id,
                    'text' => $kwText,
                    'path' => $pathString,
                    'scheme' => $normalizedScheme,
                ];

                if (is_string($classificationCode) && $classificationCode !== '') {
                    $keyword['classificationCode'] = $classificationCode;
                }

                $gcmd[] = $keyword;

                continue;
            }

            // Unknown scheme with valueURI or classificationCode → treat as GCMD-like
            if (is_string($scheme) && (is_string($valueUri) || is_string($classificationCode))) {
                $keyword = [
                    'uuid' => '',
                    'id' => is_string($valueUri) && $valueUri !== '' ? trim($valueUri) : (is_string($classificationCode) ? $classificationCode : ''),
                    'text' => $text,
                    'path' => $text,
                    'scheme' => $scheme,
                ];

                if (is_string($schemeUri) && $schemeUri !== '') {
                    $keyword['schemeURI'] = $schemeUri;
                }

                if (is_string($classificationCode) && $classificationCode !== '') {
                    $keyword['classificationCode'] = $classificationCode;
                }

                $gcmd[] = $keyword;
            }
        }

        return [
            'gcmd' => $gcmd,
            'free' => $free,
            'msl' => $msl,
            'gemet' => $gemet,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $relatedIdentifiers
     * @return array{relatedWorks: array<int, array<string, mixed>>, instruments: array<int, array<string, string>>}
     */
    private function extractRelatedWorksAndInstruments(array $relatedIdentifiers, string $filename): array
    {
        /** @var array<string, string> $identifierTypeLookup */
        $identifierTypeLookup = [];
        foreach (self::RELATED_IDENTIFIER_TYPES as $name) {
            $identifierTypeLookup[mb_strtolower($name)] = $name;
        }

        /** @var array<string, string> $relationTypeLookup */
        $relationTypeLookup = [];
        foreach (self::RELATED_RELATION_TYPES as $name) {
            $relationTypeLookup[mb_strtolower($name)] = $name;
        }

        $relatedWorks = [];
        $instruments = [];

        foreach ($relatedIdentifiers as $index => $ri) {
            $identifier = $ri['relatedIdentifier'] ?? '';
            $identifierTypeRaw = $ri['relatedIdentifierType'] ?? null;
            $relationTypeRaw = $ri['relationType'] ?? null;
            $relationTypeInformation = $ri['relationTypeInformation'] ?? null;

            if (! is_string($identifier) || $identifier === '') {
                continue;
            }

            $identifierType = is_string($identifierTypeRaw)
                ? ($identifierTypeLookup[mb_strtolower(trim($identifierTypeRaw))] ?? null)
                : null;
            $relationType = is_string($relationTypeRaw)
                ? ($relationTypeLookup[mb_strtolower(trim($relationTypeRaw))] ?? null)
                : null;

            if ($identifierType === null || $relationType === null) {
                Log::warning('Skipping related identifier with unsupported type values during JSON upload', [
                    'filename' => $filename,
                    'index' => $index,
                    'identifier' => $identifier,
                    'relatedIdentifierType' => $identifierTypeRaw,
                    'relationType' => $relationTypeRaw,
                ]);

                continue;
            }

            // Separate instrument PIDs
            if ($relationType === 'IsCollectedBy' && $identifierType === 'Handle') {
                $instruments[] = [
                    'pid' => $identifier,
                    'pidType' => $identifierType,
                    'name' => $identifier,
                ];

                continue;
            }

            $relatedWorks[] = [
                'identifier' => $identifier,
                'identifier_type' => $identifierType,
                'relation_type' => $relationType,
                'relation_type_information' => is_string($relationTypeInformation) && trim($relationTypeInformation) !== '' ? trim($relationTypeInformation) : null,
                'position' => count($relatedWorks),
            ];
        }

        return [
            'relatedWorks' => $relatedWorks,
            'instruments' => $instruments,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $fundingRefs
     * @return array<int, array{funderName: string, funderIdentifier: string|null, funderIdentifierType: string|null, awardNumber: string|null, awardUri: string|null, awardTitle: string|null}>
     */
    private function extractFundingReferences(array $fundingRefs): array
    {
        $result = [];

        foreach ($fundingRefs as $funding) {
            $funderName = $funding['funderName'] ?? null;
            if (! is_string($funderName) || trim($funderName) === '') {
                continue;
            }

            $result[] = [
                'funderName' => $funderName,
                'funderIdentifier' => isset($funding['funderIdentifier']) && is_string($funding['funderIdentifier']) ? $funding['funderIdentifier'] : null,
                'funderIdentifierType' => isset($funding['funderIdentifierType']) && is_string($funding['funderIdentifierType']) ? $funding['funderIdentifierType'] : null,
                'awardNumber' => isset($funding['awardNumber']) && is_string($funding['awardNumber']) ? $funding['awardNumber'] : null,
                'awardUri' => isset($funding['awardUri']) && is_string($funding['awardUri']) ? $funding['awardUri'] : null,
                'awardTitle' => isset($funding['awardTitle']) && is_string($funding['awardTitle']) ? $funding['awardTitle'] : null,
            ];
        }

        return $result;
    }

    /**
     * Extract ORCID from nameIdentifiers array.
     *
     * @param  array<int, array<string, mixed>>  $nameIdentifiers
     */
    private function extractOrcidFromNameIdentifiers(array $nameIdentifiers): string
    {
        foreach ($nameIdentifiers as $ni) {
            $scheme = $ni['nameIdentifierScheme'] ?? null;
            if (is_string($scheme) && stripos($scheme, 'ORCID') !== false) {
                $value = $ni['nameIdentifier'] ?? '';
                if (is_string($value) && $value !== '') {
                    // Normalize: extract just the ORCID ID part
                    if (preg_match('/(\d{4}-\d{4}-\d{4}-\d{3}[\dX])/', $value, $matches)) {
                        return $matches[1];
                    }

                    return $value;
                }
            }
        }

        return '';
    }

    /**
     * @param  array<int, array<string, mixed>>  $affiliations
     * @return array<int, array{value: string, rorId: string|null}>
     */
    private function extractAffiliationsFromJson(array $affiliations): array
    {
        $result = [];

        foreach ($affiliations as $aff) {
            $name = $aff['name'] ?? '';
            if (! is_string($name) || trim($name) === '') {
                continue;
            }

            $rorId = null;
            $rorIdRaw = $aff['affiliationIdentifier'] ?? null;
            $rorScheme = $aff['affiliationIdentifierScheme'] ?? null;
            if (is_string($rorIdRaw) && is_string($rorScheme) && stripos($rorScheme, 'ROR') !== false) {
                $rorId = $rorIdRaw;
            }

            $result[] = [
                'value' => trim($name),
                'rorId' => $rorId,
            ];
        }

        return $result;
    }

    /**
     * Extract contact person data from JSON contributor.
     *
     * @param  array<string, mixed>  $contributor
     * @return array<string, mixed>|null
     */
    private function extractContactPersonFromJson(array $contributor): ?array
    {
        $nameType = $contributor['nameType'] ?? null;

        // Skip organizational contact persons
        if (is_string($nameType) && Str::lower($nameType) === 'organizational') {
            return null;
        }

        $familyName = $contributor['familyName'] ?? null;

        if (! is_string($familyName) || trim($familyName) === '') {
            return null;
        }

        return [
            'type' => 'person',
            'orcid' => $this->extractOrcidFromNameIdentifiers($contributor['nameIdentifiers'] ?? []),
            'firstName' => $contributor['givenName'] ?? '',
            'lastName' => $familyName,
            'affiliations' => $this->extractAffiliationsFromJson($contributor['affiliation'] ?? []),
        ];
    }

    /**
     * Extract MSL lab ID from contributor nameIdentifiers.
     *
     * @param  array<string, mixed>  $contributor
     */
    private function extractLabIdFromJson(array $contributor): ?string
    {
        foreach ($contributor['nameIdentifiers'] ?? [] as $ni) {
            $scheme = $ni['nameIdentifierScheme'] ?? null;
            $value = $ni['nameIdentifier'] ?? null;

            if (is_string($scheme) && stripos($scheme, 'labid') !== false && is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Extract MSL laboratory data from contributor.
     *
     * @param  array<string, mixed>  $contributor
     * @return array{labId: string, labName: string}|null
     */
    private function extractMslLaboratoryFromJson(array $contributor, string $labId): ?array
    {
        $name = $contributor['name'] ?? null;

        if (! is_string($name) || trim($name) === '') {
            return null;
        }

        return [
            'labId' => $labId,
            'labName' => trim($name),
        ];
    }

    /**
     * Determine if a contributor is an institution.
     *
     * @param  string[]  $roles
     */
    private function isInstitutionContributor(?string $nameType, array $roles): bool
    {
        if (is_string($nameType) && Str::lower($nameType) === 'organizational') {
            return true;
        }

        // Check role labels for institution-only roles
        foreach ($roles as $roleLabel) {
            $key = Str::lower(str_replace(' ', '', $roleLabel));
            if (in_array($key, self::INSTITUTION_ONLY_CONTRIBUTOR_ROLE_KEYS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract contributor roles as label strings (matching XML upload format).
     *
     * @return string[]
     */
    private function extractContributorRoles(?string $contributorType): array
    {
        if ($contributorType === null || trim($contributorType) === '') {
            return [];
        }

        $key = Str::lower(str_replace(' ', '', $contributorType));
        $label = self::CONTRIBUTOR_ROLE_LABELS[$key] ?? $contributorType;

        return [$label];
    }

    /**
     * Merge contact persons into authors with isContact flag.
     *
     * @param  array<int, array<string, mixed>>  $authors
     * @param  array<int, array<string, mixed>>  $contactPersons
     * @return array<int, array<string, mixed>>
     */
    private function mergeContactPersonsIntoAuthors(array $authors, array $contactPersons): array
    {
        foreach ($contactPersons as $cp) {
            if (($cp['type'] ?? '') === 'institution') {
                continue;
            }

            $matched = false;

            // 1. Try ORCID match first
            $cpOrcid = $cp['orcid'] ?? '';
            if ($cpOrcid !== '') {
                foreach ($authors as &$author) {
                    if (($author['type'] ?? '') === 'person' && ($author['orcid'] ?? '') === $cpOrcid) {
                        $author['isContact'] = true;
                        $author['email'] = $author['email'] ?? '';
                        $author['website'] = $author['website'] ?? '';
                        $matched = true;
                        break;
                    }
                }
                unset($author);
            }

            // 2. Fallback to name match
            if (! $matched) {
                $cpLastName = trim($cp['lastName'] ?? '');
                $cpFirstName = trim($cp['firstName'] ?? '');

                if ($cpLastName === '') {
                    continue;
                }

                $cpNameKey = $this->buildNameKey($cpLastName, $cpFirstName);

                foreach ($authors as &$author) {
                    if (($author['type'] ?? '') === 'person') {
                        $authorNameKey = $this->buildNameKey($author['lastName'] ?? '', $author['firstName'] ?? '');
                        if ($cpNameKey === $authorNameKey) {
                            $author['isContact'] = true;
                            $author['email'] = $author['email'] ?? '';
                            $author['website'] = $author['website'] ?? '';
                            $matched = true;
                            break;
                        }
                    }
                }
                unset($author);
            }

            // 3. No match → add as new author
            if (! $matched) {
                $authors[] = [
                    'type' => 'person',
                    'orcid' => $cp['orcid'] ?? '',
                    'firstName' => $cp['firstName'] ?? '',
                    'lastName' => $cp['lastName'] ?? '',
                    'affiliations' => $cp['affiliations'] ?? [],
                    'isContact' => true,
                    'email' => '',
                    'website' => '',
                ];
            }
        }

        return $authors;
    }

    private function buildNameKey(string $lastName, string $firstName): string
    {
        return mb_strtolower(trim($lastName) . '|' . trim($firstName));
    }

    private function normalizeDateString(string $dateValue): string
    {
        $dateValue = trim($dateValue);

        if ($dateValue === '') {
            return '';
        }

        // Strip time part
        if (str_contains($dateValue, ' ')) {
            $dateValue = explode(' ', $dateValue)[0];
        }
        if (str_contains($dateValue, 'T')) {
            $dateValue = explode('T', $dateValue)[0];
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateValue)) {
            return $dateValue;
        }

        if (preg_match('/^(\d{4})-(\d{2})$/', $dateValue, $matches)) {
            return $matches[1] . '-' . $matches[2] . '-01';
        }

        if (preg_match('/^\d{4}$/', $dateValue)) {
            return $dateValue . '-01-01';
        }

        $timestamp = strtotime($dateValue);
        if ($timestamp !== false) {
            return date('Y-m-d', $timestamp);
        }

        return '';
    }

    private function formatCoordinate(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        $float = (float) $trimmed;

        return number_format($float, 6, '.', '');
    }

    /**
     * Determine coverage type from extracted data.
     *
     * @param  array<string, mixed>  $coverage
     */
    private function determineCoverageType(array $coverage): string
    {
        if (! empty($coverage['polygonPoints'])) {
            return 'polygon';
        }

        if ($coverage['latMax'] !== '' || $coverage['lonMax'] !== '') {
            return 'box';
        }

        return 'point';
    }

    /**
     * Extract the last segment from a hierarchical path.
     */
    private function extractLastPathSegment(string $path): string
    {
        $parts = explode(' > ', $path);

        return trim(end($parts));
    }

    private function errorResponse(
        UploadErrorCode $code,
        string $filename,
        ?string $customMessage = null,
        int $status = 422
    ): JsonResponse {
        $message = $customMessage ?? $code->message();

        return response()->json([
            'success' => false,
            'message' => $message,
            'filename' => $filename,
            'error' => [
                'category' => $code->category(),
                'code' => $code->value,
                'message' => $message,
                'field' => null,
                'row' => null,
                'identifier' => null,
            ],
        ], $status);
    }
}
