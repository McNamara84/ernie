<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Citations\CitationLookupResult;
use App\Services\Citations\CitationLookupService;
use App\Services\DoiSuggestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CitationLookupController extends Controller
{
    public function __construct(
        private readonly CitationLookupService $lookup,
        private readonly DoiSuggestionService $doiSuggestions,
    ) {}

    public function lookup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'doi' => [
                'required',
                'string',
                'max:512',
                // Reject obvious garbage early so the upstream Crossref/DataCite
                // calls (and their rate-limit budgets) are not wasted on input
                // that cannot possibly be a DOI. Mirrors the OpenAPI 422 contract.
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || ! $this->doiSuggestions->isValidDoiFormat($value)) {
                        $fail('The :attribute must be a valid DOI (e.g., 10.1234/example).');
                    }
                },
            ],
        ]);

        $result = $this->lookup->lookup($validated['doi']);

        // Transient upstream failure (Crossref timeout / non-2xx with no
        // confirming DataCite hit) → 502 so the UI can show a retryable
        // error instead of a misleading "not found".
        if ($result->error !== null) {
            return response()->json([
                'source' => $result->source,
                'identifier' => $validated['doi'],
                'identifier_type' => 'DOI',
                'message' => 'Upstream metadata provider is temporarily unavailable. Please try again.',
                'error' => $result->error,
            ], 502);
        }

        return response()->json($this->present($result, $validated['doi']));
    }

    /**
     * Convert the internal {@see CitationLookupResult} (camelCase, envelope)
     * to the flat snake_case shape consumed by the Citation Manager UI and
     * documented in the OpenAPI specification.
     *
     * @return array<string, mixed>
     */
    private function present(CitationLookupResult $result, string $requestedDoi): array
    {
        if (! $result->found || $result->data === null) {
            return [
                'source' => 'not_found',
                'identifier' => $requestedDoi,
                'identifier_type' => 'DOI',
            ];
        }

        $data = $result->data;

        $titles = is_array($data['titles'] ?? null) ? $data['titles'] : [];
        $mainTitle = null;
        $subtitle = null;
        foreach ($titles as $t) {
            if (! is_array($t) || ! isset($t['title']) || ! is_string($t['title'])) {
                continue;
            }
            $type = is_string($t['titleType'] ?? null) ? $t['titleType'] : 'MainTitle';
            if ($mainTitle === null && $type === 'MainTitle') {
                $mainTitle = $t['title'];
            } elseif ($subtitle === null && $type === 'Subtitle') {
                $subtitle = $t['title'];
            }
        }

        $creators = [];
        foreach ((is_array($data['creators'] ?? null) ? $data['creators'] : []) as $c) {
            if (! is_array($c)) {
                continue;
            }
            $creators[] = [
                'name' => is_string($c['name'] ?? null) ? $c['name'] : '',
                'name_type' => is_string($c['nameType'] ?? null) ? $c['nameType'] : 'Personal',
                'given_name' => is_string($c['givenName'] ?? null) ? $c['givenName'] : null,
                'family_name' => is_string($c['familyName'] ?? null) ? $c['familyName'] : null,
                'name_identifier' => is_string($c['nameIdentifier'] ?? null) ? $c['nameIdentifier'] : null,
                'name_identifier_scheme' => is_string($c['nameIdentifierScheme'] ?? null) ? $c['nameIdentifierScheme'] : null,
            ];
        }

        return [
            'source' => $result->source,
            'identifier' => is_string($data['identifier'] ?? null) ? $data['identifier'] : $requestedDoi,
            'identifier_type' => is_string($data['identifierType'] ?? null) ? $data['identifierType'] : 'DOI',
            'related_item_type' => is_string($data['relatedItemType'] ?? null) ? $data['relatedItemType'] : null,
            'title' => $mainTitle,
            'subtitle' => $subtitle,
            'publication_year' => is_int($data['publicationYear'] ?? null) ? $data['publicationYear'] : null,
            'publisher' => is_string($data['publisher'] ?? null) ? $data['publisher'] : null,
            'volume' => is_string($data['volume'] ?? null) ? $data['volume'] : null,
            'issue' => is_string($data['issue'] ?? null) ? $data['issue'] : null,
            'first_page' => is_string($data['firstPage'] ?? null) ? $data['firstPage'] : null,
            'last_page' => is_string($data['lastPage'] ?? null) ? $data['lastPage'] : null,
            'creators' => $creators,
        ];
    }
}
