<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\IgsnMetadata;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\ResourceType;
use App\Models\Title;
use App\Models\TitleType;
use App\Services\Resources\ResourceListingProjectionRefreshService;
use Illuminate\Database\Seeder;

final class ContactPersonsPlaywrightSeeder extends Seeder
{
    public function run(): void
    {
        $person = Person::query()->firstOrCreate(['given_name' => 'Ada', 'family_name' => 'PlaywrightContact1352', 'name_identifier' => null]);
        $titleType = TitleType::query()->where('slug', 'MainTitle')->firstOrFail();
        foreach (['dataset', 'physical-object'] as $slug) {
            $type = ResourceType::query()->where('slug', $slug)->firstOrFail();
            $resource = Resource::query()->firstOrCreate(
                ['doi' => '10.1234/playwright-contact-1352-'.$slug],
                ['resource_type_id' => $type->id, 'publication_year' => 2026],
            );
            Title::query()->updateOrCreate(['resource_id' => $resource->id, 'title_type_id' => $titleType->id], ['value' => 'Playwright: Contact 1352 '.$slug]);
            ResourceCreator::query()->updateOrCreate([
                'resource_id' => $resource->id, 'creatorable_type' => Person::class, 'creatorable_id' => $person->id, 'position' => 0,
            ], ['is_contact' => true, 'email' => 'contact1352@example.test', 'name_snapshot' => 'PlaywrightContact1352, Ada']);
            if ($slug === 'physical-object') {
                IgsnMetadata::query()->firstOrCreate(['resource_id' => $resource->id], ['upload_status' => 'pending']);
            }
        }
        app(ResourceListingProjectionRefreshService::class)->flushPending();
    }
}
