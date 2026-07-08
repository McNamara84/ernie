<?php

declare(strict_types=1);

namespace App\Services\Legacy;

class LegacyCoverageGeometryParserService
{
    /**
     * Parse legacy coverage geometry into ERNIE line points.
     *
     * Legacy SUMARIOPMD records mostly store lines as bare coordinate chains
     * (`lon lat lon lat ...`) rather than standards-compliant WKT. This parser
     * accepts those chains, comma-separated variants, and LINESTRING WKT.
     *
     * @return list<array{lat: float, lon: float}>|null
     */
    public function parseLine(mixed $geometry): ?array
    {
        if (! is_string($geometry) && ! is_numeric($geometry)) {
            return null;
        }

        $value = trim((string) $geometry);

        if ($value === '') {
            return null;
        }

        $payload = $this->extractCoordinatePayload($value);

        if ($payload === null) {
            return null;
        }

        $normalised = str_replace([',', "\r", "\n", "\t"], ' ', $payload);
        $tokens = preg_split('/\s+/', trim($normalised));

        if (! is_array($tokens) || count($tokens) < 4 || count($tokens) % 2 !== 0) {
            return null;
        }

        $points = [];

        for ($index = 0; $index < count($tokens); $index += 2) {
            $lon = $tokens[$index];
            $lat = $tokens[$index + 1];

            if (! is_numeric($lon) || ! is_numeric($lat)) {
                return null;
            }

            $lonFloat = (float) $lon;
            $latFloat = (float) $lat;

            if ($lonFloat < -180.0 || $lonFloat > 180.0 || $latFloat < -90.0 || $latFloat > 90.0) {
                return null;
            }

            $points[] = [
                'lat' => $latFloat,
                'lon' => $lonFloat,
            ];
        }

        return count($points) >= 2 ? $points : null;
    }

    private function extractCoordinatePayload(string $value): ?string
    {
        $trimmed = trim($value);

        if (preg_match('/^LINESTRING\s*\((.*)\)$/is', $trimmed, $matches) === 1) {
            return trim($matches[1]);
        }

        if (preg_match('/^MULTILINESTRING\s*\(\s*\((.*)\)\s*\)$/is', $trimmed, $matches) === 1) {
            return trim((string) preg_replace('/\)\s*,\s*\(/', ' ', $matches[1]));
        }

        if (preg_match('/[A-Za-z]/', $trimmed) === 1) {
            return null;
        }

        return $trimmed;
    }
}
