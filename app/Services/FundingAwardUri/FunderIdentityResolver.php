<?php

declare(strict_types=1);

namespace App\Services\FundingAwardUri;

use App\Models\FundingReference;
use App\Services\CrossrefFunderRor\CrossrefFunderRorIdentifierNormalizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class FunderIdentityResolver
{
    /** @var list<array<string, mixed>>|null */
    private ?array $entries = null;

    public function __construct(private readonly CrossrefFunderRorIdentifierNormalizer $normalizer) {}

    public function reset(): void
    {
        $this->entries = null;
    }

    public function isUnique(FundingReference $funding, FunderIdentity $funder): bool
    {
        $identity = AwardMetadata::identity($funding, $funder);
        foreach (FundingReference::with('funderIdentifierType')->where('resource_id', $funding->resource_id)
            ->where('id', '!=', $funding->id)->get() as $other) {
            $otherFunder = $this->resolve($other);
            if ($otherFunder !== null && AwardMetadata::identity($other, $otherFunder) === $identity) {
                return false;
            }
        }

        return true;
    }

    public function resolve(FundingReference $funding): ?FunderIdentity
    {
        $type = $funding->funderIdentifierType?->name;
        if ($type === 'Crossref Funder ID') {
            $normalized = $this->normalizer->normalizeCrossrefFunderId($funding->funder_identifier, true);

            return $normalized === null ? null : new FunderIdentity('10.13039/'.$normalized['normalized'], ['10.13039/'.$normalized['normalized']]);
        }
        if ($type !== 'ROR' || ($ror = $this->normalizer->canonicalRorIdentifier($funding->funder_identifier)) === null) {
            return null;
        }
        $matches = array_values(array_filter($this->entries(), static fn (array $entry): bool => ($entry['ror_id'] ?? null) === $ror));
        if (count($matches) !== 1) {
            return null;
        }
        $entry = $matches[0];
        $ids = $entry['external_ids']['fundref']['all'] ?? [];
        $source = $entry['source'] ?? [];
        if (($entry['ror_status'] ?? null) !== 'active' || ! is_array($entry['ror_types'] ?? null) || ! in_array('funder', $entry['ror_types'], true)
            || ! is_array($ids) || ! array_is_list($ids) || count($ids) !== 1 || ! is_array($source)) {
            return null;
        }
        try {
            $retrieved = Carbon::parse($source['source_retrieved_at'] ?? 'invalid');
            if ($retrieved->isFuture() || $retrieved->lt(now()->subDays((int) config('funding_award_uri.crosswalk_days', 180)))) {
                return null;
            }
        } catch (Throwable) {
            return null;
        }
        $id = $this->normalizer->normalizeCrossrefFunderId(is_string($ids[0]) ? $ids[0] : null, true);
        if ($id === null) {
            return null;
        }
        $crossref = '10.13039/'.$id['normalized'];
        // An external ID assigned to multiple active ROR records is not a unique crosswalk.
        foreach ($this->entries() as $other) {
            if (($other['ror_id'] ?? null) === $ror || ($other['ror_status'] ?? null) !== 'active') {
                continue;
            }
            $otherIds = $other['external_ids']['fundref']['all'] ?? [];
            // Malformed sibling data cannot establish that this mapping is unique.
            if (! is_array($otherIds) || ! array_is_list($otherIds)) {
                return null;
            }
            foreach ($otherIds as $otherId) {
                $normalized = $this->normalizer->normalizeCrossrefFunderId(is_string($otherId) ? $otherId : null, true);
                if ($normalized === null || $normalized['normalized'] === $id['normalized']) {
                    return null;
                }
            }
        }

        return new FunderIdentity($crossref, [$crossref, $ror], [
            'source' => 'ror_fundref_index', 'fetched_at' => $retrieved->toIso8601String(),
            'ror_id' => $ror, 'crossref_id' => $crossref,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function entries(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }
        $this->entries = [];
        try {
            $raw = Storage::disk('local')->get('ror/ror-fundref-index.json');
            $data = is_string($raw) ? json_decode($raw, true, flags: JSON_THROW_ON_ERROR) : null;
            if (is_array($data) && is_array($data['data'] ?? null)) {
                foreach ($data['data'] as $entry) {
                    if (is_array($entry)) {
                        $entry['source'] ??= $data['source'] ?? [];
                        $this->entries[] = $entry;
                    }
                }
            }
        } catch (Throwable) {
            // Missing or malformed crosswalks cannot establish a funder identity.
        }

        return $this->entries;
    }
}
