<?php

declare(strict_types=1);

namespace App\Services\OaiPmh;

use App\Models\OaiPmhDeletedRecord;
use App\Models\Resource;
use App\Services\DataCiteXmlExporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Orchestrates all 6 OAI-PMH 2.0 verbs.
 *
 * @see http://www.openarchives.org/OAI/openarchivesprotocol.html
 */
class OaiPmhService
{
    private const VALID_VERBS = [
        'Identify',
        'ListMetadataFormats',
        'ListSets',
        'ListIdentifiers',
        'ListRecords',
        'GetRecord',
    ];

    /**
     * Legal arguments per verb (excluding the 'verb' parameter itself).
     *
     * @var array<string, list<string>>
     */
    private const VERB_ARGUMENTS = [
        'Identify' => [],
        'ListMetadataFormats' => ['identifier'],
        'ListSets' => ['resumptionToken'],
        'ListIdentifiers' => ['metadataPrefix', 'from', 'until', 'set', 'resumptionToken'],
        'ListRecords' => ['metadataPrefix', 'from', 'until', 'set', 'resumptionToken'],
        'GetRecord' => ['identifier', 'metadataPrefix'],
    ];

    /**
     * Required arguments per verb.
     *
     * @var array<string, list<string>>
     */
    private const REQUIRED_ARGUMENTS = [
        'Identify' => [],
        'ListMetadataFormats' => [],
        'ListSets' => [],
        'ListIdentifiers' => ['metadataPrefix'],
        'ListRecords' => ['metadataPrefix'],
        'GetRecord' => ['identifier', 'metadataPrefix'],
    ];

    public function __construct(
        private readonly OaiPmhXmlResponseBuilder $xmlBuilder,
        private readonly DublinCoreMapper $dcMapper,
        private readonly OaiPmhSetService $setService,
        private readonly OaiPmhResumptionTokenService $tokenService,
        private readonly DataCiteXmlExporter $dataCiteExporter,
    ) {}

    /**
     * Handle an OAI-PMH request by dispatching to the correct verb handler.
     */
    public function handleRequest(Request $request): string
    {
        $verb = $request->input('verb', '');

        if ($verb === '' || ! in_array($verb, self::VALID_VERBS, true)) {
            return $this->errorResponse('badVerb', 'Illegal OAI verb');
        }

        // Check for illegal/duplicate arguments
        $argumentError = $this->validateArguments($request, $verb);
        if ($argumentError !== null) {
            return $argumentError;
        }

        return match ($verb) {
            'Identify' => $this->identify(),
            'ListMetadataFormats' => $this->listMetadataFormats($request),
            'ListSets' => $this->listSets($request),
            'ListIdentifiers' => $this->listIdentifiers($request),
            'ListRecords' => $this->listRecords($request),
            'GetRecord' => $this->getRecord($request),
        };
    }

    /**
     * Build an OAI-PMH error response.
     *
     * @param  array<string, string>  $requestAttributes
     */
    public function errorResponse(string $code, string $message, string $verb = '', array $requestAttributes = []): string
    {
        return $this->xmlBuilder
            ->createEnvelope($verb, $requestAttributes)
            ->addError($code, $message)
            ->toXml();
    }

    /**
     * Identify verb – Repository information.
     */
    private function identify(): string
    {
        $earliest = $this->buildHarvestableQuery()
            ->join('landing_pages', 'landing_pages.resource_id', '=', 'resources.id')
            ->selectRaw('MIN(CASE WHEN landing_pages.published_at > resources.updated_at THEN landing_pages.published_at ELSE resources.updated_at END) as earliest')
            ->value('earliest');

        $earliestDatestamp = $earliest !== null
            ? Carbon::parse($earliest)->utc()->format('Y-m-d\TH:i:s\Z')
            : (string) config('oaipmh.earliest_datestamp');

        // Find a sample identifier from the first published resource
        $sampleResource = Resource::query()
            ->whereHas('landingPage', fn (Builder $q) => $q->where('is_published', true))
            ->whereNotNull('doi')
            ->first();

        $sampleId = $sampleResource !== null
            ? $this->buildOaiIdentifier($sampleResource->doi)
            : config('oaipmh.identifier_prefix') . ':10.5880/example';

        return $this->xmlBuilder
            ->createEnvelope('Identify')
            ->addIdentifyContent($earliestDatestamp, $sampleId)
            ->toXml();
    }

    /**
     * ListMetadataFormats verb.
     */
    private function listMetadataFormats(Request $request): string
    {
        $identifier = $request->input('identifier');

        if ($identifier !== null) {
            if (! is_string($identifier)) {
                return $this->errorResponse('badArgument', 'The identifier argument must be a single value', 'ListMetadataFormats');
            }

            // Validate the identifier exists
            $doi = $this->extractDoiFromIdentifier($identifier);
            if ($doi === null || ! $this->resourceExists($doi)) {
                // Check deleted records too
                if ($doi === null || ! OaiPmhDeletedRecord::where('doi', $doi)->exists()) {
                    return $this->errorResponse(
                        'idDoesNotExist',
                        'The identifier does not exist in this repository',
                        'ListMetadataFormats',
                        ['identifier' => $identifier],
                    );
                }
            }
        }

        /** @var array<string, array{schema: string, namespace: string}> $formats */
        $formats = config('oaipmh.metadata_formats', []);

        return $this->xmlBuilder
            ->createEnvelope('ListMetadataFormats', $identifier !== null ? ['identifier' => $identifier] : [])
            ->addListMetadataFormatsContent($formats)
            ->toXml();
    }

    /**
     * ListSets verb.
     */
    private function listSets(Request $request): string
    {
        $resumptionToken = $request->input('resumptionToken');

        if ($resumptionToken !== null) {
            return $this->errorResponse(
                'badResumptionToken',
                'Resumption tokens are not supported for ListSets',
                'ListSets',
            );
        }

        $sets = $this->setService->listSets();

        return $this->xmlBuilder
            ->createEnvelope('ListSets')
            ->addListSetsContent($sets)
            ->toXml();
    }

    /**
     * ListIdentifiers verb – Headers only.
     */
    private function listIdentifiers(Request $request): string
    {
        return $this->listItems($request, 'ListIdentifiers', headersOnly: true);
    }

    /**
     * ListRecords verb – Full metadata records.
     */
    private function listRecords(Request $request): string
    {
        return $this->listItems($request, 'ListRecords', headersOnly: false);
    }

    /**
     * GetRecord verb – Single record.
     */
    private function getRecord(Request $request): string
    {
        $rawIdentifier = $request->input('identifier', '');
        $rawMetadataPrefix = $request->input('metadataPrefix', '');

        if (! is_string($rawIdentifier) || ! is_string($rawMetadataPrefix)) {
            return $this->errorResponse('badArgument', 'Arguments must be single values', 'GetRecord');
        }

        $identifier = $rawIdentifier;
        $metadataPrefix = $rawMetadataPrefix;

        $requestAttrs = ['identifier' => $identifier, 'metadataPrefix' => $metadataPrefix];

        if (! $this->isValidMetadataPrefix($metadataPrefix)) {
            return $this->errorResponse(
                'cannotDisseminateFormat',
                "The metadata format '{$metadataPrefix}' is not supported by this repository",
                'GetRecord',
                $requestAttrs,
            );
        }

        $doi = $this->extractDoiFromIdentifier($identifier);

        if ($doi === null) {
            return $this->errorResponse(
                'idDoesNotExist',
                'The identifier does not exist in this repository',
                'GetRecord',
                $requestAttrs,
            );
        }

        // Check for deleted record first
        $deletedRecord = OaiPmhDeletedRecord::where('doi', $doi)->first();
        if ($deletedRecord !== null) {
            $builder = $this->xmlBuilder->createEnvelope('GetRecord', $requestAttrs);
            $container = $builder->beginGetRecord();
            $builder->addDeletedRecord(
                $container,
                $deletedRecord->oai_identifier,
                $deletedRecord->datestamp->utc()->format('Y-m-d\TH:i:s\Z'),
                array_values($deletedRecord->sets ?? []),
            );

            return $builder->toXml();
        }

        // Find the published resource
        $resource = $this->findPublishedResource($doi, $metadataPrefix);
        if ($resource === null) {
            return $this->errorResponse(
                'idDoesNotExist',
                'The identifier does not exist in this repository',
                'GetRecord',
                $requestAttrs,
            );
        }

        $builder = $this->xmlBuilder->createEnvelope('GetRecord', $requestAttrs);
        $container = $builder->beginGetRecord();

        $metadataXml = $this->buildMetadataXml($resource, $metadataPrefix);
        $sets = $this->setService->getSetsForResource($resource);

        $builder->addRecord(
            $container,
            $this->buildOaiIdentifier($resource->doi),
            $this->effectiveDatestamp($resource),
            $sets,
            $metadataXml,
        );

        return $builder->toXml();
    }

    /**
     * Shared logic for ListIdentifiers and ListRecords.
     */
    private function listItems(Request $request, string $verb, bool $headersOnly): string
    {
        $resumptionToken = $request->input('resumptionToken');
        $pageSize = (int) config('oaipmh.page_size', 100);

        // Reject array parameters – OAI-PMH arguments must be single values
        foreach (['resumptionToken', 'metadataPrefix', 'set', 'from', 'until'] as $param) {
            $value = $request->input($param);
            if ($value !== null && ! is_string($value)) {
                return $this->errorResponse('badArgument', "The {$param} argument must be a single value", $verb);
            }
        }

        // Resumption token mode: restore query state from token
        if (is_string($resumptionToken)) {
            $token = $this->tokenService->resolve($resumptionToken);
            if ($token === null) {
                return $this->errorResponse('badResumptionToken', 'Invalid or expired resumption token', $verb);
            }

            // Verify the token's verb matches the current request verb
            if ($token->verb !== $verb) {
                return $this->errorResponse('badResumptionToken', 'Resumption token was issued for a different verb', $verb);
            }

            $metadataPrefix = $token->metadata_prefix ?? '';
            $setSpec = $token->set_spec;
            $from = $token->from_date;
            $until = $token->until_date;
            $cursor = $token->cursor;

            $requestAttrs = ['resumptionToken' => $resumptionToken];
        } else {
            $metadataPrefix = (string) $request->input('metadataPrefix', '');
            $setSpec = $request->input('set');
            $fromStr = $request->input('from');
            $untilStr = $request->input('until');
            $cursor = 0;

            $requestAttrs = ['metadataPrefix' => $metadataPrefix];

            if (! $this->isValidMetadataPrefix($metadataPrefix)) {
                return $this->errorResponse(
                    'cannotDisseminateFormat',
                    "The metadata format '{$metadataPrefix}' is not supported by this repository",
                    $verb,
                    $requestAttrs,
                );
            }

            // Parse and validate date parameters
            $from = null;
            $until = null;
            $fromGranularity = null;
            $untilGranularity = null;

            if ($fromStr !== null) {
                [$from, $fromGranularity] = $this->parseOaiDate($fromStr);
                if ($from === null) {
                    return $this->errorResponse('badArgument', 'Invalid from date format', $verb, $requestAttrs);
                }
                $requestAttrs['from'] = $fromStr;
            }

            if ($untilStr !== null) {
                [$until, $untilGranularity] = $this->parseOaiDate($untilStr, isUntil: true);
                if ($until === null) {
                    return $this->errorResponse('badArgument', 'Invalid until date format', $verb, $requestAttrs);
                }
                $requestAttrs['until'] = $untilStr;
            }

            // OAI-PMH requires from and until to have the same granularity
            if ($fromGranularity !== null && $untilGranularity !== null && $fromGranularity !== $untilGranularity) {
                return $this->errorResponse(
                    'badArgument',
                    'The from and until parameters must have the same granularity (both YYYY-MM-DD or both YYYY-MM-DDThh:mm:ssZ)',
                    $verb,
                    $requestAttrs,
                );
            }

            if ($from !== null && $until !== null && $from->greaterThan($until)) {
                return $this->errorResponse('badArgument', 'The from date must be less than or equal to the until date', $verb, $requestAttrs);
            }

            if ($setSpec !== null) {
                if (! $this->setService->isValidSetSpec($setSpec)) {
                    return $this->errorResponse('badArgument', "Invalid set specification: {$setSpec}", $verb, $requestAttrs);
                }
                $requestAttrs['set'] = $setSpec;
            }
        }

        // Build query for published resources
        $query = $this->buildHarvestableQuery()
            ->select('resources.*')
            ->join('landing_pages', 'landing_pages.resource_id', '=', 'resources.id')
            ->when($setSpec !== null, fn (Builder $q) => $this->setService->applySetFilter($q, $setSpec))
            ->when($from !== null, fn (Builder $q) => $q->whereRaw('(CASE WHEN landing_pages.published_at > resources.updated_at THEN landing_pages.published_at ELSE resources.updated_at END) >= ?', [$from]))
            ->when($until !== null, fn (Builder $q) => $q->whereRaw('(CASE WHEN landing_pages.published_at > resources.updated_at THEN landing_pages.published_at ELSE resources.updated_at END) <= ?', [$until]))
            ->orderByRaw('CASE WHEN landing_pages.published_at > resources.updated_at THEN landing_pages.published_at ELSE resources.updated_at END')
            ->orderBy('resources.id');

        $totalCount = $query->count();

        // Also count deleted records matching the filters
        $deletedQuery = OaiPmhDeletedRecord::query()
            ->when($from !== null, fn ($q) => $q->where('datestamp', '>=', $from))
            ->when($until !== null, fn ($q) => $q->where('datestamp', '<=', $until));

        if ($setSpec !== null) {
            $deletedQuery->whereJsonContains('sets', $setSpec);
        }

        $deletedCount = $deletedQuery->count();
        $completeListSize = $totalCount + $deletedCount;

        if ($completeListSize === 0) {
            return $this->errorResponse('noRecordsMatch', 'No records match the given criteria', $verb, $requestAttrs);
        }

        $builder = $this->xmlBuilder->createEnvelope($verb, $requestAttrs);
        $container = $headersOnly ? $builder->beginListIdentifiers() : $builder->beginListRecords();

        $emitted = 0;

        // Deleted records occupy the first $deletedCount positions in the virtual list.
        // If the cursor falls within that range, emit deleted records for this page.
        if ($cursor < $deletedCount) {
            $deletedRecords = $deletedQuery
                ->orderBy('datestamp')
                ->orderBy('id')
                ->skip($cursor)
                ->take($pageSize)
                ->get();

            foreach ($deletedRecords as $deleted) {
                if ($headersOnly) {
                    $builder->addHeader(
                        $container,
                        $deleted->oai_identifier,
                        $deleted->datestamp->utc()->format('Y-m-d\TH:i:s\Z'),
                        array_values($deleted->sets ?? []),
                        deleted: true,
                    );
                } else {
                    $builder->addDeletedRecord(
                        $container,
                        $deleted->oai_identifier,
                        $deleted->datestamp->utc()->format('Y-m-d\TH:i:s\Z'),
                        array_values($deleted->sets ?? []),
                    );
                }
                $emitted++;
            }
        }

        // Fill remaining page space with live records
        $remainingSlots = $pageSize - $emitted;
        if ($remainingSlots > 0) {
            $resourceOffset = max(0, $cursor - $deletedCount);

            $resources = $query
                ->skip($resourceOffset)
                ->take($remainingSlots)
                ->get();

            if (! $headersOnly) {
                $resources->loadMissing(['landingPage', ...$this->getRelationsForMetadata($metadataPrefix)]);
            } else {
                $resources->loadMissing(['landingPage', 'resourceType']);
            }

            foreach ($resources as $resource) {
                $oaiId = $this->buildOaiIdentifier($resource->doi);
                $datestamp = $this->effectiveDatestamp($resource);
                $sets = $this->setService->getSetsForResource($resource);

                if ($headersOnly) {
                    $builder->addHeader($container, $oaiId, $datestamp, $sets);
                } else {
                    $metadataXml = $this->buildMetadataXml($resource, $metadataPrefix);
                    $builder->addRecord($container, $oaiId, $datestamp, $sets, $metadataXml);
                }
                $emitted++;
            }
        }

        // Add resumption token if more results exist
        $nextCursor = $cursor + $emitted;

        if ($nextCursor < $completeListSize) {
            $newToken = $this->tokenService->create(
                $verb,
                $metadataPrefix,
                $setSpec,
                $from,
                $until,
                $nextCursor,
                $completeListSize,
            );

            $builder->addResumptionToken(
                $container,
                $newToken->token,
                $completeListSize,
                $cursor,
                $newToken->expires_at->utc()->format('Y-m-d\TH:i:s\Z'),
            );
        } elseif ($resumptionToken !== null) {
            // Last page with a resumption token: emit empty token to signal end
            $builder->addResumptionToken($container, null, $completeListSize, $cursor);
        }

        return $builder->toXml();
    }

    /**
     * Validate arguments for a given verb.
     *
     * Merges query string and POST body parameters to ensure consistent
     * validation regardless of HTTP method (OAI-PMH supports both GET and POST).
     */
    private function validateArguments(Request $request, string $verb): ?string
    {
        $allParams = array_keys($request->all());
        $legalArgs = self::VERB_ARGUMENTS[$verb] ?? [];
        $requiredArgs = self::REQUIRED_ARGUMENTS[$verb] ?? [];

        // Check for illegal arguments
        foreach ($allParams as $param) {
            if ($param === 'verb') {
                continue;
            }
            if (! in_array($param, $legalArgs, true)) {
                return $this->errorResponse(
                    'badArgument',
                    "Illegal argument '{$param}' for verb '{$verb}'",
                    $verb,
                );
            }
        }

        // If resumptionToken is present, it must be the exclusive argument
        if ($request->has('resumptionToken') && in_array('resumptionToken', $legalArgs, true)) {
            $otherArgs = array_filter($allParams, fn (string $p) => $p !== 'verb' && $p !== 'resumptionToken');
            if ($otherArgs !== []) {
                return $this->errorResponse(
                    'badArgument',
                    'resumptionToken is an exclusive argument and cannot be combined with other arguments',
                    $verb,
                );
            }

            // When resumptionToken is present, skip required argument checks
            return null;
        }

        // Check for required arguments
        foreach ($requiredArgs as $required) {
            if (! $request->has($required) || $request->input($required) === '') {
                return $this->errorResponse(
                    'badArgument',
                    "Missing required argument '{$required}' for verb '{$verb}'",
                    $verb,
                );
            }
        }

        return null;
    }

    /**
     * Build a base query for harvestable (published) resources.
     *
     * @return Builder<Resource>
     */
    private function buildHarvestableQuery(): Builder
    {
        return Resource::query()
            ->whereHas('landingPage', fn (Builder $q) => $q->where('is_published', true))
            ->whereNotNull('doi');
    }

    /**
     * Find a published resource by DOI.
     *
     * Eager-loads only the relations needed for the requested metadata format.
     */
    private function findPublishedResource(string $doi, string $metadataPrefix = 'oai_datacite'): ?Resource
    {
        $resource = $this->buildHarvestableQuery()
            ->where('doi', $doi)
            ->first();

        $resource?->loadMissing(['landingPage', ...$this->getRelationsForMetadata($metadataPrefix)]);

        return $resource;
    }

    /**
     * Check if a resource with the given DOI exists and is published.
     */
    private function resourceExists(string $doi): bool
    {
        return $this->buildHarvestableQuery()
            ->where('doi', $doi)
            ->exists();
    }

    /**
     * Build the OAI identifier from a DOI.
     */
    private function buildOaiIdentifier(?string $doi): string
    {
        return config('oaipmh.identifier_prefix') . ':' . ($doi ?? 'unknown');
    }

    /**
     * Compute the effective OAI-PMH datestamp for a resource.
     *
     * Uses the greater of resources.updated_at and landing_pages.published_at
     * so that the datestamp reflects whichever event happened more recently.
     */
    private function effectiveDatestamp(Resource $resource): string
    {
        $updated = $resource->updated_at ?? now();
        $published = $resource->landingPage?->published_at;

        $effective = ($published !== null && $published->greaterThan($updated))
            ? $published
            : $updated;

        return $effective->utc()->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * Extract DOI from an OAI identifier.
     *
     * Format: "oai:ernie.gfz.de:{doi}"
     */
    private function extractDoiFromIdentifier(string $identifier): ?string
    {
        $prefix = config('oaipmh.identifier_prefix') . ':';

        if (! str_starts_with($identifier, $prefix)) {
            return null;
        }

        $doi = substr($identifier, strlen($prefix));

        return $doi !== '' ? $doi : null;
    }

    /**
     * Build the metadata XML for a resource in the given format.
     */
    private function buildMetadataXml(Resource $resource, string $metadataPrefix): string
    {
        return match ($metadataPrefix) {
            'oai_dc' => $this->buildDublinCoreXml($resource),
            'oai_datacite' => $this->buildDataCiteXml($resource),
            default => '',
        };
    }

    /**
     * Build Dublin Core XML for a resource.
     */
    private function buildDublinCoreXml(Resource $resource): string
    {
        $dcElements = $this->dcMapper->map($resource);

        return $this->xmlBuilder->buildDublinCoreXml($dcElements);
    }

    /**
     * Build DataCite XML for a resource (reuses the existing DataCiteXmlExporter).
     */
    private function buildDataCiteXml(Resource $resource): string
    {
        return $this->dataCiteExporter->export($resource);
    }

    /**
     * Get the relations that should be loaded for metadata rendering.
     *
     * @return list<string>
     */
    private function getRelationsForMetadata(string $metadataPrefix): array
    {
        return match ($metadataPrefix) {
            'oai_dc' => [
                'resourceType',
                'language',
                'publisher',
                'titles',
                'creators.creatorable',
                'contributors.contributorable',
                'descriptions.descriptionType',
                'subjects',
                'rights',
                'formats',
                'relatedIdentifiers',
                'geoLocations',
            ],
            'oai_datacite' => [
                'resourceType',
                'language',
                'publisher',
                'titles.titleType',
                'creators.creatorable',
                'creators.affiliations',
                'contributors.contributorable',
                'contributors.contributorTypes',
                'contributors.affiliations',
                'descriptions.descriptionType',
                'dates.dateType',
                'subjects',
                'geoLocations',
                'rights',
                'relatedIdentifiers.identifierType',
                'relatedIdentifiers.relationType',
                'fundingReferences.funderIdentifierType',
                'alternateIdentifiers',
                'sizes',
                'formats',
                'igsnMetadata',
                'instruments',
            ],
            default => ['resourceType'],
        };
    }

    /**
     * Check if a metadata prefix is supported.
     */
    private function isValidMetadataPrefix(string $prefix): bool
    {
        /** @var array<string, mixed> $formats */
        $formats = config('oaipmh.metadata_formats', []);

        return array_key_exists($prefix, $formats);
    }

    /**
     * Parse an OAI-PMH date string (YYYY-MM-DD or YYYY-MM-DDThh:mm:ssZ).
     *
     * Returns [Carbon, granularity] or [null, null] if the format is invalid.
     * When $isUntil is true and the granularity is date-only, the time is set
     * to end-of-day (23:59:59) to make the range inclusive per OAI-PMH spec.
     *
     * @return array{0: Carbon|null, 1: string|null}
     */
    private function parseOaiDate(string $dateStr, bool $isUntil = false): array
    {
        // Full datetime: YYYY-MM-DDThh:mm:ssZ
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $dateStr)) {
            return [Carbon::parse($dateStr)->utc(), 'datetime'];
        }

        // Date only: YYYY-MM-DD
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
            $date = Carbon::parse($dateStr)->utc();

            return [$isUntil ? $date->endOfDay() : $date->startOfDay(), 'date'];
        }

        return [null, null];
    }
}
