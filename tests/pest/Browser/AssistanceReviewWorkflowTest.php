<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\DismissedRelation;
use App\Models\IdentifierType;
use App\Models\RelationType;
use App\Models\Resource;
use App\Models\SuggestedRelation;
use App\Models\User;
use Database\Seeders\IdentifierTypeSeeder;
use Database\Seeders\RelationTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(RefreshDatabase::class);

/**
 * Browser regression coverage for the primary Assistance review flow.
 *
 * A reviewer must be able to inspect a relation suggestion and decline it.
 * Declining removes the item from the queue and records the dismissal, so the
 * discovery job cannot re-create the same review task later.
 */
describe('Assistance relation review workflow', function (): void {
    beforeEach(function (): void {
        test()->seed(IdentifierTypeSeeder::class);
        test()->seed(RelationTypeSeeder::class);
    });

    it('lets an administrator decline a relation suggestion from the review queue', function (): void {
        /** @var TestCase $this */
        $reviewer = User::factory()->create(['role' => UserRole::ADMIN]);
        $resource = Resource::factory()->create(['doi' => '10.5880/review.workflow.001']);

        $identifierTypeId = IdentifierType::query()->where('slug', 'DOI')->value('id');
        $relationTypeId = RelationType::query()->where('slug', 'IsCitedBy')->value('id');

        expect($identifierTypeId)->toBeInt()
            ->and($relationTypeId)->toBeInt();

        $suggestion = SuggestedRelation::query()->create([
            'resource_id' => $resource->id,
            'identifier' => '10.5880/review.workflow.related',
            'identifier_type_id' => $identifierTypeId,
            'relation_type_id' => $relationTypeId,
            'source' => 'scholexplorer',
            'source_title' => 'Related work awaiting review',
            'source_publisher' => 'GFZ Data Services',
            'discovered_at' => now(),
        ]);

        $this->actingAs($reviewer);

        visit('/assistance')
            ->assertNoSmoke()
            ->assertSee('Relation Suggestions')
            ->assertSee('10.5880/review.workflow.related')
            ->assertSee('Related work awaiting review')
            ->pressAndWaitFor('Decline')
            ->assertSee('Suggestion declined.')
            ->assertDontSee('10.5880/review.workflow.related');

        $this->assertDatabaseMissing('suggested_relations', ['id' => $suggestion->id]);
        $this->assertDatabaseHas('dismissed_relations', [
            'resource_id' => $resource->id,
            'identifier' => '10.5880/review.workflow.related',
            'relation_type_id' => $relationTypeId,
            'dismissed_by' => $reviewer->id,
        ]);

        expect(DismissedRelation::query()->count())->toBe(1);
    });
});
