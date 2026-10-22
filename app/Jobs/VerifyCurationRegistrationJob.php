<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Resource;
use App\Services\Curation\CurationRegistrationService;
use App\Services\DataCiteRegistrationFactoryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class VerifyCurationRegistrationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public readonly int $resourceId, public readonly string $doi, public readonly bool $testMode) {}

    public function handle(DataCiteRegistrationFactoryService $factory, CurationRegistrationService $evidence): void
    {
        $resource = Resource::find($this->resourceId);
        if ($resource === null || $resource->doi !== $this->doi) {
            return;
        }
        $response = $factory->clientForMode($this->testMode)->getDoi($this->doi);
        if ($response->status() === 404) {
            return;
        }
        $record = $response->throw()->json();
        if (is_array($record)) {
            $evidence->record($resource, $record, $this->testMode ? 'test' : 'production', 'verification');
        }
    }
}
