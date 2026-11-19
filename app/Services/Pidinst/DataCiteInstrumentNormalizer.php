<?php

declare(strict_types=1);

namespace App\Services\Pidinst;

use InvalidArgumentException;

/** Maps the public DataCite PIDINST mapping without guessing technical subfields. */
final class DataCiteInstrumentNormalizer
{
    public static function doi(mixed $value): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException('Missing DataCite DOI.');
        }
        $doi = preg_replace('~^https?://(?:dx\.)?doi\.org/~i', '', trim($value)) ?? '';
        if (! preg_match('~^10\.\d{4,9}/\S+$~iD', $doi)) {
            throw new InvalidArgumentException('Invalid DataCite DOI.');
        }

        return strtolower($doi);
    }

    /** @param array<string, mixed> $record
     * @return array{id: string, pid: string, pidType: string, name: string, description: string, landingPage: string, owners: list<string>, manufacturers: list<string>, model: null, instrumentTypes: list<string>, measuredVariables: list<string>}
     */
    public function normalize(array $record): array
    {
        $attributes = $record['attributes'] ?? [];
        $doi = self::doi($attributes['doi'] ?? null);
        if (self::doi($record['id'] ?? null) !== $doi || ($attributes['state'] ?? null) !== 'findable'
            || data_get($attributes, 'types.resourceTypeGeneral') !== 'Instrument') {
            throw new InvalidArgumentException('DataCite record is not a matching findable Instrument DOI.');
        }
        $titles = $this->objects($attributes['titles'] ?? [], 'titles');
        $main = $fallback = '';
        foreach ($titles as $title) {
            $name = $this->text($title['title'] ?? null, 'title');
            if ($name !== '') {
                $fallback = $fallback === '' ? $name : $fallback;
                if (empty($title['titleType']) && $main === '') {
                    $main = $name;
                }
            }
        }
        $name = $main !== '' ? $main : $fallback;
        if ($name === '') {
            throw new InvalidArgumentException('DataCite instrument has no non-empty title.');
        }
        $url = $this->text($attributes['url'] ?? '', 'url');
        if ($url !== '' && (filter_var($url, FILTER_VALIDATE_URL) === false || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true))) {
            throw new InvalidArgumentException('Invalid DataCite instrument landing page.');
        }
        $descriptions = ['Abstract' => [], 'TechnicalInfo' => []];
        foreach ($this->objects($attributes['descriptions'] ?? [], 'descriptions') as $description) {
            $text = $this->text($description['description'] ?? '', 'description');
            $type = $description['descriptionType'] ?? null;
            if (is_string($type) && isset($descriptions[$type]) && $text !== '') {
                $descriptions[$type][] = $text;
            }
        }
        $owners = [];
        foreach ($this->objects($attributes['contributors'] ?? [], 'contributors') as $contributor) {
            if (($contributor['contributorType'] ?? null) === 'HostingInstitution') {
                $owners[] = $this->text($contributor['name'] ?? null, 'HostingInstitution name');
            }
        }
        $manufacturers = [];
        foreach ($this->objects($attributes['creators'] ?? [], 'creators') as $creator) {
            $manufacturers[] = $this->text($creator['name'] ?? null, 'creator name');
        }

        return [
            'id' => 'datacite:'.$doi, 'pid' => $doi, 'pidType' => 'DOI', 'name' => $name,
            'description' => implode("\n\n", array_merge($descriptions['Abstract'], $descriptions['TechnicalInfo'])),
            'landingPage' => $url, 'owners' => array_values(array_filter($owners, fn (string $name): bool => $name !== '')),
            'manufacturers' => array_values(array_filter($manufacturers, fn (string $name): bool => $name !== '')),
            'model' => null, 'instrumentTypes' => [], 'measuredVariables' => [],
        ];
    }

    private function text(mixed $value, string $field): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException('Invalid DataCite '.$field.'.');
        }

        return trim($value);
    }

    /** @return list<array<string, mixed>> */
    private function objects(mixed $value, string $field): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException('Invalid DataCite '.$field.' list.');
        }
        foreach ($value as $entry) {
            if (! is_array($entry) || array_is_list($entry)) {
                throw new InvalidArgumentException('Invalid DataCite '.$field.' entry.');
            }
        }

        return $value;
    }
}
