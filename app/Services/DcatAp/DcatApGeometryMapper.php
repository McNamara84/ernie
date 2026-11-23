<?php

declare(strict_types=1);

namespace App\Services\DcatAp;

use App\Models\GeoLocation;
use App\Services\Iso19115\Iso19115JsonGeometryMapper;

final class DcatApGeometryMapper
{
    public function __construct(private readonly Iso19115JsonGeometryMapper $geometry) {}

    /** @return array{wkt: string, property: string}|null */
    public function map(GeoLocation $location, bool $boundsOnly = false): ?array
    {
        $geometry = $this->geometry->geometry($location);
        if ($geometry === null) {
            return null;
        }
        $property = $geometry['type'] === 'Point' ? 'dcat:centroid' : ($location->hasBox() ? 'dcat:bbox' : 'locn:geometry');
        if ($boundsOnly && ($location->hasLine() || $location->hasPolygon())) {
            $points = $geometry['type'] === 'Polygon' ? $geometry['coordinates'][0] : $geometry['coordinates'];
            $geometry = $this->boundingBox($points);
            $property = 'dcat:bbox';
        }
        $depth = match ($geometry['type']) {
            'Point' => 0, 'LineString' => 1, 'Polygon', 'MultiLineString' => 2, 'MultiPolygon' => 3,
            default => throw new \LogicException('Unsupported geometry'),
        };
        $wkt = '<http://www.opengis.net/def/crs/OGC/1.3/CRS84> '.strtoupper($geometry['type']).' ';
        $wkt .= $depth === 0 ? '('.$this->coordinates($geometry['coordinates'], 0).')' : $this->coordinates($geometry['coordinates'], $depth);

        return ['wkt' => $wkt, 'property' => $property];
    }

    /**
     * Follow the vertices across the dateline before computing the envelope.
     *
     * @param  non-empty-list<array{float, float}>  $points
     * @return array{type: string, coordinates: array<mixed>}
     */
    private function boundingBox(array $points): array
    {
        $previous = $west = $east = $points[0][0];
        foreach ($points as [$longitude]) {
            $longitude += 360 * round(($previous - $longitude) / 360);
            $west = min($west, $longitude);
            $east = max($east, $longitude);
            $previous = $longitude;
        }
        $south = min(array_column($points, 1));
        $north = max(array_column($points, 1));
        if ($east - $west >= 360) {
            $west = -180.0;
            $east = 180.0;
        } else {
            $offset = 360 * floor(($west + 180) / 360);
            $west -= $offset;
            $east -= $offset;
            if ($east > 180) {
                $east -= 360;
            }
        }
        // When all vertices lie on one meridian or parallel the envelope loses a dimension.
        if ($south === $north && $west === $east) {
            return ['type' => 'Point', 'coordinates' => [$west, $south]];
        }
        if ($south === $north || $west === $east) {
            if ($west > $east) {
                return ['type' => 'MultiLineString', 'coordinates' => [
                    [[$west, $south], [180.0, $north]], [[-180.0, $south], [$east, $north]],
                ]];
            }

            return ['type' => 'LineString', 'coordinates' => [[$west, $south], [$east, $north]]];
        }

        return $this->geometry->box($west, $south, $east, $north);
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
