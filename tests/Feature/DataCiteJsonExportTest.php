<?php

use App\Models\Language;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\ResourceType;
use App\Models\Title;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
});

describe('DataCite JSON Export Route', function () {
    test('requires authentication', function () {
        $resource = Resource::factory()->create();

        $response = $this->get(route('resources.export-datacite-json', $resource));

        $response->assertStatus(302)
            ->assertRedirect(route('login'));
    });

    test('returns 404 for non-existent resource', function () {
        $this->actingAs($this->user);

        $response = $this->get(route('resources.export-datacite-json', 99999));

        $response->assertStatus(404);
    });

    test('exports DataCite JSON with correct headers', function () {
        $this->actingAs($this->user);

        $resource = Resource::factory()->create(['publication_year' => 2024]);
        $resourceType = ResourceType::where('name', 'Dataset')->first();
        $resource->resource_type_id = $resourceType->id;
        $resource->save();

        // Add required creator
        $person = Person::factory()->create([
            'given_name' => 'Test',
            'family_name' => 'Creator',
        ]);
        ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_id' => $person->id,
            'creatorable_type' => Person::class,
            'position' => 1,
        ]);

        // Add required title
        Title::factory()->create([
            'resource_id' => $resource->id,
            'value' => 'Test Resource',
        ]);

        $response = $this->get(route('resources.export-datacite-json', $resource));

        $response->assertStatus(200)
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeader('Content-Disposition');

        // Verify filename format
        $contentDisposition = $response->headers->get('Content-Disposition');
        expect($contentDisposition)->toContain('attachment; filename=')
            ->and($contentDisposition)->toContain("resource-{$resource->id}-")
            ->and($contentDisposition)->toContain('-datacite.json');
    });

    test('exports valid JSON structure', function () {
        $this->actingAs($this->user);

        $resource = Resource::factory()->create(['publication_year' => 2024]);
        $resourceType = ResourceType::where('name', 'Software')->first();
        $resource->resource_type_id = $resourceType->id;
        $resource->save();

        // Add required fields
        $person = Person::factory()->create();
        ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_id' => $person->id,
            'creatorable_type' => Person::class,
            'position' => 1,
        ]);

        Title::factory()->create(['resource_id' => $resource->id]);

        $response = $this->get(route('resources.export-datacite-json', $resource));

        $response->assertStatus(200);

        $json = $response->json();

        expect($json)->toBeArray()
            ->and($json)->toHaveKey('data')
            ->and($json['data'])->toHaveKey('type')
            ->and($json['data']['type'])->toBe('dois')
            ->and($json['data'])->toHaveKey('attributes')
            ->and($json['data']['attributes'])->toHaveKeys([
                'titles',
                'creators',
                'publisher',
                'publicationYear',
                'types',
            ]);
    });

    test('exports correct filename format with timestamp', function () {
        $this->actingAs($this->user);

        $resource = Resource::factory()->create(['publication_year' => 2024]);
        $resourceType = ResourceType::where('name', 'Other')->first();
        $resource->resource_type_id = $resourceType->id;
        $resource->save();

        // Add minimum required data
        $person = Person::factory()->create();
        ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_id' => $person->id,
            'creatorable_type' => Person::class,
            'position' => 1,
        ]);

        Title::factory()->create(['resource_id' => $resource->id]);

        $before = now()->format('YmdHis');
        $response = $this->get(route('resources.export-datacite-json', $resource));
        $after = now()->format('YmdHis');

        $contentDisposition = $response->headers->get('Content-Disposition');

        // Extract filename from Content-Disposition header
        preg_match('/filename=(.+)/', $contentDisposition, $matches);
        $filename = $matches[1] ?? '';

        expect($filename)->toContain("resource-{$resource->id}-")
            ->and($filename)->toContain('-datacite.json');

        // Extract timestamp from filename
        preg_match('/resource-\d+-(\d{14})-datacite\.json/', $filename, $timestampMatches);
        $timestamp = $timestampMatches[1] ?? '';

        expect($timestamp)->toBeGreaterThanOrEqual($before)
            ->and($timestamp)->toBeLessThanOrEqual($after);
    });

    test('exports resource with multiple creators', function () {
        $this->actingAs($this->user);

        $resource = Resource::factory()->create(['publication_year' => 2024]);

        // Add creators
        $person1 = Person::factory()->create(['given_name' => 'Alice', 'family_name' => 'Smith']);
        $person2 = Person::factory()->create(['given_name' => 'Bob', 'family_name' => 'Jones']);

        ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_id' => $person1->id,
            'creatorable_type' => Person::class,
            'position' => 1,
        ]);

        ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_id' => $person2->id,
            'creatorable_type' => Person::class,
            'position' => 2,
        ]);

        Title::factory()->create(['resource_id' => $resource->id]);

        $response = $this->get(route('resources.export-datacite-json', $resource));

        $json = $response->json();

        expect($json['data']['attributes']['creators'])->toHaveCount(2)
            ->and($json['data']['attributes']['creators'][0]['name'])->toBe('Smith, Alice')
            ->and($json['data']['attributes']['creators'][1]['name'])->toBe('Jones, Bob');
    });

    test('exports resource with all optional fields', function () {
        $this->actingAs($this->user);

        $resource = Resource::factory()->create([
            'publication_year' => 2024,
            'doi' => '10.82433/TEST-123',
            'version' => '1.0.0',
        ]);

        $language = Language::factory()->create(['iso_code' => 'en']);
        $resource->language_id = $language->id;
        $resource->save();

        // Add creator
        $person = Person::factory()->create();
        ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_id' => $person->id,
            'creatorable_type' => Person::class,
            'position' => 1,
        ]);

        // Add title
        Title::factory()->create([
            'resource_id' => $resource->id,
            'value' => 'Main Title',
            'language' => 'en',
        ]);

        $response = $this->get(route('resources.export-datacite-json', $resource));

        $json = $response->json();

        expect($json['data']['attributes'])->toHaveKey('doi')
            ->and($json['data']['attributes']['doi'])->toBe('10.82433/TEST-123')
            ->and($json['data']['attributes'])->toHaveKey('language')
            ->and($json['data']['attributes']['language'])->toBe('en')
            ->and($json['data']['attributes'])->toHaveKey('version')
            ->and($json['data']['attributes']['version'])->toBe('1.0.0');
    });

    test('handles concurrent export requests', function () {
        $this->actingAs($this->user);

        $resource1 = Resource::factory()->create(['publication_year' => 2024]);
        $resource2 = Resource::factory()->create(['publication_year' => 2024]);

        foreach ([$resource1, $resource2] as $resource) {
            $person = Person::factory()->create();
            ResourceCreator::create([
                'resource_id' => $resource->id,
                'creatorable_id' => $person->id,
                'creatorable_type' => Person::class,
                'position' => 1,
            ]);
            Title::factory()->create(['resource_id' => $resource->id]);
        }

        $response1 = $this->get(route('resources.export-datacite-json', $resource1));
        $response2 = $this->get(route('resources.export-datacite-json', $resource2));

        $response1->assertStatus(200);
        $response2->assertStatus(200);

        $json1 = $response1->json();
        $json2 = $response2->json();

        // Verify they are different exports
        expect($json1)->not->toBe($json2);
    });
});
