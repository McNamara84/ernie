<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Models\DateType;
use App\Models\DescriptionType;
use App\Models\Institution;
use App\Models\Person;
use App\Models\RelatedItem;
use App\Models\RelatedItemTitle;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\Right;

final class SchemaOrgExampleMetadata
{
    public static function addTo(Resource $resource): void
    {
        $person = Person::factory()->create(['given_name' => 'Ada', 'family_name' => 'Lovelace']);
        $institution = Institution::factory()->create(['name' => 'Example Observatory']);
        ResourceCreator::factory()->forPerson($person)->position(0)->create(['resource_id' => $resource->id]);
        ResourceCreator::factory()->forInstitution($institution)->position(1)->create(['resource_id' => $resource->id]);
        foreach (['Issued' => '2025-06-01', 'Created' => '2024-01-01', 'Updated' => '2025-05-01', 'Collected' => '2024-01-01'] as $slug => $value) {
            $type = DateType::firstOrCreate(['slug' => $slug], ['name' => $slug]);
            $resource->dates()->create(['date_type_id' => $type->id, 'date_value' => $value]);
        }
        $abstract = DescriptionType::firstOrCreate(['slug' => 'Abstract'], ['name' => 'Abstract']);
        $resource->descriptions()->create(['description_type_id' => $abstract->id, 'value' => 'Synthetic research metadata for Schema.org validation.']);
        $resource->subjects()->create(['value' => 'Geology']);
        $resource->subjects()->create([
            'value' => 'Rock',
            'subject_scheme' => 'Science Keywords',
            'scheme_uri' => 'https://example.org/vocabulary',
            'value_uri' => 'https://example.org/vocabulary/rock',
        ]);
        $right = Right::firstOrCreate(['identifier' => 'CC-BY-4.0'], [
            'name' => 'Creative Commons Attribution 4.0 International',
            'uri' => 'https://creativecommons.org/licenses/by/4.0/',
            'scheme_uri' => 'https://spdx.org/licenses/',
        ]);
        $resource->rights()->attach($right->id);
        $resource->geoLocations()->create(['place' => 'Potsdam', 'point_latitude' => 52.4, 'point_longitude' => 13.0]);
        $resource->fundingReferences()->create(['funder_name' => 'Example Foundation', 'award_number' => 'EXAMPLE-1', 'award_title' => 'Example research grant']);
        $item = RelatedItem::factory()->withIdentifier('10.1234/example-citation')->create([
            'resource_id' => $resource->id, 'publisher' => 'Example Publisher', 'publication_year' => 2023,
        ]);
        RelatedItemTitle::factory()->create(['related_item_id' => $item->id, 'title' => 'Related research', 'title_type' => 'MainTitle']);
    }
}
