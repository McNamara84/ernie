<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Description;
use App\Models\DescriptionType;
use App\Models\LandingPage;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\Right;
use App\Models\TitleType;
use App\Models\User;
use App\Policies\ResourcePolicy;

function createNonDraftResourceForPolicy(): Resource
{
    $resource = Resource::factory()->create([
        'doi' => null,
    ]);

    $titleType = TitleType::firstOrCreate([
        'slug' => 'MainTitle',
    ], [
        'name' => 'Main Title',
    ]);

    $resource->titles()->create([
        'value' => 'Complete resource',
        'title_type_id' => $titleType->id,
    ]);

    $creator = Person::create([
        'family_name' => 'Example',
        'given_name' => 'Author',
    ]);

    ResourceCreator::create([
        'resource_id' => $resource->id,
        'creatorable_type' => Person::class,
        'creatorable_id' => $creator->id,
        'position' => 0,
    ]);

    $right = Right::firstOrCreate([
        'identifier' => 'cc-by-4.0',
    ], [
        'name' => 'CC-BY 4.0',
    ]);
    $resource->rights()->attach($right->id);

    $abstractType = DescriptionType::firstOrCreate([
        'slug' => 'Abstract',
    ], [
        'name' => 'Abstract',
    ]);

    Description::create([
        'resource_id' => $resource->id,
        'value' => 'Abstract',
        'description_type_id' => $abstractType->id,
    ]);

    return $resource->fresh([
        'titles.titleType',
        'creators',
        'rights',
        'descriptions.descriptionType',
        'landingPage',
    ]);
}

describe('ResourcePolicy', function () {
    beforeEach(function () {
        $this->policy = new ResourcePolicy;
        $this->resource = Resource::factory()->create([
            'doi' => null,
        ]);
    });

    describe('viewAny', function () {
        it('allows admin to view any resources', function () {
            $user = User::factory()->create(['role' => UserRole::ADMIN]);
            expect($this->policy->viewAny($user))->toBeTrue();
        });

        it('allows group leader to view any resources', function () {
            $user = User::factory()->create(['role' => UserRole::GROUP_LEADER]);
            expect($this->policy->viewAny($user))->toBeTrue();
        });

        it('allows curator to view any resources', function () {
            $user = User::factory()->create(['role' => UserRole::CURATOR]);
            expect($this->policy->viewAny($user))->toBeTrue();
        });

        it('allows beginner to view any resources', function () {
            $user = User::factory()->create(['role' => UserRole::BEGINNER]);
            expect($this->policy->viewAny($user))->toBeTrue();
        });
    });

    describe('view', function () {
        it('allows admin to view a resource', function () {
            $user = User::factory()->create(['role' => UserRole::ADMIN]);
            expect($this->policy->view($user, $this->resource))->toBeTrue();
        });

        it('allows group leader to view a resource', function () {
            $user = User::factory()->create(['role' => UserRole::GROUP_LEADER]);
            expect($this->policy->view($user, $this->resource))->toBeTrue();
        });

        it('allows curator to view a resource', function () {
            $user = User::factory()->create(['role' => UserRole::CURATOR]);
            expect($this->policy->view($user, $this->resource))->toBeTrue();
        });

        it('allows beginner to view a resource', function () {
            $user = User::factory()->create(['role' => UserRole::BEGINNER]);
            expect($this->policy->view($user, $this->resource))->toBeTrue();
        });
    });

    describe('create', function () {
        it('allows admin to create resources', function () {
            $user = User::factory()->create(['role' => UserRole::ADMIN]);
            expect($this->policy->create($user))->toBeTrue();
        });

        it('allows group leader to create resources', function () {
            $user = User::factory()->create(['role' => UserRole::GROUP_LEADER]);
            expect($this->policy->create($user))->toBeTrue();
        });

        it('allows curator to create resources', function () {
            $user = User::factory()->create(['role' => UserRole::CURATOR]);
            expect($this->policy->create($user))->toBeTrue();
        });

        it('allows beginner to create resources', function () {
            $user = User::factory()->create(['role' => UserRole::BEGINNER]);
            expect($this->policy->create($user))->toBeTrue();
        });
    });

    describe('update', function () {
        it('allows admin to update a resource', function () {
            $user = User::factory()->create(['role' => UserRole::ADMIN]);
            expect($this->policy->update($user, $this->resource))->toBeTrue();
        });

        it('allows group leader to update a resource', function () {
            $user = User::factory()->create(['role' => UserRole::GROUP_LEADER]);
            expect($this->policy->update($user, $this->resource))->toBeTrue();
        });

        it('allows curator to update a resource', function () {
            $user = User::factory()->create(['role' => UserRole::CURATOR]);
            expect($this->policy->update($user, $this->resource))->toBeTrue();
        });

        it('allows beginner to update a resource', function () {
            $user = User::factory()->create(['role' => UserRole::BEGINNER]);
            expect($this->policy->update($user, $this->resource))->toBeTrue();
        });
    });

    describe('delete', function () {
        it('short-circuits before loading relations for users who cannot delete drafts', function () {
            $user = User::factory()->create(['role' => UserRole::BEGINNER]);

            /** @var Resource&\Mockery\MockInterface $resource */
            $resource = \Mockery::mock(Resource::class);
            $resource->shouldNotReceive('loadMissing');
            $resource->shouldNotReceive('publicStatus');

            expect($this->policy->delete($user, $resource))->toBeFalse();
        });

        it('loads relations only after the role check passes', function () {
            $user = User::factory()->create(['role' => UserRole::ADMIN]);

            /** @var Resource&\Mockery\MockInterface $resource */
            $resource = \Mockery::mock(Resource::class);
            $resource->shouldReceive('getAttribute')->with('doi')->once()->andReturn(null);
            $resource->shouldReceive('loadMissing')->once()->with([
                'titles.titleType',
                'creators',
                'rights',
                'descriptions.descriptionType',
                'landingPage',
            ])->andReturnSelf();
            $resource->shouldReceive('publicStatus')->once()->andReturn('draft');

            expect($this->policy->delete($user, $resource))->toBeTrue();
        });

        it('allows admin to delete a draft resource', function () {
            $user = User::factory()->create(['role' => UserRole::ADMIN]);
            expect($this->policy->delete($user, $this->resource))->toBeTrue();
        });

        it('denies admin from deleting a draft resource with a persistent identifier', function () {
            $user = User::factory()->create(['role' => UserRole::ADMIN]);
            $resource = Resource::factory()->create([
                'doi' => '10.5880/test.2026.001',
            ]);

            expect($resource->publicStatus())->toBe('draft');
            expect($this->policy->delete($user, $resource))->toBeFalse();
        });

        it('allows group leader to delete a draft resource', function () {
            $user = User::factory()->create(['role' => UserRole::GROUP_LEADER]);
            expect($this->policy->delete($user, $this->resource))->toBeTrue();
        });

        it('allows curator to delete a draft resource', function () {
            $user = User::factory()->create(['role' => UserRole::CURATOR]);
            expect($this->policy->delete($user, $this->resource))->toBeTrue();
        });

        it('denies admin from deleting a non-draft resource', function () {
            $user = User::factory()->create(['role' => UserRole::ADMIN]);
            $resource = createNonDraftResourceForPolicy();

            expect($resource->publicStatus())->not->toBe('draft');
            expect($this->policy->delete($user, $resource))->toBeFalse();
        });

        it('denies group leader from deleting a non-draft resource', function () {
            $user = User::factory()->create(['role' => UserRole::GROUP_LEADER]);
            $resource = createNonDraftResourceForPolicy();

            expect($resource->publicStatus())->not->toBe('draft');
            expect($this->policy->delete($user, $resource))->toBeFalse();
        });

        it('denies curator from deleting a non-draft resource', function () {
            $user = User::factory()->create(['role' => UserRole::CURATOR]);
            $resource = createNonDraftResourceForPolicy();

            expect($resource->publicStatus())->not->toBe('draft');
            expect($this->policy->delete($user, $resource))->toBeFalse();
        });

        it('denies beginner from deleting a resource', function () {
            $user = User::factory()->create(['role' => UserRole::BEGINNER]);
            expect($this->policy->delete($user, $this->resource))->toBeFalse();
        });
    });

    describe('importFromDataCite', function () {
        it('allows admin to import from DataCite', function () {
            $user = User::factory()->create(['role' => UserRole::ADMIN]);
            expect($this->policy->importFromDataCite($user))->toBeTrue();
        });

        it('allows group leader to import from DataCite', function () {
            $user = User::factory()->create(['role' => UserRole::GROUP_LEADER]);
            expect($this->policy->importFromDataCite($user))->toBeTrue();
        });

        it('denies curator from importing from DataCite', function () {
            $user = User::factory()->create(['role' => UserRole::CURATOR]);
            expect($this->policy->importFromDataCite($user))->toBeFalse();
        });

        it('denies beginner from importing from DataCite', function () {
            $user = User::factory()->create(['role' => UserRole::BEGINNER]);
            expect($this->policy->importFromDataCite($user))->toBeFalse();
        });
    });

    describe('changeDoi', function () {
        it('allows DOI change if DOI is not actually changing', function () {
            $user = User::factory()->create(['role' => UserRole::CURATOR]);
            $this->resource->doi = '10.5880/test.001';
            expect($this->policy->changeDoi($user, $this->resource, '10.5880/test.001'))->toBeTrue();
        });

        it('allows DOI change if resource has no landing page', function () {
            $user = User::factory()->create(['role' => UserRole::CURATOR]);
            $this->resource->doi = '10.5880/old.001';
            expect($this->policy->changeDoi($user, $this->resource, '10.5880/new.001'))->toBeTrue();
        });

        it('allows DOI change if landing page is not published', function () {
            $user = User::factory()->create(['role' => UserRole::CURATOR]);
            $this->resource->doi = '10.5880/old.001';
            LandingPage::factory()->withoutDoi()->create([
                'resource_id' => $this->resource->id,
                'is_published' => false,
            ]);
            $this->resource->refresh();
            expect($this->policy->changeDoi($user, $this->resource, '10.5880/new.001'))->toBeTrue();
        });

        it('denies curator from changing DOI on published landing page', function () {
            $user = User::factory()->create(['role' => UserRole::CURATOR]);
            $this->resource->doi = '10.5880/old.001';
            LandingPage::factory()->create([
                'resource_id' => $this->resource->id,
                'doi_prefix' => '10.5880/old.001',
                'is_published' => true,
            ]);
            $this->resource->refresh();
            expect($this->policy->changeDoi($user, $this->resource, '10.5880/new.001'))->toBeFalse();
        });

        it('allows admin to change DOI on published landing page', function () {
            $user = User::factory()->create(['role' => UserRole::ADMIN]);
            $this->resource->doi = '10.5880/old.001';
            LandingPage::factory()->create([
                'resource_id' => $this->resource->id,
                'doi_prefix' => '10.5880/old.001',
                'is_published' => true,
            ]);
            $this->resource->refresh();
            expect($this->policy->changeDoi($user, $this->resource, '10.5880/new.001'))->toBeTrue();
        });

        it('denies group leader from changing DOI on published landing page', function () {
            $user = User::factory()->create(['role' => UserRole::GROUP_LEADER]);
            $this->resource->doi = '10.5880/old.001';
            LandingPage::factory()->create([
                'resource_id' => $this->resource->id,
                'doi_prefix' => '10.5880/old.001',
                'is_published' => true,
            ]);
            $this->resource->refresh();
            expect($this->policy->changeDoi($user, $this->resource, '10.5880/new.001'))->toBeFalse();
        });
    });
});
