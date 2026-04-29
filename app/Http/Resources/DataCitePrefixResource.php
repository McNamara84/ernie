<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * DataCite prefix configuration payload.
 *
 * Output shape:
 *  - test: list<string>
 *  - production: list<string>
 *  - test_mode: bool
 */
final class DataCitePrefixResource extends JsonResource
{
    /**
     * Disable the default "data" wrapper to keep the existing frontend
     * contract (`{ test, production, test_mode }`).
     *
     * @var string|null
     */
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $payload */
        $payload = $this->resource;

        return [
            'test' => $payload['test'] ?? [],
            'production' => $payload['production'] ?? [],
            'test_mode' => (bool) ($payload['test_mode'] ?? true),
        ];
    }
}
