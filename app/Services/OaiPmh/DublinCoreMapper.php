<?php

declare(strict_types=1);

namespace App\Services\OaiPmh;

use App\Models\Resource;

/**
 * Maps a Resource model to Dublin Core (DC) elements for OAI-PMH oai_dc format.
 *
 * @see http://www.openarchives.org/OAI/2.0/oai_dc.xsd
 * @see http://purl.org/dc/elements/1.1/
 */
class DublinCoreMapper
{
    /**
     * Map a Resource to an associative array of DC elements.
     *
     * @return array<string, list<string>>
     */
    public function map(Resource $resource): array
    {
        /** @var array<string, list<string>> $dc */
        $dc = [];

        // dc:title
        $titles = $resource->titles->pluck('value')->filter()->values()->all();
        if ($titles !== []) {
            $dc['title'] = $titles;
        }

        // dc:creator
        $creators = $resource->creators
            ->map(fn ($creator) => $this->formatCreatorName($creator))
            ->filter()
            ->values()
            ->all();
        if ($creators !== []) {
            $dc['creator'] = $creators;
        }

        // dc:subject
        $subjects = $resource->subjects->pluck('value')->filter()->values()->all();
        if ($subjects !== []) {
            $dc['subject'] = $subjects;
        }

        // dc:description (prefer Abstract type)
        $descriptions = $resource->descriptions
            ->sortByDesc(fn ($d) => $d->descriptionType->slug === 'Abstract' ? 1 : 0)
            ->pluck('description')
            ->filter()
            ->values()
            ->all();
        if ($descriptions !== []) {
            $dc['description'] = $descriptions;
        }

        // dc:publisher
        $publisherName = $resource->publisher?->name;
        if ($publisherName !== null && $publisherName !== '') {
            $dc['publisher'] = [$publisherName];
        }

        // dc:contributor
        $contributors = $resource->contributors
            ->map(fn ($contributor) => $this->formatCreatorName($contributor))
            ->filter()
            ->values()
            ->all();
        if ($contributors !== []) {
            $dc['contributor'] = $contributors;
        }

        // dc:date (publication year in W3CDTF)
        if ($resource->publication_year !== null) {
            $dc['date'] = [(string) $resource->publication_year];
        }

        // dc:type
        $typeName = $resource->resourceType?->name;
        if ($typeName !== null && $typeName !== '') {
            $dc['type'] = [$typeName];
        }

        // dc:format
        $formats = $resource->formats->pluck('value')->filter()->values()->all();
        if ($formats !== []) {
            $dc['format'] = $formats;
        }

        // dc:identifier (DOI as URL)
        if ($resource->doi !== null && $resource->doi !== '') {
            $dc['identifier'] = ["https://doi.org/{$resource->doi}"];
        }

        // dc:language
        $langCode = $resource->language?->code;
        if ($langCode !== null && $langCode !== '') {
            $dc['language'] = [$langCode];
        }

        // dc:relation (related identifiers as URLs where possible)
        $relations = $resource->relatedIdentifiers
            ->map(fn ($ri) => $ri->identifier)
            ->filter()
            ->values()
            ->all();
        if ($relations !== []) {
            $dc['relation'] = $relations;
        }

        // dc:coverage (geo locations as text)
        $coverages = $resource->geoLocations
            ->map(fn ($geo) => $this->formatGeoLocation($geo))
            ->filter()
            ->values()
            ->all();
        if ($coverages !== []) {
            $dc['coverage'] = $coverages;
        }

        // dc:rights
        $rights = $resource->rights
            ->map(fn ($r) => $r->uri !== null && $r->uri !== '' ? "{$r->name} ({$r->uri})" : $r->name)
            ->filter()
            ->values()
            ->all();
        if ($rights !== []) {
            $dc['rights'] = $rights;
        }

        /** @var array<string, list<string>> $dc */
        return $dc;
    }

    /**
     * Format a creator/contributor name from the polymorphic relation.
     */
    private function formatCreatorName(mixed $author): ?string
    {
        $entity = $author->creatorable ?? $author->contributorable ?? null;

        if ($entity === null) {
            return null;
        }

        // Person: "LastName, FirstName"
        if ($entity instanceof \App\Models\Person) {
            $parts = array_filter([$entity->family_name, $entity->given_name]);

            return $parts !== [] ? implode(', ', $parts) : null;
        }

        // Institution: name
        if ($entity instanceof \App\Models\Institution) {
            return $entity->name !== '' ? $entity->name : null;
        }

        return null;
    }

    /**
     * Format a GeoLocation as a human-readable coverage string.
     */
    private function formatGeoLocation(mixed $geo): ?string
    {
        $parts = [];

        if ($geo->place !== null && $geo->place !== '') {
            $parts[] = $geo->place;
        }

        if ($geo->point_latitude !== null && $geo->point_longitude !== null) {
            $parts[] = "Point({$geo->point_latitude}, {$geo->point_longitude})";
        }

        if ($geo->box_south !== null && $geo->box_west !== null
            && $geo->box_north !== null && $geo->box_east !== null) {
            $parts[] = "Box({$geo->box_south}, {$geo->box_west}, {$geo->box_north}, {$geo->box_east})";
        }

        return $parts !== [] ? implode('; ', $parts) : null;
    }
}
