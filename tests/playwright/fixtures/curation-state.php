<?php

declare(strict_types=1);

use App\Models\Resource;
use App\Models\TitleType;
use App\Models\User;
use App\Services\Curation\CurationService;
use App\Services\Resources\ResourceListingProjectorService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! in_array(config('app.env'), ['local', 'testing'], true)) {
    throw new RuntimeException('Curation fixtures require a local or testing environment.');
}
Queue::fake();
if (($argv[1] ?? '') === 'clean') {
    $data = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($data) || ! Str::isUuid($data['tag'] ?? '')) {
        throw new RuntimeException('Invalid curation fixture identifier.');
    }
    DB::transaction(function () use ($data): void {
        Resource::where('created_by_user_id', $data['actor'])->whereHas('titles', fn ($q) => $q->where('value', 'like', 'Curation browser '.$data['tag'].'%'))->get()->each->delete();
        User::whereIn('id', [$data['actor'], $data['next'], $data['beginner']])->where('email', 'like', '%'.$data['tag'].'@example.test')->get()->each->delete();
    });
    exit(0);
}
if (($argv[1] ?? '') !== 'create') {
    throw new RuntimeException('Unknown curation fixture action.');
}
$data = DB::transaction(function () use ($argv): array {
    $tag = (string) Str::uuid();
    $password = Str::password(32);
    $actor = User::factory()->curator()->create(['name' => 'Browser Curator', 'email' => 'curator-'.$tag.'@example.test', 'password' => Hash::make($password)]);
    $next = User::factory()->curator()->create(['name' => 'Browser Handover', 'email' => 'next-'.$tag.'@example.test', 'password' => Hash::make($password)]);
    $beginner = User::factory()->beginner()->create(['name' => 'Browser Beginner', 'email' => 'beginner-'.$tag.'@example.test', 'password' => Hash::make($password)]);
    $resource = Resource::factory()->create(['doi' => null, 'created_by_user_id' => $actor->id]);
    $title = 'Curation browser '.$tag;
    $resource->titles()->create(['value' => $title, 'title_type_id' => TitleType::firstOrCreate(['slug' => 'MainTitle'], ['name' => 'Main Title'])->id]);
    app(CurationService::class)->start($resource, $actor);
    $ids = [$resource->id];
    if (($argv[2] ?? '') === '300') {
        $attributes = $resource->getAttributes();
        unset($attributes['id']);
        Resource::insert(array_map(fn (int $offset): array => [...$attributes, 'created_at' => now()->subMinutes($offset)], range(1, 299)));
        $extra = Resource::where('created_by_user_id', $actor->id)->where('id', '!=', $resource->id)->pluck('id')->all();
        $now = now();
        DB::table('titles')->insert(array_map(fn ($id): array => ['resource_id' => $id, 'title_type_id' => $resource->titles->first()->title_type_id, 'value' => $title.' '.$id, 'created_at' => $now, 'updated_at' => $now], $extra));
        DB::table('resource_curation_states')->insert(array_map(fn ($id): array => ['resource_id' => $id, 'start_source' => 'backfill', 'started_at' => $now, 'last_activity_at' => $now->copy()->subMinutes($id % 300), 'lead_curator_id' => $actor->id,
            'waiting_for' => $id % 3 === 0 ? 'submitters' : null, 'waiting_since' => $id % 3 === 0 ? $now->copy()->subDays(16) : null, 'needs_help_text' => $id % 5 === 0 ? 'Check coordinates' : null], $extra));
        DB::table('resource_curators')->insert(array_map(fn ($id): array => ['resource_id' => $id, 'user_id' => $actor->id, 'assigned_at' => $now], $extra));
        DB::table('resource_curators')->insert(array_values(array_map(fn ($id): array => ['resource_id' => $id, 'user_id' => $next->id, 'assigned_at' => $now], array_filter($extra, fn ($id): bool => $id % 4 === 0))));
        $ids = [...$ids, ...$extra];
    }
    app(ResourceListingProjectorService::class)->refreshMany($ids);

    return ['tag' => $tag, 'title' => $title, 'id' => $resource->id, 'actor' => $actor->id, 'next' => $next->id, 'beginner' => $beginner->id,
        'email' => $actor->email, 'beginnerEmail' => $beginner->email, 'password' => $password];
});
echo json_encode($data, JSON_THROW_ON_ERROR);
