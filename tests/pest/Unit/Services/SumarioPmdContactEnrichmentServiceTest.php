<?php

declare(strict_types=1);

use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Services\SumarioPmdContactEnrichmentService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

describe('SumarioPmdContactEnrichmentService', function () {
    beforeEach(function () {
        Config::set('database.connections.metaworks', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        DB::purge('metaworks');

        Schema::connection('metaworks')->create('resource', function (Blueprint $table): void {
            $table->id();
            $table->string('identifier')->nullable();
        });

        Schema::connection('metaworks')->create('resourceagent', function (Blueprint $table): void {
            $table->integer('resource_id');
            $table->integer('order');
            $table->string('name')->nullable();
            $table->string('firstname')->nullable();
            $table->string('lastname')->nullable();
        });

        Schema::connection('metaworks')->create('contactinfo', function (Blueprint $table): void {
            $table->integer('resourceagent_resource_id');
            $table->integer('resourceagent_order');
            $table->string('email')->nullable();
            $table->string('website')->nullable();
        });
    });

    it('enriches a matching creator with legacy contact email and website', function () {
        DB::connection('metaworks')->table('resource')->insert([
            'id' => 42,
            'identifier' => '10.5880/contact.creator',
        ]);
        DB::connection('metaworks')->table('resourceagent')->insert([
            'resource_id' => 42,
            'order' => 3,
            'name' => 'Doe, Jane',
            'firstname' => 'Jane',
            'lastname' => 'Doe',
        ]);
        DB::connection('metaworks')->table('contactinfo')->insert([
            'resourceagent_resource_id' => 42,
            'resourceagent_order' => 3,
            'email' => 'jane.doe@example.org',
            'website' => 'https://jane.example.org',
        ]);

        $resource = Resource::factory()->create(['doi' => '10.5880/contact.creator']);
        $person = Person::query()->create([
            'given_name' => 'Jane',
            'family_name' => 'Doe',
        ]);
        $creator = ResourceCreator::query()->create([
            'resource_id' => $resource->id,
            'creatorable_type' => Person::class,
            'creatorable_id' => $person->id,
            'position' => 0,
        ]);

        $updated = (new SumarioPmdContactEnrichmentService)->enrich($resource, '10.5880/contact.creator');

        expect($updated)->toBeTrue()
            ->and($creator->fresh()->is_contact)->toBeTrue()
            ->and($creator->fresh()->email)->toBe('jane.doe@example.org')
            ->and($creator->fresh()->website)->toBe('https://jane.example.org');
    });

    it('does not touch the resource when matching creator contact data is unchanged', function () {
        DB::connection('metaworks')->table('resource')->insert([
            'id' => 47,
            'identifier' => '10.5880/contact.creator.unchanged',
        ]);
        DB::connection('metaworks')->table('resourceagent')->insert([
            'resource_id' => 47,
            'order' => 1,
            'name' => 'Stable, Casey',
            'firstname' => 'Casey',
            'lastname' => 'Stable',
        ]);
        DB::connection('metaworks')->table('contactinfo')->insert([
            'resourceagent_resource_id' => 47,
            'resourceagent_order' => 1,
            'email' => 'casey.stable@example.org',
            'website' => 'https://casey.example.org',
        ]);

        $resource = Resource::factory()->create([
            'doi' => '10.5880/contact.creator.unchanged',
            'updated_at' => now()->subDay(),
        ]);
        $originalUpdatedAt = $resource->updated_at;
        $person = Person::query()->create([
            'given_name' => 'Casey',
            'family_name' => 'Stable',
        ]);
        $creator = ResourceCreator::query()->create([
            'resource_id' => $resource->id,
            'creatorable_type' => Person::class,
            'creatorable_id' => $person->id,
            'position' => 0,
            'is_contact' => true,
            'email' => 'casey.stable@example.org',
            'website' => 'https://casey.example.org',
        ]);

        $updated = (new SumarioPmdContactEnrichmentService)->enrich($resource, '10.5880/contact.creator.unchanged');

        expect($updated)->toBeFalse()
            ->and($creator->fresh()->wasChanged(['is_contact', 'email', 'website']))->toBeFalse()
            ->and($resource->fresh()->updated_at?->equalTo($originalUpdatedAt))->toBeTrue();
    });

    it('ignores invalid legacy contact email and unsafe website values', function () {
        DB::connection('metaworks')->table('resource')->insert([
            'id' => 44,
            'identifier' => '10.5880/contact.invalid',
        ]);
        DB::connection('metaworks')->table('resourceagent')->insert([
            'resource_id' => 44,
            'order' => 1,
            'name' => 'Risk, Riley',
            'firstname' => 'Riley',
            'lastname' => 'Risk',
        ]);
        DB::connection('metaworks')->table('contactinfo')->insert([
            'resourceagent_resource_id' => 44,
            'resourceagent_order' => 1,
            'email' => 'not-an-email',
            'website' => 'javascript:alert(1)',
        ]);

        $resource = Resource::factory()->create(['doi' => '10.5880/contact.invalid']);
        $person = Person::query()->create([
            'given_name' => 'Riley',
            'family_name' => 'Risk',
        ]);
        $creator = ResourceCreator::query()->create([
            'resource_id' => $resource->id,
            'creatorable_type' => Person::class,
            'creatorable_id' => $person->id,
            'position' => 0,
        ]);

        $updated = (new SumarioPmdContactEnrichmentService)->enrich($resource, '10.5880/contact.invalid');

        expect($updated)->toBeFalse()
            ->and($creator->fresh()->is_contact)->toBeFalse()
            ->and($creator->fresh()->email)->toBeNull()
            ->and($creator->fresh()->website)->toBeNull();
    });

    it('persists valid legacy contact fields while dropping invalid companion values', function () {
        DB::connection('metaworks')->table('resource')->insert([
            'id' => 45,
            'identifier' => '10.5880/contact.partial',
        ]);
        DB::connection('metaworks')->table('resourceagent')->insert([
            'resource_id' => 45,
            'order' => 1,
            'name' => 'Safe, Sam',
            'firstname' => 'Sam',
            'lastname' => 'Safe',
        ]);
        DB::connection('metaworks')->table('contactinfo')->insert([
            'resourceagent_resource_id' => 45,
            'resourceagent_order' => 1,
            'email' => 'sam.safe@example.org',
            'website' => 'ftp://example.org/profile',
        ]);

        $resource = Resource::factory()->create(['doi' => '10.5880/contact.partial']);
        $person = Person::query()->create([
            'given_name' => 'Sam',
            'family_name' => 'Safe',
        ]);
        $creator = ResourceCreator::query()->create([
            'resource_id' => $resource->id,
            'creatorable_type' => Person::class,
            'creatorable_id' => $person->id,
            'position' => 0,
        ]);

        $updated = (new SumarioPmdContactEnrichmentService)->enrich($resource, '10.5880/contact.partial');

        expect($updated)->toBeTrue()
            ->and($creator->fresh()->is_contact)->toBeTrue()
            ->and($creator->fresh()->email)->toBe('sam.safe@example.org')
            ->and($creator->fresh()->website)->toBeNull();
    });

    it('ignores overlong legacy contact email and website values', function () {
        DB::connection('metaworks')->table('resource')->insert([
            'id' => 46,
            'identifier' => '10.5880/contact.overlong',
        ]);
        DB::connection('metaworks')->table('resourceagent')->insert([
            'resource_id' => 46,
            'order' => 1,
            'name' => 'Long, Logan',
            'firstname' => 'Logan',
            'lastname' => 'Long',
        ]);
        DB::connection('metaworks')->table('contactinfo')->insert([
            'resourceagent_resource_id' => 46,
            'resourceagent_order' => 1,
            'email' => str_repeat('a', 260).'@example.org',
            'website' => 'https://example.org/'.str_repeat('a', 260),
        ]);

        $resource = Resource::factory()->create(['doi' => '10.5880/contact.overlong']);
        $person = Person::query()->create([
            'given_name' => 'Logan',
            'family_name' => 'Long',
        ]);
        $creator = ResourceCreator::query()->create([
            'resource_id' => $resource->id,
            'creatorable_type' => Person::class,
            'creatorable_id' => $person->id,
            'position' => 0,
        ]);

        $updated = (new SumarioPmdContactEnrichmentService)->enrich($resource, '10.5880/contact.overlong');

        expect($updated)->toBeFalse()
            ->and($creator->fresh()->is_contact)->toBeFalse()
            ->and($creator->fresh()->email)->toBeNull()
            ->and($creator->fresh()->website)->toBeNull();
    });

    it('enriches matching contributors with legacy contact information', function () {
        DB::connection('metaworks')->table('resource')->insert([
            'id' => 43,
            'identifier' => '10.5880/contact.contributor',
        ]);
        DB::connection('metaworks')->table('resourceagent')->insert([
            'resource_id' => 43,
            'order' => 1,
            'name' => 'Smith, Alex',
            'firstname' => 'Alex',
            'lastname' => 'Smith',
        ]);
        DB::connection('metaworks')->table('contactinfo')->insert([
            'resourceagent_resource_id' => 43,
            'resourceagent_order' => 1,
            'email' => 'alex.smith@example.org',
            'website' => null,
        ]);

        $resource = Resource::factory()->create(['doi' => '10.5880/contact.contributor']);
        $person = Person::query()->create([
            'given_name' => 'Alex',
            'family_name' => 'Smith',
        ]);
        $contributor = ResourceContributor::query()->create([
            'resource_id' => $resource->id,
            'contributorable_type' => Person::class,
            'contributorable_id' => $person->id,
            'position' => 0,
        ]);

        (new SumarioPmdContactEnrichmentService)->enrich($resource, '10.5880/contact.contributor');

        expect($contributor->fresh()->email)->toBe('alex.smith@example.org')
            ->and($contributor->fresh()->website)->toBeNull();
    });

    it('does not touch the resource when matching contributor contact data is unchanged', function () {
        DB::connection('metaworks')->table('resource')->insert([
            'id' => 48,
            'identifier' => '10.5880/contact.contributor.unchanged',
        ]);
        DB::connection('metaworks')->table('resourceagent')->insert([
            'resource_id' => 48,
            'order' => 1,
            'name' => 'Still, Alex',
            'firstname' => 'Alex',
            'lastname' => 'Still',
        ]);
        DB::connection('metaworks')->table('contactinfo')->insert([
            'resourceagent_resource_id' => 48,
            'resourceagent_order' => 1,
            'email' => 'alex.still@example.org',
            'website' => 'https://alex.example.org',
        ]);

        $resource = Resource::factory()->create([
            'doi' => '10.5880/contact.contributor.unchanged',
            'updated_at' => now()->subDay(),
        ]);
        $originalUpdatedAt = $resource->updated_at;
        $person = Person::query()->create([
            'given_name' => 'Alex',
            'family_name' => 'Still',
        ]);
        $contributor = ResourceContributor::query()->create([
            'resource_id' => $resource->id,
            'contributorable_type' => Person::class,
            'contributorable_id' => $person->id,
            'position' => 0,
            'email' => 'alex.still@example.org',
            'website' => 'https://alex.example.org',
        ]);

        $updated = (new SumarioPmdContactEnrichmentService)->enrich($resource, '10.5880/contact.contributor.unchanged');

        expect($updated)->toBeFalse()
            ->and($contributor->fresh()->wasChanged(['email', 'website']))->toBeFalse()
            ->and($resource->fresh()->updated_at?->equalTo($originalUpdatedAt))->toBeTrue();
    });
});
