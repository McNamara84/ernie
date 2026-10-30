<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IdentifierValidationRequest;
use App\Support\Swhid;
use Illuminate\Http\JsonResponse;

class IdentifierValidationController extends Controller
{
    public function __invoke(IdentifierValidationRequest $request): JsonResponse
    {
        /** @var string $identifier */
        $identifier = $request->validated('identifier');
        $result = Swhid::validate($identifier);
        $parsed = $result['parsed'];

        return response()->json([
            'identifierType' => 'SWHID',
            'profile' => Swhid::PROFILE,
            'valid' => $result['valid'],
            'normalized' => $result['normalized'],
            'core' => $parsed['core'] ?? null,
            'objectType' => $parsed['objectType'] ?? null,
            'qualifiers' => $parsed === null ? null : (object) $parsed['qualifiers'],
            'resolverUrl' => Swhid::resolveUrl($identifier),
            'errors' => $result['errors'],
        ])->header('Cache-Control', 'no-store');
    }
}
