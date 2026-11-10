<?php

declare(strict_types=1);

namespace App\Services\DcatAp;

use App\Models\GeoLocation;
use App\Services\Iso19115\Iso19115JsonGeometryMapper;

final class DcatApGeometryMapper
{
    public function __construct(private readonly Iso19115JsonGeometryMapper $geometry) {}

    /** @return array{wkt: string, property: string}|null */
    public function map(GeoLocation $location): ?array
    {
        $geometry = $this->geometry->geometry($location);
        if ($geometry === null) {
            return null;
        }
        $depth = match ($geometry['type']) {
            'Point' => 0, 'LineString' => 1, 'Polygon' => 2, 'MultiPolygon' => 3,
            default => throw new \LogicException('Unsupported geometry'),
        };
        $wkt = '<http://www.opengis.net/def/crs/OGC/1.3/CRS84> '.strtoupper($geometry['type']).' ';
        $wkt .= $depth === 0 ? '('.$this->coordinates($geometry['coordinates'], 0).')' : $this->coordinates($geometry['coordinates'], $depth);

        return ['wkt' => $wkt, 'property' => $geometry['type'] === 'Point' ? 'dcat:centroid' : ($location->hasBox() ? 'dcat:bbox' : 'locn:geometry')];
    }

    /** @param array<mixed> $coordinates */
    private function coordinates(array $coordinates, int $depth): string
    {
        if ($depth === 0) {
            return implode(' ', array_map(static fn ($value): string => rtrim(rtrim(sprintf('%.8F', (float) $value), '0'), '.'), $coordinates));
        }

        return '('.implode(', ', array_map(fn (array $part): string => $this->coordinates($part, $depth - 1), $coordinates)).')';
    }
}
