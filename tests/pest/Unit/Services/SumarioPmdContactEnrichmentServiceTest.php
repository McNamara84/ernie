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
});
