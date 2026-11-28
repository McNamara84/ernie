<?php

declare(strict_types=1);

use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Services\ContactPersons\ResourceContactProjectorService;
use App\Services\Resources\ResourceListingProjectionRefreshService;
use Illuminate\Support\Facades\DB;

uses()->group('mysql-sensitive');

it('waits for a concurrent resource update and then projects its committed contact data', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requires the MySQL 9.7 test server.');
    }

    $resource = Resource::factory()->create();
    $person = Person::factory()->create();
    $creator = ResourceCreator::factory()->forPerson($person)->create([
        'resource_id' => $resource->id, 'is_contact' => true, 'email' => 'old@example.test',
    ]);
    app(ResourceListingProjectionRefreshService::class)->flushPending();
    DB::commit();
    DB::disconnect();
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    if ($pair === false || ($pid = pcntl_fork()) === -1) {
        throw new RuntimeException('Could not start concurrent contact projector.');
    }
    if ($pid === 0) {
        fclose($pair[0]);
        DB::purge();
        stream_set_timeout($pair[1], 20);
        fgets($pair[1]);
        try {
            DB::connection()->beforeExecuting(function (string $query) use ($pair): void {
                if (str_contains($query, 'for update')) {
                    fwrite($pair[1], "locking\n");
                }
            });
            app(ResourceContactProjectorService::class)->refreshMany([$resource->id]);
            fwrite($pair[1], "done\n");
            exit(0);
        } catch (Throwable $exception) {
            fwrite($pair[1], 'error: '.$exception->getMessage()."\n");
            exit(1);
        }
    }
    fclose($pair[1]);
    stream_set_timeout($pair[0], 20);
    try {
        DB::beginTransaction();
        Resource::query()->whereKey($resource->id)->lockForUpdate()->firstOrFail();
        DB::table('resource_creators')->where('id', $creator->id)->update(['email' => 'latest@example.test']);
        fwrite($pair[0], "go\n");
        expect(trim((string) fgets($pair[0])))->toBe('locking');
        $read = [$pair[0]];
        $write = $except = null;
        // The projector must not finish while the other connection owns the lock.
        expect(stream_select($read, $write, $except, 0, 200000))->toBe(0);
        DB::commit();
        expect(trim((string) fgets($pair[0])))->toBe('done');
        pcntl_waitpid($pid, $status);
        expect(pcntl_wexitstatus($status))->toBe(0);
        expect(DB::table('resource_contact_points')->where('resource_id', $resource->id)->pluck('email')->all())
            ->toBe(['latest@example.test']);
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        fclose($pair[0]);
        pcntl_waitpid($pid, $status);
        DB::purge();
        Resource::whereKey($resource->id)->delete();
        Person::whereKey($person->id)->delete();
        DB::beginTransaction();
    }
});
