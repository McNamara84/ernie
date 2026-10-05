<?php

declare(strict_types=1);

namespace App\Services\RelationTypeCorrection;

final readonly class RelationEvidence
{
    public function __construct(
        public string $provider,
        public string $originKey,
        public string $claimant,
        public string $subject,
        public string $object,
        public string $originalRelation,
        public string $relation,
        public string $sourceUrl,
        public string $sourcePointer,
        public string $fetchedAt,
        public bool $primary = true,
    ) {}

    /** @return array<string, mixed> */
    public function material(): array
    {
        return ['origin_key' => $this->originKey, 'claimant' => $this->claimant, 'subject' => $this->subject,
            'object' => $this->object, 'original_relation' => $this->originalRelation, 'relation' => $this->relation, 'primary' => $this->primary];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [...$this->material(), 'provider' => $this->provider, 'source_url' => $this->sourceUrl,
            'source_pointer' => $this->sourcePointer, 'fetched_at' => $this->fetchedAt,
            'mapping_version' => RelationTypeRules::VERSION, 'assertion_hash' => RelationTypeRules::fingerprint($this->material())];
    }

    public function fromPerspective(string $doi): ?string
    {
        return $this->subject === $doi ? $this->relation : ($this->object === $doi ? RelationTypeRules::inverse($this->relation) : null);
    }
}
