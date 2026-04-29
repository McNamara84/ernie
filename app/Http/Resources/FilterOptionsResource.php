<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Filter option payload for the Resources index page.
 *
 * Wraps a plain associative array with keys:
 *  - resource_types: list<array{name: string, slug: string}>
 *  - curators: list<string>
 *  - year_range: array{min: int, max: int}
 *  - statuses: list<string>
 */
final class FilterOptionsResource extends JsonResource
{
    /**
     * Disable the default "data" wrapper so the response shape stays
     * backwards-compatible with the existing frontend contract.
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

        // Mirror ResourceFilterController::loadYearRange(): when no usable
        // year range is available we fall back to the current year so the
        // documented {min:int, max:int} contract holds and frontend consumers
        // (resources.ts, OldDatasetsFilters) never receive nulls they would
        // crash on.
        $currentYear = (int) now()->year;

        return [
            'resource_types' => $payload['resource_types'] ?? [],
            'curators' => $payload['curators'] ?? [],
            'year_range' => $payload['year_range'] ?? ['min' => $currentYear, 'max' => $currentYear],
            'statuses' => $payload['statuses'] ?? [],
        ];
    }
}
