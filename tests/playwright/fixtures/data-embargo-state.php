<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Models\DateType;
use App\Models\Description;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\Right;
use App\Models\Title;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (PHP_SAPI !== 'cli' || ! in_array(config('app.env'), ['local', 'testing'], true) || ! config('datacite.test_mode')) {
    throw new RuntimeException('Embargo browser fixtures require a local/testing DataCite test environment.');
}

if (($argv[1] ?? '') === 'cleanup') {
    $snapshot = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    Resource::query()->whereKey((int) ($snapshot['id'] ?? 0))
        ->whereHas('titles', fn ($query) => $query->where('value', 'like', 'PLAYWRIGHT-DATA-EMBARGO-%'))->delete();
    exit(0);
}
if (($argv[1] ?? '') !== 'create') {
    throw new RuntimeException('Invalid embargo fixture action.');
}

$resource = Resource::factory()->create(['doi' => '10.83279/browser-embargo-'.Str::uuid(), 'access_level' => AccessLevel::EMBARGOED]);
$title = 'PLAYWRIGHT-DATA-EMBARGO-'.Str::uuid();
Title::factory()->create(['resource_id' => $resource->id, 'value' => $title]);
ResourceCreator::factory()->create(['resource_id' => $resource->id]);
Description::factory()->abstract()->create(['resource_id' => $resource->id, 'value' => 'Public embargo metadata for the browser regression test.']);
$resource->rights()->attach(Right::factory()->create());
$available = now(config('app.timezone'))->addDay()->toDateString();
$type = DateType::firstOrCreate(['slug' => 'Available'], ['name' => 'Available', 'is_active' => true]);
$resource->dates()->create(['date_type_id' => $type->id, 'date_value' => $available]);
// Avoid scheduling an external FAIR assessment for this short-lived test fixture.
$page = LandingPage::withoutEvents(fn () => LandingPage::factory()->published()->create([
    'resource_id' => $resource->id, 'template' => 'default_gfz', 'doi_prefix' => $resource->doi,
    'ftp_url' => 'https://example.org/embargo-browser-private.zip', 'downloads_unavailable' => false,
]));
echo json_encode([
    'id' => $resource->id, 'title' => $title, 'path' => $page->getPublicPath(),
    'available' => now(config('app.timezone'))->addDay()->format('d.m.Y'),
    'downloadPath' => '/landing-page-downloads/'.$page->id.'/primary',
], JSON_THROW_ON_ERROR);
