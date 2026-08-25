<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Enums\ContributorCategory;
use App\Enums\UserRole;
use App\Http\Requests\Resource\SendResourceReviewLinksRequest;
use App\Mail\ResourceReviewLink;
use App\Mail\ResourceReviewLinkMigration;
use App\Models\ContributorType;
use App\Models\Description;
use App\Models\DescriptionType;
use App\Models\LandingPage;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Models\Right;
use App\Models\TitleType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Mail::fake();
    $this->withoutMiddleware(ThrottleRequests::class);
    config(['mail.landing_page_contact_cc' => 'datapub@example.test']);

    $this->contactPersonType = ContributorType::create([
        'name' => 'Contact Person',
        'slug' => 'ContactPerson',
        'category' => ContributorCategory::PERSON,
        'is_active' => true,
        'is_elmo_active' => true,
    ]);
    $this->otherType = ContributorType::create([
        'name' => 'Researcher',
        'slug' => 'Researcher',
        'category' => ContributorCategory::PERSON,
        'is_active' => true,
        'is_elmo_active' => true,
    ]);
    $this->mainTitleType = TitleType::factory()->create([
        'name' => 'Main Title',
        'slug' => 'MainTitle',
    ]);
});

function createReviewMailResource(string $title = 'Review Resource', array $resourceOverrides = []): Resource
{
    $resource = Resource::factory()->create([
        'doi' => '10.5880/'.fake()->unique()->bothify('review.####'),
        'force_review_status' => true,
        ...$resourceOverrides,
    ]);

    $resource->titles()->create([
        'value' => $title,
        'title_type_id' => test()->mainTitleType->id,
    ]);

    LandingPage::factory()->draft()->create([
        'resource_id' => $resource->id,
        'doi_prefix' => $resource->doi,
        'slug' => 'review-resource-'.$resource->id,
        'preview_token' => 'token-'.$resource->id,
    ]);

    return $resource;
}

function addReviewMailContributor(
    Resource $resource,
    ?string $email,
    ?ContributorType $type = null,
    ?Person $person = null,
): ResourceContributor {
    $person ??= Person::factory()->create([
        'given_name' => 'Ada',
        'family_name' => 'Reviewer',
    ]);

    $contributor = ResourceContributor::factory()->create([
        'resource_id' => $resource->id,
        'contributorable_type' => Person::class,
        'contributorable_id' => $person->id,
        'email' => $email,
    ]);
    $contributor->contributorTypes()->sync([($type ?? test()->contactPersonType)->id]);

    return $contributor;
}

function completeReviewMailResource(Resource $resource): void
{
    $resource->update(['access_level' => AccessLevel::OPEN]);
    $resource->titles()->create([
        'value' => 'Complete Curation Resource',
        'title_type_id' => test()->mainTitleType->id,
    ]);

    ResourceCreator::factory()->create(['resource_id' => $resource->id]);

    $right = Right::firstOrCreate(
        ['identifier' => 'cc-by-4.0'],
        ['name' => 'Creative Commons Attribution 4.0'],
    );
    $resource->rights()->attach($right->id);

    $descriptionType = DescriptionType::firstOrCreate(
        ['slug' => 'Abstract'],
        ['name' => 'Abstract'],
    );
    Description::create([
        'resource_id' => $resource->id,
        'description_type_id' => $descriptionType->id,
        'value' => 'A complete abstract for curation status.',
    ]);
}

function postReviewMailRequest(User $user, array $ids): TestResponse
{
    return test()->actingAs($user)->postJson(route('resources.send-review-links'), [
        'ids' => $ids,
    ]);
}

function postReviewMailMigrationRequest(User $user, array $ids): TestResponse
{
    return test()->actingAs($user)->postJson(route('resources.send-review-link-migrations'), [
        'ids' => $ids,
    ]);
}

describe('authorization and request validation', function (): void {
    it('allows Admin, Group Leader and Curator roles', function (UserRole $role): void {
        $resource = createReviewMailResource();
        addReviewMailContributor($resource, 'reviewer@example.test');
        $user = User::factory()->create(['role' => $role]);

        postReviewMailRequest($user, [$resource->id])
            ->assertOk()
            ->assertJsonPath('queued_messages', 1);

        Mail::assertQueued(ResourceReviewLink::class, 1);
    })->with([
        'Admin' => UserRole::ADMIN,
        'Group Leader' => UserRole::GROUP_LEADER,
        'Curator' => UserRole::CURATOR,
    ]);

    it('rejects Beginner users and guests', function (): void {
        $resource = createReviewMailResource();
        addReviewMailContributor($resource, 'reviewer@example.test');

        postReviewMailRequest(User::factory()->beginner()->create(), [$resource->id])
            ->assertForbidden();

        $this->postJson(route('resources.send-review-links'), ['ids' => [$resource->id]])
            ->assertForbidden();

        Mail::assertNothingQueued();
    });

    it('authorizes the explicit migration endpoint independently of the invitation endpoint', function (): void {
        $resource = createReviewMailResource();
        addReviewMailContributor($resource, 'reviewer@example.test');

        postReviewMailMigrationRequest(User::factory()->curator()->create(), [$resource->id])
            ->assertOk()
            ->assertJsonPath('queued_messages', 1);

        Mail::assertQueued(ResourceReviewLinkMigration::class, 1);
        Mail::assertNotQueued(ResourceReviewLink::class);
    });

    it('rejects Beginner users and guests at the migration endpoint', function (): void {
        $resource = createReviewMailResource();
        addReviewMailContributor($resource, 'reviewer@example.test');

        postReviewMailMigrationRequest(User::factory()->beginner()->create(), [$resource->id])
            ->assertForbidden();

        $this->postJson(route('resources.send-review-link-migrations'), ['ids' => [$resource->id]])
            ->assertForbidden();

        Mail::assertNothingQueued();
    });

    it('validates missing, duplicate and unknown resource IDs', function (array $payload, string $errorKey): void {
        $user = User::factory()->curator()->create();

        $this->actingAs($user)
            ->postJson(route('resources.send-review-links'), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($errorKey);

        Mail::assertNothingQueued();
    })->with([
        'missing IDs' => [[], 'ids'],
        'duplicate IDs' => [['ids' => [1, 1]], 'ids.1'],
        'unknown ID' => [['ids' => [999999]], 'ids.0'],
    ]);

    it('rejects batches larger than the visible resources page limit', function (): void {
        $ids = range(1, SendResourceReviewLinksRequest::MAX_BATCH_SIZE + 1);

        postReviewMailRequest(User::factory()->curator()->create(), $ids)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ids')
            ->assertJsonPath('errors.ids.0', fn (string $message): bool => str_contains(
                $message,
                'must not have more than '.SendResourceReviewLinksRequest::MAX_BATCH_SIZE.' items',
            ));

        Mail::assertNothingQueued();
    });
});

describe('request rate limiting', function (): void {
    it('limits duplicate invitation batches per user without affecting another curator', function (): void {
        $this->withMiddleware(ThrottleRequests::class);
        $resource = createReviewMailResource();
        addReviewMailContributor($resource, 'reviewer@example.test');
        $firstUser = User::factory()->curator()->create();
        $secondUser = User::factory()->curator()->create();
        $clearRateLimit = static function (User $user): void {
            RateLimiter::clear('resource-review-links:'.$user->getAuthIdentifier());
        };
        ThrottleRequests::shouldHashKeys(false);
        $clearRateLimit($firstUser);
        $clearRateLimit($secondUser);

        try {
            for ($attempt = 1; $attempt <= 10; $attempt++) {
                postReviewMailRequest($firstUser, [$resource->id])
                    ->assertOk()
                    ->assertHeader('X-RateLimit-Limit', '10');
            }

            postReviewMailRequest($firstUser, [$resource->id])
                ->assertTooManyRequests();

            postReviewMailRequest($secondUser, [$resource->id])
                ->assertOk();

            Mail::assertQueued(ResourceReviewLink::class, 11);
        } finally {
            $clearRateLimit($firstUser);
            $clearRateLimit($secondUser);
            ThrottleRequests::shouldHashKeys();
        }
    });
});

describe('atomic review selection preflight', function (): void {
    it('rejects a mixed selection without queueing any email', function (): void {
        $reviewResource = createReviewMailResource();
        addReviewMailContributor($reviewResource, 'reviewer@example.test');

        $publishedResource = createReviewMailResource('Published Resource');
        $publishedResource->landingPage()->update([
            'is_published' => true,
            'published_at' => now(),
        ]);

        postReviewMailRequest(User::factory()->curator()->create(), [$reviewResource->id, $publishedResource->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ids')
            ->assertJsonPath('errors.ids.0', fn (string $message): bool => str_contains($message, "#{$publishedResource->id} is not in review"));

        Mail::assertNothingQueued();
    });

    it('rejects each non-review status', function (string $status): void {
        $resource = match ($status) {
            'published' => tap(createReviewMailResource(), function (Resource $resource): void {
                $resource->landingPage()->update(['is_published' => true, 'published_at' => now()]);
            }),
            'draft' => Resource::factory()->create(['force_review_status' => false]),
            'curation' => tap(Resource::factory()->create(['force_review_status' => false, 'doi' => null]), completeReviewMailResource(...)),
        };

        postReviewMailRequest(User::factory()->curator()->create(), [$resource->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ids');

        Mail::assertNothingQueued();
    })->with(['published', 'draft', 'curation']);

    it('rejects a forced-review resource without a usable preview link', function (): void {
        $resource = createReviewMailResource();
        $resource->landingPage()->update(['preview_token' => null]);

        postReviewMailRequest(User::factory()->curator()->create(), [$resource->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ids')
            ->assertJsonPath('errors.ids.0', fn (string $message): bool => str_contains($message, "#{$resource->id} has no review link"));

        Mail::assertNothingQueued();
    });

    it('fails before queueing when the configured contact address is invalid', function (string $address): void {
        config(['mail.landing_page_contact_cc' => $address]);
        $resource = createReviewMailResource();
        addReviewMailContributor($resource, 'reviewer@example.test');

        postReviewMailRequest(User::factory()->curator()->create(), [$resource->id])
            ->assertServiceUnavailable()
            ->assertJsonPath('message', 'Review email delivery is not configured. Please contact an administrator.');

        Mail::assertNothingQueued();
    })->with(['empty address' => '', 'invalid address' => 'not-an-email']);
});

describe('recipient resolution and batch results', function (): void {
    it('keeps the existing endpoint on the personalized invitation workflow', function (): void {
        $resource = createReviewMailResource('Seismic Dataset');
        addReviewMailContributor($resource, 'ada@example.test');
        $curator = User::factory()->curator()->create();

        postReviewMailRequest($curator, [$resource->id])
            ->assertOk()
            ->assertJson([
                'queued_messages' => 1,
                'successful_resources' => [[
                    'id' => $resource->id,
                    'queued_recipients' => 1,
                ]],
                'failed_resources' => [],
                'skipped_recipients_count' => 0,
            ]);

        Mail::assertQueued(ResourceReviewLink::class, function (ResourceReviewLink $mail) use ($resource, $curator): bool {
            return $mail->hasTo('ada@example.test')
                && $mail->hasReplyTo('datapub@example.test')
                && $mail->hasCc('datapub@example.test')
                && $mail->resourceId === $resource->id
                && $mail->resourceTitle === 'Seismic Dataset'
                && $mail->resourceDoi === $resource->doi
                && $mail->recipientName === 'Ada Reviewer'
                && $mail->reviewUrl === $resource->landingPage?->preview_url
                && $mail->initiatorName === $curator->name
                && $mail->initiatorEmail === $curator->email
                && $mail->contactAddress === 'datapub@example.test';
        });

        Mail::assertNotQueued(ResourceReviewLinkMigration::class);
    });

    it('queues the migration notice only through the explicit migration endpoint', function (): void {
        $resource = createReviewMailResource('Migrated Seismic Dataset');
        addReviewMailContributor($resource, 'ada@example.test');

        postReviewMailMigrationRequest(User::factory()->curator()->create(), [$resource->id])
            ->assertOk()
            ->assertJsonPath('message', 'Review-link migration emails queued for delivery.')
            ->assertJsonPath('queued_messages', 1);

        Mail::assertQueued(ResourceReviewLinkMigration::class, function (ResourceReviewLinkMigration $mail) use ($resource): bool {
            return $mail->hasTo('ada@example.test')
                && $mail->hasReplyTo('datapub@example.test')
                && $mail->hasCc('datapub@example.test')
                && $mail->resourceId === $resource->id
                && $mail->resourceTitle === 'Migrated Seismic Dataset'
                && $mail->resourceDoi === $resource->doi
                && $mail->recipientName === 'Ada Reviewer'
                && $mail->reviewUrl === $resource->landingPage?->preview_url
                && $mail->contactAddress === 'datapub@example.test';
        });

        Mail::assertNotQueued(ResourceReviewLink::class);
    });

    it('keeps a migrated review resource without a DOI eligible for delivery', function (): void {
        $resource = createReviewMailResource('Migrated Dataset Awaiting DOI', ['doi' => null]);
        addReviewMailContributor($resource, 'ada@example.test');

        postReviewMailMigrationRequest(User::factory()->curator()->create(), [$resource->id])
            ->assertOk()
            ->assertJsonPath('queued_messages', 1);

        Mail::assertQueued(ResourceReviewLinkMigration::class, fn (ResourceReviewLinkMigration $mail): bool => $mail->resourceId === $resource->id
            && $mail->resourceDoi === null
            && $mail->resourceTitle === 'Migrated Dataset Awaiting DOI'
            && $mail->reviewUrl === $resource->landingPage?->preview_url);
    });

    it('keeps a review resource without a DOI eligible for delivery', function (): void {
        $resource = createReviewMailResource('Dataset Awaiting DOI', ['doi' => null]);
        addReviewMailContributor($resource, 'ada@example.test');

        postReviewMailRequest(User::factory()->curator()->create(), [$resource->id])
            ->assertOk()
            ->assertJsonPath('queued_messages', 1);

        Mail::assertQueued(ResourceReviewLink::class, fn (ResourceReviewLink $mail): bool => $mail->resourceId === $resource->id
            && $mail->resourceDoi === null
            && $mail->resourceTitle === 'Dataset Awaiting DOI'
            && $mail->reviewUrl === $resource->landingPage?->preview_url);
    });

    it('skips missing addresses and ignores non-ContactPerson contributors and contact creators', function (): void {
        $resource = createReviewMailResource();
        $validContributor = addReviewMailContributor($resource, 'valid@example.test');
        $validContributor->contributorTypes()->attach($this->otherType->id);
        addReviewMailContributor($resource, null);
        addReviewMailContributor($resource, 'researcher@example.test', $this->otherType);

        ResourceCreator::factory()->create([
            'resource_id' => $resource->id,
            'is_contact' => true,
            'email' => 'creator@example.test',
        ]);

        postReviewMailRequest(User::factory()->curator()->create(), [$resource->id])
            ->assertStatus(207)
            ->assertJsonPath('queued_messages', 1)
            ->assertJsonPath('skipped_recipients_count', 1);

        Mail::assertQueued(ResourceReviewLink::class, 1);
        Mail::assertQueued(ResourceReviewLink::class, fn (ResourceReviewLink $mail): bool => $mail->hasTo('valid@example.test'));
        Mail::assertNotQueued(ResourceReviewLink::class, fn (ResourceReviewLink $mail): bool => $mail->hasTo('researcher@example.test'));
        Mail::assertNotQueued(ResourceReviewLink::class, fn (ResourceReviewLink $mail): bool => $mail->hasTo('creator@example.test'));
    });

    it('deduplicates an address within one resource but sends once per resource', function (): void {
        $first = createReviewMailResource('First Resource');
        $second = createReviewMailResource('Second Resource');
        addReviewMailContributor($first, 'shared@example.test');
        addReviewMailContributor($first, 'SHARED@example.test');
        addReviewMailContributor($second, 'shared@example.test');

        postReviewMailRequest(User::factory()->curator()->create(), [$first->id, $second->id])
            ->assertOk()
            ->assertJsonPath('queued_messages', 2);

        Mail::assertQueued(ResourceReviewLink::class, 2);
        Mail::assertQueued(ResourceReviewLink::class, fn (ResourceReviewLink $mail): bool => $mail->resourceId === $first->id
            && $mail->resourceTitle === 'First Resource');
        Mail::assertQueued(ResourceReviewLink::class, fn (ResourceReviewLink $mail): bool => $mail->resourceId === $second->id
            && $mail->resourceTitle === 'Second Resource');
    });

    it('reports a resource without valid recipients while processing other resources', function (): void {
        $successful = createReviewMailResource('Successful Resource');
        addReviewMailContributor($successful, 'valid@example.test');
        $failed = createReviewMailResource('No Recipients');
        addReviewMailContributor($failed, 'invalid-address');

        postReviewMailRequest(User::factory()->curator()->create(), [$successful->id, $failed->id])
            ->assertStatus(207)
            ->assertJsonPath('queued_messages', 1)
            ->assertJsonPath('successful_resources.0.id', $successful->id)
            ->assertJsonPath('failed_resources.0.id', $failed->id)
            ->assertJsonPath('skipped_recipients_count', 1);

        Mail::assertQueued(ResourceReviewLink::class, 1);
    });

    it('isolates a queue failure and returns a partial result without exposing the review link', function (): void {
        $resource = createReviewMailResource();
        $contributor = addReviewMailContributor($resource, 'valid@example.test');

        Mail::shouldReceive('to')
            ->once()
            ->with('valid@example.test')
            ->andThrow(new RuntimeException('Queue unavailable'));

        postReviewMailRequest(User::factory()->curator()->create(), [$resource->id])
            ->assertStatus(207)
            ->assertJsonPath('queued_messages', 0)
            ->assertJsonPath('successful_resources', [])
            ->assertJsonPath('failed_resources.0.id', $resource->id)
            ->assertJsonPath('skipped_recipients_count', 1)
            ->assertJsonMissing(['review_url' => $resource->landingPage?->preview_url])
            ->assertJsonMissing(['preview_token' => 'token-'.$resource->id]);

        expect($contributor->id)->toBeGreaterThan(0);
    });
});
