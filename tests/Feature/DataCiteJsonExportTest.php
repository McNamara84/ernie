<?php

use App\Models\Language;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceAuthor;
use App\Models\ResourceTitle;
use App\Models\ResourceType;
use App\Models\Role;
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

        $resource = Resource::factory()->create(['year' => 2024]);
        $resourceType = ResourceType::where('name', 'Dataset')->first();
        $resource->resource_type_id = $resourceType->id;
        $resource->save();

        // Add required creator
        $person = Person::factory()->create([
            'first_name' => 'Test',
            'last_name' => 'Creator',
        ]);
        $authorRole = Role::where('name', 'Author')->first();
        $resourceAuthor = ResourceAuthor::create([
            'resource_id' => $resource->id,
            'authorable_id' => $person->id,
            'authorable_type' => Person::class,
            'position' => 1,
        ]);
        $resourceAuthor->roles()->attach($authorRole);

        // Add required title
        ResourceTitle::factory()->create([
            'resource_id' => $resource->id,
            'title' => 'Test Resource',
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

        $resource = Resource::factory()->create(['year' => 2024]);
        $resourceType = ResourceType::where('name', 'Software')->first();
        $resource->resource_type_id = $resourceType->id;
        $resource->save();

        // Add required fields
        $person = Person::factory()->create();
        $authorRole = Role::where('name', 'Author')->first();
        $resourceAuthor = ResourceAuthor::create([
            'resource_id' => $resource->id,
            'authorable_id' => $person->id,
            'authorable_type' => Person::class,
            'position' => 1,
        ]);
        $resourceAuthor->roles()->attach($authorRole);

        ResourceTitle::factory()->create(['resource_id' => $resource->id]);

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

        $resource = Resource::factory()->create(['year' => 2024]);
        $resourceType = ResourceType::where('name', 'Other')->first();
        $resource->resource_type_id = $resourceType->id;
        $resource->save();

        // Add minimum required data
        $person = Person::factory()->create();
        $authorRole = Role::where('name', 'Author')->first();
        $resourceAuthor = ResourceAuthor::create([
            'resource_id' => $resource->id,
            'authorable_id' => $person->id,
            'authorable_type' => Person::class,
            'position' => 1,
        ]);
        $resourceAuthor->roles()->attach($authorRole);

        ResourceTitle::factory()->create(['resource_id' => $resource->id]);

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

    test('exports resource with multiple creators and contributors', function () {
        $this->actingAs($this->user);

        $resource = Resource::factory()->create(['year' => 2024]);

        // Add creators
        $person1 = Person::factory()->create(['first_name' => 'Alice', 'last_name' => 'Smith']);
        $person2 = Person::factory()->create(['first_name' => 'Bob', 'last_name' => 'Jones']);

        $authorRole = Role::where('name', 'Author')->first();
        
        $creator1 = ResourceAuthor::create([
            'resource_id' => $resource->id,
            'authorable_id' => $person1->id,
            'authorable_type' => Person::class,
            'position' => 1,
        ]);
        $creator1->roles()->attach($authorRole);

        $creator2 = ResourceAuthor::create([
            'resource_id' => $resource->id,
            'authorable_id' => $person2->id,
            'authorable_type' => Person::class,
            'position' => 2,
        ]);
        $creator2->roles()->attach($authorRole);

        // Add contributor
        $person3 = Person::factory()->create(['first_name' => 'Charlie', 'last_name' => 'Brown']);
        $contactRole = Role::where('name', 'Contact Person')->first();
        
        $contributor = ResourceAuthor::create([
            'resource_id' => $resource->id,
            'authorable_id' => $person3->id,
            'authorable_type' => Person::class,
            'position' => 3,
        ]);
        $contributor->roles()->attach($contactRole);

        ResourceTitle::factory()->create(['resource_id' => $resource->id]);

        $response = $this->get(route('resources.export-datacite-json', $resource));

        $json = $response->json();

        expect($json['data']['attributes']['creators'])->toHaveCount(2)
            ->and($json['data']['attributes']['creators'][0]['name'])->toBe('Smith, Alice')
            ->and($json['data']['attributes']['creators'][1]['name'])->toBe('Jones, Bob')
            ->and($json['data']['attributes']['contributors'])->toHaveCount(1)
            ->and($json['data']['attributes']['contributors'][0]['name'])->toBe('Brown, Charlie')
            ->and($json['data']['attributes']['contributors'][0]['contributorType'])->toBe('ContactPerson');
    });

    test('exports resource with all optional fields', function () {
        $this->actingAs($this->user);

        $resource = Resource::factory()->create([
            'year' => 2024,
            'doi' => '10.82433/TEST-123',
            'version' => '1.0.0',
        ]);

        $language = Language::factory()->create(['iso_code' => 'en']);
        $resource->language_id = $language->id;
        $resource->save();

        // Add creator
        $person = Person::factory()->create();
        $authorRole = Role::where('name', 'Author')->first();
        $resourceAuthor = ResourceAuthor::create([
            'resource_id' => $resource->id,
            'authorable_id' => $person->id,
            'authorable_type' => Person::class,
            'position' => 1,
        ]);
        $resourceAuthor->roles()->attach($authorRole);

        // Add titles
        ResourceTitle::factory()->create([
            'resource_id' => $resource->id,
            'title' => 'Main Title',
            'language_id' => $language->id,
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

        $resource1 = Resource::factory()->create(['year' => 2024]);
        $resource2 = Resource::factory()->create(['year' => 2024]);

        foreach ([$resource1, $resource2] as $resource) {
            $person = Person::factory()->create();
            $authorRole = Role::where('name', 'Author')->first();
            $resourceAuthor = ResourceAuthor::create([
                'resource_id' => $resource->id,
                'authorable_id' => $person->id,
                'authorable_type' => Person::class,
                'position' => 1,
            ]);
            $resourceAuthor->roles()->attach($authorRole);
            ResourceTitle::factory()->create(['resource_id' => $resource->id]);
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
