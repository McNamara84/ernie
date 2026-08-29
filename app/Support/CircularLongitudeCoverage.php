<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Builds the minimum circular longitude interval covering points and arcs.
 *
 * Longitudes are normalized only at the API boundary. Internally, intervals
 * use an unwrapped 0..360 representation so antimeridian-crossing coverage is
 * never mistaken for its much larger complement.
 */
final class CircularLongitudeCoverage
{
    private const EPSILON = 1.0E-9;

    /** @var list<float> */
    private array $points = [];

    /** @var list<array{0: float, 1: float}> */
    private array $segments = [];

    private bool $coversWorld = false;

    public function add(float $west, float $east): void
    {
        if ($this->coversWorld) {
            return;
        }

        $span = self::span($west, $east);
        if ($span >= 360.0 - self::EPSILON) {
            $this->coversWorld = true;
            $this->points = [];
            $this->segments = [];

            return;
        }

        $start = self::toCircle($west);
        if ($span <= self::EPSILON) {
            $this->points[] = $start;

            return;
        }

        $end = $start + $span;
        if ($end <= 360.0 + self::EPSILON) {
            $this->segments[] = [$start, min(360.0, $end)];

            return;
        }

        $this->segments[] = [$start, 360.0];
        $this->segments[] = [0.0, $end - 360.0];
    }

    /**
     * @return array{west: float, east: float}|null
     */
    public function bounds(): ?array
    {
        if ($this->coversWorld) {
            return ['west' => -180.0, 'east' => 180.0];
        }

        $coverage = $this->segments;
        foreach ($this->points as $point) {
            $coverage[] = [$point, $point];
        }

        if ($coverage === []) {
            return null;
        }

        usort($coverage, static fn (array $left, array $right): int => $left[0] <=> $right[0]);

        $firstStart = $coverage[0][0];
        $currentEnd = $coverage[0][1];
        $largestGap = -1.0;
        $resultWest = null;
        $resultEast = null;

        foreach (array_slice($coverage, 1) as [$start, $end]) {

            if ($start <= $currentEnd + self::EPSILON) {
                $currentEnd = max($currentEnd, $end);

                continue;
            }

            $gap = $start - $currentEnd;
            if ($gap > $largestGap) {
                $largestGap = $gap;
                $resultWest = $start;
                $resultEast = $currentEnd;
            }

            $currentEnd = $end;
        }

        $wrapGap = ($firstStart + 360.0) - $currentEnd;
        if ($wrapGap > $largestGap) {
            $largestGap = $wrapGap;
            $resultWest = $firstStart;
            $resultEast = $currentEnd;
        }

        if ($largestGap <= self::EPSILON || $resultWest === null || $resultEast === null) {
            return ['west' => -180.0, 'east' => 180.0];
        }

        return [
            'west' => self::normalize($resultWest),
            'east' => self::normalize($resultEast),
        ];
    }

    /**
     * @return array{west: float, east: float}
     */
    public static function merge(float $firstWest, float $firstEast, float $secondWest, float $secondEast): array
    {
        $coverage = new self;
        $coverage->add($firstWest, $firstEast);
        $coverage->add($secondWest, $secondEast);

        return $coverage->bounds() ?? ['west' => -180.0, 'east' => 180.0];
    }

    public static function span(float $west, float $east): float
    {
        $difference = $east - $west;
        if (abs($difference) >= 360.0 - self::EPSILON) {
            return 360.0;
        }

        $span = fmod($difference, 360.0);

        return $span < 0.0 ? $span + 360.0 : $span;
    }

    public static function midpoint(float $west, float $east): float
    {
        return self::normalize($west + (self::span($west, $east) / 2.0));
    }

    public static function nearestCopy(float $longitude, float $reference): float
    {
        $normalized = self::normalize($longitude);

        return $normalized + (360.0 * round(($reference - $normalized) / 360.0));
    }

    public static function normalize(float $longitude): float
    {
        $normalized = fmod($longitude + 180.0, 360.0);
        if ($normalized < 0.0) {
            $normalized += 360.0;
        }

        return $normalized - 180.0;
    }

    private static function toCircle(float $longitude): float
    {
        $normalized = fmod($longitude, 360.0);

        return $normalized < 0.0 ? $normalized + 360.0 : $normalized;
    }
}
