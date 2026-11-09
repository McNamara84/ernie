<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Models\DateType;
use App\Models\DescriptionType;
use App\Models\IdentifierType;
use App\Models\Institution;
use App\Models\LandingPage;
use App\Models\Language;
use App\Models\Person;
use App\Models\Publisher;
use App\Models\RelationType;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\ResourceType;
use App\Models\Title;

/** Public DataCite snapshots, loaded as ERNIE aggregates without remote lookups. */
final class DcatApExampleMetadata
{
    public static function load(string $name): Resource
    {
        $source = json_decode(file_get_contents(__DIR__.'/DcatAp/'.$name.'.json'), true, flags: JSON_THROW_ON_ERROR)['data']['attributes'];
        $type = ResourceType::firstOrCreate(['slug' => strtolower($source['types']['resourceTypeGeneral'])], ['name' => $source['types']['resourceTypeGeneral'], 'is_active' => true]);
        $language = empty($source['language']) ? null : Language::firstOrCreate(['code' => $source['language']], ['name' => $source['language'], 'active' => true]);
        $publisher = is_array($source['publisher']) ? $source['publisher']['name'] : $source['publisher'];
        $resource = Resource::factory()->create(['doi' => $source['doi'], 'resource_type_id' => $type->id, 'language_id' => $language?->id,
            'publication_year' => $source['publicationYear'], 'version' => $source['version'] ?? null,
            'publisher_id' => Publisher::firstOrCreate(['name' => $publisher])->id]);
        foreach ($source['titles'] as $title) {
            if (! isset($title['titleType'])) {
                Title::factory()->create(['resource_id' => $resource->id, 'value' => $title['title'], 'language' => $title['lang'] ?? null]);
            }
        }
        foreach ($source['descriptions'] ?? [] as $description) {
            $type = DescriptionType::firstOrCreate(['slug' => $description['descriptionType']], ['name' => $description['descriptionType']]);
            $resource->descriptions()->create(['value' => $description['description'], 'description_type_id' => $type->id, 'language' => $description['lang'] ?? null]);
        }
        foreach ($source['creators'] ?? [] as $position => $creator) {
            $identifier = $creator['nameIdentifiers'][0] ?? [];
            $fields = ['name_identifier' => $identifier['nameIdentifier'] ?? null, 'name_identifier_scheme' => $identifier['nameIdentifierScheme'] ?? null];
            $party = ($creator['nameType'] ?? null) === 'Organizational' ? Institution::create(['name' => $creator['name'], ...$fields])
                : Person::create(['family_name' => $creator['familyName'] ?? $creator['name'], 'given_name' => $creator['givenName'] ?? null, ...$fields]);
            ResourceCreator::create(['resource_id' => $resource->id, 'creatorable_type' => $party::class, 'creatorable_id' => $party->id, 'position' => $position, 'name_snapshot' => $creator['name']]);
        }
        foreach ($source['subjects'] ?? [] as $subject) {
            $resource->subjects()->create(['value' => $subject['subject'], 'subject_scheme' => $subject['subjectScheme'] ?? null,
                'scheme_uri' => $subject['schemeUri'] ?? null, 'value_uri' => $subject['valueUri'] ?? null, 'language' => $subject['lang'] ?? null]);
        }
        foreach ($source['dates'] ?? [] as $date) {
            $type = DateType::firstOrCreate(['slug' => $date['dateType']], ['name' => $date['dateType']]);
            $resource->dates()->create(['date_type_id' => $type->id, 'date_value' => $date['date']]);
        }
        foreach ($source['rightsList'] ?? [] as $right) {
            $resource->resourceRights()->create(['rights_text' => $right['rights'] ?? null, 'rights_uri' => $right['rightsUri'] ?? null, 'rights_identifier' => $right['rightsIdentifier'] ?? null]);
        }
        foreach ($source['relatedIdentifiers'] ?? [] as $position => $relation) {
            $identifierType = IdentifierType::firstOrCreate(['slug' => $relation['relatedIdentifierType']], ['name' => $relation['relatedIdentifierType']]);
            $relationType = RelationType::firstOrCreate(['slug' => $relation['relationType']], ['name' => $relation['relationType']]);
            $resource->relatedIdentifiers()->create(['identifier' => $relation['relatedIdentifier'], 'identifier_type_id' => $identifierType->id, 'relation_type_id' => $relationType->id, 'position' => $position]);
        }
        foreach ($source['geoLocations'] ?? [] as $position => $location) {
            $point = $location['geoLocationPoint'] ?? [];
            $box = $location['geoLocationBox'] ?? [];
            $resource->geoLocations()->create(['place' => $location['geoLocationPlace'] ?? null, 'position' => $position,
                'point_longitude' => $point['pointLongitude'] ?? null, 'point_latitude' => $point['pointLatitude'] ?? null,
                'west_bound_longitude' => $box['westBoundLongitude'] ?? null, 'east_bound_longitude' => $box['eastBoundLongitude'] ?? null,
                'south_bound_latitude' => $box['southBoundLatitude'] ?? null, 'north_bound_latitude' => $box['northBoundLatitude'] ?? null]);
        }
        LandingPage::factory()->published()->create(['resource_id' => $resource->id, 'doi_prefix' => $resource->doi, 'slug' => $name]);

        return $resource->refresh();
    }
}
