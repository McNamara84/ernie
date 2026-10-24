<?php

declare(strict_types=1);

namespace App\Services\Curation;

use App\Models\Resource;
use App\Models\ResourceDoiRegistrationEvidence;
use App\Services\Resources\ResourceListingProjectionRefreshService;
use Illuminate\Support\Facades\DB;

final readonly class CurationRegistrationService
{
    public function __construct(private CurationService $curation) {}

    /** @param array<string, mixed> $record */
    public function record(Resource $resource, array $record, string $environment, string $source): void
    {
        if (! $this->curation->available() || ! $this->curation->eligible($resource)) {
            return;
        }
        $data = $record['data'] ?? $record;
        $doi = is_array($data) ? ($data['id'] ?? null) : null;
        $state = is_array($data) ? ($data['attributes']['state'] ?? null) : null;
        if (! is_string($doi) || ! in_array($state, ['registered', 'findable', 'draft'], true)) {
            return;
        }
        DB::transaction(function () use ($resource, $doi, $state, $environment, $source): void {
            $locked = Resource::query()->lockForUpdate()->findOrFail($resource->id);
            if (strtolower((string) $locked->doi) !== strtolower($doi)) {
                return;
            }
            ResourceDoiRegistrationEvidence::updateOrCreate(
                ['resource_id' => $locked->id, 'doi' => strtolower($doi), 'environment' => $environment],
                ['state' => $state, 'source' => $source, 'confirmed_at' => now()],
            );
            $this->curation->reconcile($locked->id);
            app(ResourceListingProjectionRefreshService::class)->schedule($locked->id);
        });
    }
}
