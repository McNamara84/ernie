<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RelatedIdentifier\ResolveCitationLabelRequest;
use App\Services\Citations\RelatedIdentifierCitationLabelService;
use App\Services\DataCiteApiService;
use Illuminate\Http\JsonResponse;

class RelatedIdentifierCitationLabelController extends Controller
{
    public function __construct(
        private readonly RelatedIdentifierCitationLabelService $citationLabels,
        private readonly DataCiteApiService $dataCite,
    ) {}

    public function resolve(ResolveCitationLabelRequest $request): JsonResponse
    {
        /** @var array{identifier: string, identifierType: 'DOI'|'URL'} $validated */
        $validated = $request->validated();
        $identifierType = $validated['identifierType'];
        $identifier = $identifierType === 'DOI'
            ? $this->dataCite->normalizeDoi($validated['identifier'])
            : $validated['identifier'];

        if (! is_string($identifier) || $identifier === '') {
            return response()->json([
                'error' => 'The identifier is invalid.',
            ], 422);
        }

        $citation = $this->citationLabels->resolve($identifier, $identifierType);

        if (! is_string($citation) || trim($citation) === '') {
            return response()->json([
                'error' => 'No citation label could be resolved for this identifier.',
            ], 404);
        }

        return response()->json([
            'citation' => trim($citation),
            'identifier' => $identifier,
            'identifier_type' => $identifierType,
        ]);
    }
}
