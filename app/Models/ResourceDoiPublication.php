<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\DataCiteVerificationException;
use App\Services\DataCiteMemberApiClient;
use App\Services\ResourceCacheService;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $uuid
 * @property int|null $active_resource_id
 * @property int $resource_id
 * @property int|null $user_id
 * @property bool $test_mode
 * @property string $endpoint
 * @property string $repository
 * @property string $prefix
 * @property string|null $doi
 * @property string $target_url
 * @property string $metadata_hash
 * @property array<string, mixed> $payload
 * @property string|null $previous_state
 * @property string $status
 * @property int $attempts
 * @property string|null $last_error
 */
class ResourceDoiPublication extends Model
{
    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['test_mode' => 'boolean', 'payload' => 'array', 'attempts' => 'integer', 'completed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $publication): void {
            $publication->active_resource_id = in_array($publication->status, ['succeeded', 'failed', 'superseded'], true) ? null : $publication->resource_id;
        });
        static::saved(function (self $publication): void {
            app(ResourceCacheService::class)->invalidateResourceCache($publication->resource_id);
        });
    }

    public function assertContext(DataCiteMemberApiClient $client, ?string $doi = null): void
    {
        if ($this->test_mode !== $client->isTestMode() || $this->endpoint !== $client->endpoint()
            || $this->repository !== $client->repositoryClientId($this->doi ?? $this->prefix)
            || ($doi !== null && strcasecmp((string) $this->doi, $doi) !== 0)) {
            throw new DataCiteVerificationException('doi_publication_context_changed', 'This publication belongs to a different DOI, DataCite repository or environment. Reconcile it in its original context.');
        }
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return $this->only(['uuid', 'doi', 'test_mode', 'status', 'last_error', 'last_error_code', 'completed_at']);
    }
}
