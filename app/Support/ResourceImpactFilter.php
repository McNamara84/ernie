<?php

declare(strict_types=1);

namespace App\Support;

final readonly class ResourceImpactFilter
{
    public function __construct(
        public ?string $doi = null,
        public ?int $datacenterId = null,
    ) {}

    public function isActive(): bool
    {
        return $this->doi !== null || $this->datacenterId !== null;
    }

    /**
     * @return array{doi: string|null, datacenter_id: int|null}
     */
    public function toArray(): array
    {
        return [
            'doi' => $this->doi,
            'datacenter_id' => $this->datacenterId,
        ];
    }
}
