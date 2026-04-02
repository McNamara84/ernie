<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DataCiteApiService;
use Illuminate\Http\JsonResponse;

/**
 * Controller für DOI-Zitations-Abruf.
 *
 * Verwendet die doi.org Content Negotiation API über den DataCiteApiService.
 */
class DataCiteController extends Controller
{
    public function __construct(
        private DataCiteApiService $dataCiteService
    ) {}

    /**
     * Ruft eine formatierte Zitation für eine DOI ab.
     *
     * @param  string  $doi  Die DOI (kann von jedem Registrar sein)
     * @return JsonResponse JSON mit citation und doi
     */
    public function getCitation(string $doi): JsonResponse
    {
        $metadata = $this->dataCiteService->getMetadata($doi);

        if (! $metadata) {
            return response()->json([
                'error' => 'Metadata not found for DOI',
            ], 404);
        }

        $citation = $this->dataCiteService->buildCitationFromMetadata($metadata);

        return response()->json([
            'citation' => $citation,
            'doi' => $doi,
        ]);
    }

    /**
     * Returns structured author data for a DOI, including affiliations and author type.
     *
     * First tries the DataCite REST API (which includes affiliations and nameType).
     * Falls back to CSL JSON via doi.org Content Negotiation for non-DataCite DOIs.
     *
     * @param  string  $doi  The DOI (any registrar)
     * @return JsonResponse JSON with doi and authors array
     */
    public function getAuthors(string $doi): JsonResponse
    {
        // Try DataCite REST API first (includes affiliations)
        $dataCiteMetadata = $this->dataCiteService->getDataCiteMetadata($doi);

        if ($dataCiteMetadata !== null) {
            $authors = $this->extractDataCiteAuthors($dataCiteMetadata);

            return response()->json([
                'doi' => $doi,
                'authors' => $authors,
            ]);
        }

        // Fall back to CSL JSON for non-DataCite DOIs
        $metadata = $this->dataCiteService->getMetadata($doi);

        if (! $metadata) {
            return response()->json([
                'error' => 'Metadata not found for DOI',
            ], 404);
        }

        $authors = $this->extractAuthors($metadata);

        return response()->json([
            'doi' => $doi,
            'authors' => $authors,
        ]);
    }

    /**
     * Extract structured author information from DataCite REST API metadata.
     *
     * Includes affiliations and distinguishes Person vs. Organizational authors.
     *
     * @param  array<string, mixed>  $metadata  DataCite attributes from REST API
     * @return array<int, array{given_name: string|null, family_name: string|null, name: string|null, orcid: string|null, type: string, ror_id: string|null, affiliations: array<int, array{name: string, identifier: string|null, identifier_scheme: string|null}>}>
     */
    private function extractDataCiteAuthors(array $metadata): array
    {
        $rawCreators = $metadata['creators'] ?? [];

        if (! is_array($rawCreators)) {
            return [];
        }

        $authors = [];

        foreach ($rawCreators as $creator) {
            if (! is_array($creator)) {
                continue;
            }

            $nameType = $creator['nameType'] ?? 'Personal';
            $isOrganizational = $nameType === 'Organizational';

            $orcid = $this->extractDataCiteNameIdentifier($creator, 'ORCID');
            $rorId = $isOrganizational ? $this->extractDataCiteNameIdentifier($creator, 'ROR') : null;

            $affiliations = $this->extractDataCiteAffiliations($creator);

            if ($isOrganizational) {
                $authors[] = [
                    'given_name' => null,
                    'family_name' => null,
                    'name' => $creator['name'] ?? null,
                    'orcid' => null,
                    'type' => 'Institution',
                    'affiliations' => $affiliations,
                    'ror_id' => $rorId,
                ];
            } else {
                $authors[] = [
                    'given_name' => $creator['givenName'] ?? null,
                    'family_name' => $creator['familyName'] ?? null,
                    'name' => null,
                    'orcid' => $orcid,
                    'type' => 'Person',
                    'affiliations' => $affiliations,
                    'ror_id' => null,
                ];
            }
        }

        return $authors;
    }

    /**
     * Extract a specific name identifier from a DataCite creator entry.
     *
     * @param  array<string, mixed>  $creator  Single creator from DataCite REST API
     * @param  string  $scheme  The identifier scheme to look for (e.g. 'ORCID', 'ROR')
     */
    private function extractDataCiteNameIdentifier(array $creator, string $scheme): ?string
    {
        $nameIdentifiers = $creator['nameIdentifiers'] ?? [];

        if (! is_array($nameIdentifiers)) {
            return null;
        }

        foreach ($nameIdentifiers as $identifier) {
            if (! is_array($identifier)) {
                continue;
            }

            $identifierScheme = $identifier['nameIdentifierScheme'] ?? '';

            if (strtoupper($identifierScheme) !== strtoupper($scheme)) {
                continue;
            }

            $value = $identifier['nameIdentifier'] ?? '';

            if (! is_string($value) || $value === '') {
                continue;
            }

            if ($scheme === 'ORCID') {
                if (preg_match('/(\d{4}-\d{4}-\d{4}-\d{3}[\dX])/', $value, $matches)) {
                    return $matches[1];
                }

                continue;
            }

            if ($scheme === 'ROR') {
                if (preg_match('/(https:\/\/ror\.org\/[a-z0-9]+)/', $value, $matches)) {
                    return $matches[1];
                }
                // Bare ROR ID
                if (preg_match('/^(0[a-z0-9]{6}\d{2})$/', $value, $matches)) {
                    return "https://ror.org/{$matches[1]}";
                }

                continue;
            }

            // Unknown scheme – return raw value
            return $value;
        }

        return null;
    }

    /**
     * Extract affiliations from a DataCite creator entry.
     *
     * @param  array<string, mixed>  $creator  Single creator from DataCite REST API
     * @return array<int, array{name: string, identifier: string|null, identifier_scheme: string|null}>
     */
    private function extractDataCiteAffiliations(array $creator): array
    {
        $rawAffiliations = $creator['affiliation'] ?? [];

        if (! is_array($rawAffiliations)) {
            return [];
        }

        $affiliations = [];

        foreach ($rawAffiliations as $affiliation) {
            if (is_string($affiliation)) {
                if ($affiliation !== '') {
                    $affiliations[] = [
                        'name' => $affiliation,
                        'identifier' => null,
                        'identifier_scheme' => null,
                    ];
                }

                continue;
            }

            if (! is_array($affiliation)) {
                continue;
            }

            $name = $affiliation['name'] ?? null;

            if (! is_string($name) || $name === '') {
                continue;
            }

            $identifier = $affiliation['affiliationIdentifier'] ?? null;
            $scheme = $affiliation['affiliationIdentifierScheme'] ?? null;

            $affiliations[] = [
                'name' => $name,
                'identifier' => is_string($identifier) && $identifier !== '' ? $identifier : null,
                'identifier_scheme' => is_string($scheme) && $scheme !== '' ? $scheme : null,
            ];
        }

        return $affiliations;
    }

    /**
     * Extract structured author information from CSL JSON metadata.
     *
     * Used as fallback when DataCite REST API is unavailable (non-DataCite DOIs).
     * Returns empty affiliations since CSL JSON doesn't include them.
     *
     * @param  array<string, mixed>  $metadata  CSL JSON metadata from doi.org
     * @return array<int, array{given_name: string|null, family_name: string|null, name: string|null, orcid: string|null, type: string, affiliations: array<mixed>, ror_id: null}>
     */
    private function extractAuthors(array $metadata): array
    {
        $rawAuthors = $metadata['author'] ?? [];

        if (! is_array($rawAuthors)) {
            return [];
        }

        $authors = [];

        foreach ($rawAuthors as $author) {
            if (! is_array($author)) {
                continue;
            }

            $orcid = $this->extractOrcid($author);

            if (isset($author['family'])) {
                $authors[] = [
                    'given_name' => $author['given'] ?? null,
                    'family_name' => $author['family'],
                    'name' => null,
                    'orcid' => $orcid,
                    'type' => 'Person',
                    'affiliations' => [],
                    'ror_id' => null,
                ];
            } elseif (isset($author['literal'])) {
                $authors[] = [
                    'given_name' => null,
                    'family_name' => null,
                    'name' => $author['literal'],
                    'orcid' => $orcid,
                    'type' => 'Institution',
                    'affiliations' => [],
                    'ror_id' => null,
                ];
            }
        }

        return $authors;
    }

    /**
     * Extract ORCID from a CSL JSON author entry.
     *
     * CSL JSON may store ORCID in various fields depending on the registrar.
     *
     * @param  array<string, mixed>  $author  Single author entry from CSL JSON
     */
    private function extractOrcid(array $author): ?string
    {
        // Check common CSL JSON ORCID field names
        $orcidValue = $author['ORCID'] ?? $author['orcid'] ?? null;

        if (! is_string($orcidValue) || $orcidValue === '') {
            return null;
        }

        // Normalize: extract the ORCID ID from a full URL if needed
        if (preg_match('/(\d{4}-\d{4}-\d{4}-\d{3}[\dX])/', $orcidValue, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
