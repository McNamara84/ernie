<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
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
use Mockery\MockInterface;

function createNonDraftResourceForPolicy(): Resource
{
    $resource = Resource::factory()->create([
        'doi' => null,
        'access_level' => AccessLevel::OPEN,
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

function createReviewResourceForPolicy(string $doi = '10.5880/test.review.policy'): Resource
{
    $resource = createNonDraftResourceForPolicy();
    $resource->update(['doi' => $doi]);

    LandingPage::factory()->withDoi($doi)->draft()->create([
        'resource_id' => $resource->id,
    ]);

    return $resource->fresh([
        'titles.titleType',
        'creators',
        'rights',
        'descriptions.descriptionType',
        'landingPage',
    ]);
}

function createPublishedResourceForPolicy(string $doi = '10.5880/test.published.policy'): Resource
{
    $resource = createNonDraftResourceForPolicy();
    $resource->update(['doi' => $doi]);

    LandingPage::factory()->withDoi($doi)->published()->create([
        'resource_id' => $resource->id,
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
        it('short-circuits before loading relations for users who cannot delete resources', function () {
            $user = User::factory()->create(['role' => UserRole::BEGINNER]);

            /** @var Resource&MockInterface $resource */
            $resource = Mockery::mock(Resource::class);
            $resource->shouldNotReceive('loadMissing');
            $resource->shouldNotReceive('publicStatus');

            expect($this->policy->delete($user, $resource))->toBeFalse();
        });

        it('loads status relations only for curators', function () {
            $user = User::factory()->create(['role' => UserRole::CURATOR]);

            /** @var Resource&MockInterface $resource */
            $resource = Mockery::mock(Resource::class);
            $resource->shouldReceive('loadMissing')->once()->with([
                'landingPage',
                'titles.titleType',
                'creators',
                'rights',
                'descriptions.descriptionType',
            ])->andReturnSelf();
            $resource->shouldReceive('publicStatus')->once()->andReturn('draft');

            expect($this->policy->delete($user, $resource))->toBeTrue();
        });

        it('allows privileged roles without loading status relations', function (UserRole $role) {
            $user = User::factory()->create(['role' => $role]);

            /** @var Resource&MockInterface $resource */
            $resource = Mockery::mock(Resource::class);
            $resource->shouldNotReceive('loadMissing');
            $resource->shouldNotReceive('publicStatus');

            expect($this->policy->delete($user, $resource))->toBeTrue();
        })->with([
            'admin' => UserRole::ADMIN,
            'group leader' => UserRole::GROUP_LEADER,
        ]);

        it('allows admin to delete a draft resource', function () {
            $user = User::factory()->create(['role' => UserRole::ADMIN]);
            expect($this->policy->delete($user, $this->resource))->toBeTrue();
        });

        it('allows admin to delete a draft resource with a persistent identifier', function () {
            $user = User::factory()->create(['role' => UserRole::ADMIN]);
            $resource = Resource::factory()->create([
                'doi' => '10.5880/test.2026.001',
            ]);

            expect($resource->publicStatus())->toBe('draft');
            expect($this->policy->delete($user, $resource))->toBeTrue();
        });

        it('allows admin to delete a draft resource with a landing page', function () {
            $user = User::factory()->create(['role' => UserRole::ADMIN]);
            LandingPage::factory()->withoutDoi()->draft()->create([
                'resource_id' => $this->resource->id,
            ]);

            $this->resource->refresh();

            expect($this->resource->publicStatus())->toBe('draft');
            expect($this->policy->delete($user, $this->resource))->toBeTrue();
        });

        it('allows group leader to delete a curation resource', function () {
            $user = User::factory()->create(['role' => UserRole::GROUP_LEADER]);
            $resource = createNonDraftResourceForPolicy();

            expect($resource->publicStatus())->toBe('curation');
            expect($this->policy->delete($user, $resource))->toBeTrue();
        });

        it('allows curator to delete a review resource', function () {
            $user = User::factory()->create(['role' => UserRole::CURATOR]);
            $resource = createReviewResourceForPolicy();

            expect($resource->publicStatus())->toBe('review');
            expect($this->policy->delete($user, $resource))->toBeTrue();
        });

        it('allows admin to delete a published resource', function () {
            $user = User::factory()->create(['role' => UserRole::ADMIN]);
            $resource = createPublishedResourceForPolicy();

            expect($resource->publicStatus())->toBe('published');
            expect($this->policy->delete($user, $resource))->toBeTrue();
        });

        it('allows group leader to delete a published resource', function () {
            $user = User::factory()->create(['role' => UserRole::GROUP_LEADER]);
            $resource = createPublishedResourceForPolicy();

            expect($this->policy->delete($user, $resource))->toBeTrue();
        });

        it('denies curator from deleting a published resource', function () {
            $user = User::factory()->create(['role' => UserRole::CURATOR]);
            $resource = createPublishedResourceForPolicy();

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

    describe('DOI editing', function () {
        it('exposes the DOI edit capability by role before publication', function (UserRole $role, bool $expected) {
            $user = User::factory()->create(['role' => $role]);
            $this->resource->setRelation('landingPage', null);

            expect($this->policy->editDoi($user, $this->resource))->toBe($expected);
        })->with([
            'admin' => [UserRole::ADMIN, true],
            'group leader' => [UserRole::GROUP_LEADER, true],
            'curator' => [UserRole::CURATOR, true],
            'beginner' => [UserRole::BEGINNER, false],
        ]);

        it('exposes the DOI edit capability by role after publication', function (UserRole $role, bool $expected) {
            $user = User::factory()->create(['role' => $role]);
            LandingPage::factory()->published()->withDoi('10.5880/old.001')->create([
                'resource_id' => $this->resource->id,
            ]);
            $this->resource->refresh();

            expect($this->policy->editDoi($user, $this->resource))->toBe($expected);
        })->with([
            'admin' => [UserRole::ADMIN, true],
            'group leader' => [UserRole::GROUP_LEADER, false],
            'curator' => [UserRole::CURATOR, false],
            'beginner' => [UserRole::BEGINNER, false],
        ]);

        it('allows every role to submit an unchanged DOI', function (UserRole $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->resource->update(['doi' => '10.5880/test.001']);
            LandingPage::factory()->published()->withDoi('10.5880/test.001')->create([
                'resource_id' => $this->resource->id,
            ]);
            $this->resource->refresh();

            expect($this->policy->changeDoi($user, $this->resource, '10.5880/test.001'))->toBeTrue();
        })->with(UserRole::cases());

        it('allows authorized roles to replace or remove a DOI before publication', function (UserRole $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->resource->doi = '10.5880/old.001';
            $this->resource->setRelation('landingPage', null);

            expect($this->policy->changeDoi($user, $this->resource, '10.5880/new.001'))->toBeTrue()
                ->and($this->policy->changeDoi($user, $this->resource, null))->toBeTrue();
        })->with([
            UserRole::ADMIN,
            UserRole::GROUP_LEADER,
            UserRole::CURATOR,
        ]);

        it('denies a beginner from replacing or removing a DOI before publication', function () {
            $user = User::factory()->create(['role' => UserRole::BEGINNER]);
            $this->resource->doi = '10.5880/old.001';
            $this->resource->setRelation('landingPage', null);

            expect($this->policy->changeDoi($user, $this->resource, '10.5880/new.001'))->toBeFalse()
                ->and($this->policy->changeDoi($user, $this->resource, null))->toBeFalse();
        });

        it('only allows an admin to replace or remove a published DOI', function (UserRole $role, bool $expected) {
            $user = User::factory()->create(['role' => $role]);
            $this->resource->update(['doi' => '10.5880/old.001']);
            LandingPage::factory()->published()->withDoi('10.5880/old.001')->create([
                'resource_id' => $this->resource->id,
            ]);
            $this->resource->refresh();

            expect($this->policy->changeDoi($user, $this->resource, '10.5880/new.001'))->toBe($expected)
                ->and($this->policy->changeDoi($user, $this->resource, null))->toBe($expected);
        })->with([
            'admin' => [UserRole::ADMIN, true],
            'group leader' => [UserRole::GROUP_LEADER, false],
            'curator' => [UserRole::CURATOR, false],
            'beginner' => [UserRole::BEGINNER, false],
        ]);
    });
});
