<?php

declare(strict_types=1);

namespace App\Services\Citations;

use App\Models\Institution;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\ResourceType;

/**
 * Maps a Resource to the CSL-JSON item shared by all landing-page styles.
 */
final class LandingPageCslItemMapper
{
    /**
     * DataCite resourceTypeGeneral to the closest unambiguous CSL item type.
     *
     * Types without a faithful CSL equivalent deliberately fall back to
     * "document" and retain their DataCite meaning in "genre".
     *
     * @var array<string, string>
     */
    private const TYPE_MAP = [
        'Audiovisual' => 'motion_picture',
        'Book' => 'book',
        'BookChapter' => 'chapter',
        'Collection' => 'collection',
        'ComputationalNotebook' => 'software',
        'ConferencePaper' => 'paper-conference',
        'ConferenceProceeding' => 'book',
        'DataPaper' => 'article-journal',
        'Dataset' => 'dataset',
        'Dissertation' => 'thesis',
        'Event' => 'event',
        'Image' => 'graphic',
        'InteractiveResource' => 'webpage',
        'Journal' => 'periodical',
        'JournalArticle' => 'article-journal',
        'OutputManagementPlan' => 'report',
        'PeerReview' => 'review',
        'Preprint' => 'manuscript',
        'Report' => 'report',
        'Service' => 'webpage',
        'Software' => 'software',
        'Standard' => 'standard',
        'Text' => 'document',
        'Workflow' => 'software',
    ];

    /**
     * @var array<string, string>
     */
    private const DOCUMENT_GENRES = [
        'Award' => 'Award',
        'Instrument' => 'Instrument',
        'Model' => 'Model',
        'Other' => 'Other',
        'PhysicalObject' => 'Physical object',
        'Project' => 'Project',
        'Sound' => 'Sound',
        'StudyRegistration' => 'Study registration',
    ];

    /**
     * @return array<string, mixed>
     */
    public function map(Resource $resource): array
    {
        $doi = $this->normalizeDoi($resource->doi);
        [$type, $genre] = $this->mapType($resource->resourceType);

        $item = [
            'id' => $doi ?? 'ernie-resource-'.($resource->getKey() ?? 'unpersisted'),
            'type' => $type,
        ];

        $this->addString($item, 'genre', $genre);
        $this->addString($item, 'title', $resource->main_title);

        $authors = $resource->creators
            ->sortBy('position')
            ->map(fn (ResourceCreator $creator): ?array => $this->mapCreator($creator))
            ->filter()
            ->values()
            ->all();

        if ($authors !== []) {
            $item['author'] = $authors;
        }

        if ($resource->publication_year !== null) {
            $item['issued'] = [
                'date-parts' => [[(int) $resource->publication_year]],
            ];
        }

        $this->addString($item, 'publisher', $resource->publisher?->name);

        if ($doi !== null) {
            $item['DOI'] = $doi;
            $item['URL'] = 'https://doi.org/'.$doi;
        }

        $this->addString($item, 'version', $resource->version);
        $this->addString($item, 'language', $resource->language?->code);

        return $item;
    }

    /**
     * @return array{string, string|null}
     */
    private function mapType(?ResourceType $resourceType): array
    {
        if ($resourceType === null) {
            return ['document', null];
        }

        $dataCiteType = $resourceType->dataciteResourceTypeGeneral();

        if (isset(self::TYPE_MAP[$dataCiteType])) {
            return [self::TYPE_MAP[$dataCiteType], null];
        }

        if (isset(self::DOCUMENT_GENRES[$dataCiteType])) {
            return ['document', self::DOCUMENT_GENRES[$dataCiteType]];
        }

        return ['document', $this->nonEmptyString($resourceType->name) ?? $dataCiteType];
    }

    /**
     * @return array<string, string>|null
     */
    private function mapCreator(ResourceCreator $creator): ?array
    {
        $creatorable = $creator->creatorable;

        if ($creatorable instanceof Person) {
            $person = [];
            $this->addString($person, 'family', $creatorable->family_name);
            $this->addString($person, 'given', $creatorable->given_name);

            return $person !== [] ? $person : null;
        }

        if ($creatorable instanceof Institution) {
            $name = $this->nonEmptyString($creatorable->name);

            return $name !== null ? ['literal' => $name] : null;
        }

        return null;
    }

    private function normalizeDoi(?string $doi): ?string
    {
        $doi = $this->nonEmptyString($doi);

        if ($doi === null) {
            return null;
        }

        $doi = preg_replace('/^doi:\s*/i', '', $doi) ?? $doi;

        if (preg_match('/^https?:\/\/(?:dx\.)?doi\.org\/(.+)$/i', $doi, $matches) === 1) {
            $doi = $matches[1];
        }

        $doi = trim($doi);

        return $doi !== '' ? strtolower($doi) : null;
    }

    /**
     * @param  array<string, mixed>  $target
     */
    private function addString(array &$target, string $key, ?string $value): void
    {
        $value = $this->nonEmptyString($value);

        if ($value !== null) {
            $target[$key] = $value;
        }
    }

    private function nonEmptyString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
