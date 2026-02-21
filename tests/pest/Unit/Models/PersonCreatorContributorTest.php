<?php

declare(strict_types=1);

use App\Models\Institution;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;

covers(Person::class, ResourceCreator::class, ResourceContributor::class);

describe('Person model', function (): void {
    it('has correct fillable attributes', function (): void {
        $person = new Person;

        expect($person->getFillable())->toBe([
            'given_name',
            'family_name',
            'name_identifier',
            'name_identifier_scheme',
            'name_identifier_scheme_uri',
        ]);
    });

    it('uses the persons table', function (): void {
        $person = new Person;

        expect($person->getTable())->toBe('persons');
    });

    it('generates full name with family and given name', function (): void {
        $person = Person::factory()->make([
            'given_name' => 'Albert',
            'family_name' => 'Einstein',
        ]);

        expect($person->full_name)->toBe('Einstein, Albert');
    });

    it('returns only family name when given name is null', function (): void {
        $person = Person::factory()->make([
            'given_name' => null,
            'family_name' => 'Einstein',
        ]);

        expect($person->full_name)->toBe('Einstein');
    });

    it('returns only given name when family name is null', function (): void {
        $person = Person::factory()->make([
            'given_name' => 'Albert',
            'family_name' => null,
        ]);

        expect($person->full_name)->toBe('Albert');
    });

    it('returns empty string when both names are null', function (): void {
        $person = Person::factory()->make([
            'given_name' => null,
            'family_name' => null,
        ]);

        expect($person->full_name)->toBe('');
    });

    it('detects ORCID identifier', function (): void {
        $person = Person::factory()->withOrcid('https://orcid.org/0000-0001-2345-6789')->make();

        expect($person->hasOrcid())->toBeTrue();
        expect($person->orcid)->toBe('https://orcid.org/0000-0001-2345-6789');
    });

    it('returns false for hasOrcid when no identifier', function (): void {
        $person = Person::factory()->make([
            'name_identifier' => null,
            'name_identifier_scheme' => null,
        ]);

        expect($person->hasOrcid())->toBeFalse();
        expect($person->orcid)->toBeNull();
    });

    it('returns false for hasOrcid when scheme is not ORCID', function (): void {
        $person = Person::factory()->make([
            'name_identifier' => 'https://isni.org/isni/0000000000000001',
            'name_identifier_scheme' => 'ISNI',
        ]);

        expect($person->hasOrcid())->toBeFalse();
        expect($person->orcid)->toBeNull();
    });
});

describe('Person relationships', function (): void {
    it('has resource creators morphMany relationship', function (): void {
        $person = Person::factory()->create();

        expect($person->resourceCreators())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\MorphMany::class);
    });

    it('has resource contributors morphMany relationship', function (): void {
        $person = Person::factory()->create();

        expect($person->resourceContributors())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\MorphMany::class);
    });
});

describe('ResourceCreator model', function (): void {
    it('has correct fillable attributes', function (): void {
        $creator = new ResourceCreator;

        expect($creator->getFillable())->toBe([
            'resource_id',
            'creatorable_type',
            'creatorable_id',
            'position',
            'is_contact',
            'email',
            'website',
        ]);
    });

    it('casts position to integer', function (): void {
        $creator = new ResourceCreator;
        $casts = $creator->getCasts();

        expect($casts['position'])->toBe('integer');
    });

    it('casts is_contact to boolean', function (): void {
        $creator = new ResourceCreator;
        $casts = $creator->getCasts();

        expect($casts['is_contact'])->toBe('boolean');
    });

    it('detects Person creatorable type', function (): void {
        $creator = ResourceCreator::factory()->make([
            'creatorable_type' => Person::class,
        ]);

        expect($creator->isPerson())->toBeTrue();
        expect($creator->isInstitution())->toBeFalse();
    });

    it('detects Institution creatorable type', function (): void {
        $creator = ResourceCreator::factory()->make([
            'creatorable_type' => Institution::class,
        ]);

        expect($creator->isPerson())->toBeFalse();
        expect($creator->isInstitution())->toBeTrue();
    });

    it('belongs to a resource', function (): void {
        $resource = Resource::factory()->create();
        $person = Person::factory()->create();
        $creator = ResourceCreator::factory()->create([
            'resource_id' => $resource->id,
            'creatorable_type' => Person::class,
            'creatorable_id' => $person->id,
        ]);

        expect($creator->resource)->toBeInstanceOf(Resource::class);
        expect($creator->resource->id)->toBe($resource->id);
    });

    it('morphs to a person', function (): void {
        $person = Person::factory()->create();
        $creator = ResourceCreator::factory()->create([
            'creatorable_type' => Person::class,
            'creatorable_id' => $person->id,
        ]);

        expect($creator->creatorable)->toBeInstanceOf(Person::class);
        expect($creator->creatorable->id)->toBe($person->id);
    });

    it('has affiliations morphMany relationship', function (): void {
        $creator = ResourceCreator::factory()->create();

        expect($creator->affiliations())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\MorphMany::class);
    });
});

describe('ResourceContributor model', function (): void {
    it('has correct fillable attributes', function (): void {
        $contributor = new ResourceContributor;

        expect($contributor->getFillable())->toBe([
            'resource_id',
            'contributorable_type',
            'contributorable_id',
            'position',
        ]);
    });

    it('casts position to integer', function (): void {
        $contributor = new ResourceContributor;
        $casts = $contributor->getCasts();

        expect($casts['position'])->toBe('integer');
    });

    it('detects Person contributorable type', function (): void {
        $contributor = ResourceContributor::factory()->make([
            'contributorable_type' => Person::class,
        ]);

        expect($contributor->isPerson())->toBeTrue();
        expect($contributor->isInstitution())->toBeFalse();
    });

    it('detects Institution contributorable type', function (): void {
        $contributor = ResourceContributor::factory()->make([
            'contributorable_type' => Institution::class,
        ]);

        expect($contributor->isPerson())->toBeFalse();
        expect($contributor->isInstitution())->toBeTrue();
    });

    it('belongs to a resource', function (): void {
        $resource = Resource::factory()->create();
        $person = Person::factory()->create();
        $contributorType = \App\Models\ContributorType::create([
            'name' => 'ContactPerson',
            'slug' => 'contact-person',
            'is_active' => true,
        ]);
        $contributor = ResourceContributor::factory()->create([
            'resource_id' => $resource->id,
            'contributorable_type' => Person::class,
            'contributorable_id' => $person->id,
        ]);
        $contributor->contributorTypes()->sync([$contributorType->id]);

        expect($contributor->resource)->toBeInstanceOf(Resource::class);
        expect($contributor->resource->id)->toBe($resource->id);
    });

    it('has contributor types via pivot table', function (): void {
        $contributorType = \App\Models\ContributorType::create([
            'name' => 'DataCurator',
            'slug' => 'data-curator',
            'is_active' => true,
        ]);
        $contributor = ResourceContributor::factory()->create();
        $contributor->contributorTypes()->sync([$contributorType->id]);
        $contributor->load('contributorTypes');

        expect($contributor->contributorTypes)->toHaveCount(1);
        expect($contributor->contributorTypes->first())->toBeInstanceOf(\App\Models\ContributorType::class);
        expect($contributor->contributorTypes->first()->id)->toBe($contributorType->id);
    });

    it('supports multiple contributor types', function (): void {
        $type1 = \App\Models\ContributorType::create([
            'name' => 'DataCurator',
            'slug' => 'data-curator',
            'is_active' => true,
        ]);
        $type2 = \App\Models\ContributorType::create([
            'name' => 'Editor',
            'slug' => 'editor',
            'is_active' => true,
        ]);
        $contributor = ResourceContributor::factory()->create();
        $contributor->contributorTypes()->sync([$type1->id, $type2->id]);
        $contributor->load('contributorTypes');

        expect($contributor->contributorTypes)->toHaveCount(2);
        expect($contributor->contributorTypes->pluck('slug')->sort()->values()->all())
            ->toBe(['data-curator', 'editor']);
    });

    it('has affiliations morphMany relationship', function (): void {
        $contributor = ResourceContributor::factory()->create();

        expect($contributor->affiliations())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\MorphMany::class);
    });
});
