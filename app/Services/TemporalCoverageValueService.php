<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\DataCiteDateNormalizer;
use DateTimeImmutable;
use DateTimeZone;

final class TemporalCoverageValueService
{
    /**
     * @return array{startDate: string, endDate: string, startTime: string, endTime: string, timezone: string, temporalMode: string}
     */
    public function parse(string $value): array
    {
        $value = trim($value);
        $isInterval = str_contains($value, '/');
        [$start, $end] = $isInterval
            ? explode('/', $value, 2)
            : [$value, ''];

        $startParts = $this->parseEndpoint($start);
        $endParts = $this->parseEndpoint($end);
        $hasParsedEndpoint = $startParts['date'] !== '' || $endParts['date'] !== '';

        return [
            'startDate' => $startParts['date'],
            'endDate' => $endParts['date'],
            'startTime' => $startParts['time'],
            'endTime' => $endParts['time'],
            'timezone' => $startParts['timezone'] !== ''
                ? $startParts['timezone']
                : $endParts['timezone'],
            'temporalMode' => $hasParsedEndpoint ? ($isInterval ? 'interval' : 'instant') : '',
        ];
    }

    /**
     * Convert one editor/storage coverage into a DataCite Coverage date.
     *
     * @param  array{startDate?: mixed, endDate?: mixed, startTime?: mixed, endTime?: mixed, timezone?: mixed, temporalMode?: mixed}  $coverage
     */
    public function toDataCiteValue(array $coverage): ?string
    {
        $timezone = $this->normalizeTimezone($coverage['timezone'] ?? null);
        $start = $this->formatEndpoint(
            $coverage['startDate'] ?? null,
            $coverage['startTime'] ?? null,
            $timezone,
        );
        $end = $this->formatEndpoint(
            $coverage['endDate'] ?? null,
            $coverage['endTime'] ?? null,
            $timezone,
        );

        if ($start === '' && $end === '') {
            return null;
        }

        if (($coverage['temporalMode'] ?? null) === 'instant' && $start !== '' && $end === '') {
            return $start;
        }

        return $start.'/'.$end;
    }

    /**
     * @param  array{startDate?: mixed, endDate?: mixed, startTime?: mixed, endTime?: mixed, timezone?: mixed, temporalMode?: mixed}  $coverage
     * @return array{start: string, end: string, mode: 'instant'|'interval'}
     */
    public function toIsoEndpoints(array $coverage): array
    {
        $timezone = $this->normalizeTimezone($coverage['timezone'] ?? null);
        $start = $this->formatEndpoint(
            $coverage['startDate'] ?? null,
            $coverage['startTime'] ?? null,
            $timezone,
            false,
        );
        $end = $this->formatEndpoint(
            $coverage['endDate'] ?? null,
            $coverage['endTime'] ?? null,
            $timezone,
            false,
        );

        return [
            'start' => $start,
            'end' => $end,
            'mode' => ($coverage['temporalMode'] ?? null) === 'instant' && $start !== '' && $end === ''
                ? 'instant'
                : 'interval',
        ];
    }

    public function normalizeTimezone(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $timezone = trim($value);

        if (in_array(strtoupper($timezone), ['Z', 'UTC', 'GMT'], true) || $timezone === '+00:00' || $timezone === '-00:00') {
            return 'UTC';
        }

        if (preg_match('/^[+-]\d{2}:\d{2}$/D', $timezone) === 1) {
            return preg_match('/^[+-](?:0\d|1[0-3]):[0-5]\d$|^[+-]14:00$/D', $timezone) === 1
                ? $timezone
                : null;
        }

        try {
            new DateTimeZone($timezone);

            return $timezone;
        } catch (\Exception) {
            return null;
        }
    }

    /** @return array{date: string, time: string, timezone: string} */
    private function parseEndpoint(string $value): array
    {
        $value = trim($value);

        if ($value === '') {
            return ['date' => '', 'time' => '', 'timezone' => ''];
        }

        $date = DataCiteDateNormalizer::normalize($value);
        if ($date === $value) {
            return ['date' => $date, 'time' => '', 'timezone' => ''];
        }

        if (preg_match(
            '/^(?<date>\d{4}-\d{2}-\d{2})(?:T(?<time>\d{2}:\d{2}(?::\d{2})?)(?:\.\d+)?(?<timezone>Z|[+-]\d{2}:\d{2})?)?$/D',
            $value,
            $matches,
        ) === 1) {
            [$year, $month, $day] = array_map('intval', explode('-', $matches['date']));
            if (! checkdate($month, $day, $year)) {
                return ['date' => '', 'time' => '', 'timezone' => ''];
            }

            $time = $matches['time'] ?? '';
            if ($time !== '') {
                $timeParts = array_map('intval', explode(':', $time));
                if ($timeParts[0] > 23 || $timeParts[1] > 59 || (($timeParts[2] ?? 0) > 59)) {
                    return ['date' => '', 'time' => '', 'timezone' => ''];
                }
            }

            $rawTimezone = $matches['timezone'] ?? '';
            $timezone = $this->normalizeTimezone($rawTimezone);
            if ($rawTimezone !== '' && $timezone === null) {
                return ['date' => '', 'time' => '', 'timezone' => ''];
            }

            if (str_ends_with($time, ':00')) {
                $time = substr($time, 0, 5);
            }

            return [
                'date' => $matches['date'],
                'time' => $time,
                'timezone' => $timezone ?? '',
            ];
        }

        return ['date' => '', 'time' => '', 'timezone' => ''];
    }

    private function formatEndpoint(mixed $dateValue, mixed $timeValue, ?string $timezone, bool $requireTimezoneForTime = true): string
    {
        $date = is_string($dateValue) ? trim($dateValue) : '';
        $time = is_string($timeValue) ? trim($timeValue) : '';

        if (! DataCiteDateNormalizer::isDateOnly($date)) {
            return '';
        }

        if ($time === '') {
            return $date;
        }

        if ($timezone === null) {
            return $requireTimezoneForTime ? $date : $date.'T'.$time;
        }

        $suffix = $this->timezoneSuffix($date, $time, $timezone);

        return $date.'T'.$time.$suffix;
    }

    private function timezoneSuffix(string $date, string $time, string $timezone): string
    {
        if ($timezone === 'UTC') {
            return 'Z';
        }

        if (preg_match('/^[+-]\d{2}:\d{2}$/D', $timezone) === 1) {
            return $timezone;
        }

        try {
            return (new DateTimeImmutable($date.'T'.$time, new DateTimeZone($timezone)))->format('P');
        } catch (\Exception) {
            return '';
        }
    }
}
