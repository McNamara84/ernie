<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\TombstoneReason;
use App\Models\LandingPage;
use App\Models\LandingPageTemplate;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\ResourceTombstoneTransition;
use App\Models\Title;
use Illuminate\Database\Seeder;

/** Creates a local browser fixture without sending any request to DataCite. */
class TombstonePlaywrightSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Tombstone browser fixtures are restricted to local and testing environments.');
        }

        /** @var array<string, mixed> $attributes */
        $attributes = Resource::factory()->raw();
        unset($attributes['doi']);
        $resource = Resource::firstOrCreate(['doi' => '10.1234/playwright-tombstone'], $attributes);
        /** @var array<string, mixed> $titleAttributes */
        $titleAttributes = Title::factory()->raw(['resource_id' => $resource->id, 'value' => 'Playwright: Tombstone Resource']);
        Title::firstOrCreate(['resource_id' => $resource->id], $titleAttributes);
        if (! $resource->creators()->exists()) {
            ResourceCreator::factory()->create(['resource_id' => $resource->id, 'is_contact' => true, 'email' => 'tombstone-contact@example.org']);
        }
        $page = LandingPage::firstOrCreate(['resource_id' => $resource->id], [
            'doi_prefix' => $resource->doi, 'slug' => 'playwright-tombstone',
            'template' => LandingPageTemplate::DEFAULT_TEMPLATE_SLUG,
        ]);
        $page->forceFill([
            'is_tombstone' => true, 'is_published' => true, 'published_at' => now(),
            'tombstone_reason' => TombstoneReason::DATA_LOST,
            'tombstone_statement' => 'The original files were permanently lost. The DOI and metadata remain available.',
            'tombstoned_at' => now(), 'tombstone_revision' => 1,
            'landing_page_template_id' => LandingPageTemplate::defaultForType(LandingPageTemplate::TEMPLATE_TYPE_RESOURCE)->id,
        ])->save();
        ResourceTombstoneTransition::firstOrCreate(['resource_id' => $resource->id, 'revision' => 1], [
            'action' => 'activate', 'reason' => TombstoneReason::DATA_LOST->value, 'statement' => $page->tombstone_statement,
            'doi' => $resource->doi, 'test_mode' => true, 'previous_state' => 'findable', 'previous_url' => $page->public_url,
            'target_state' => 'registered', 'target_url' => $page->public_url, 'status' => 'succeeded', 'completed_at' => now(),
            'snapshot' => ['version' => 1, 'configuration' => ['template' => 'default_gfz', 'is_published' => true]],
        ]);
        $resource->touch();
    }
}
