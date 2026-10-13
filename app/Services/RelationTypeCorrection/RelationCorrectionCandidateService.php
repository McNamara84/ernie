<?php

declare(strict_types=1);

namespace App\Services\RelationTypeCorrection;

use App\Models\RelatedIdentifier;
use App\Models\RelationType;

/**
 * @phpstan-type Correction array{contract_version: int, policy_version: string, current: array<string, mixed>, proposed: array{id: int, slug: string, name: string}, rule: array{id: string, conflict_kind: string, rationale: string}, confidence: array{level: string, score: float, basis: string}, evidence: list<array<string, mixed>>, sources: list<array<string, mixed>>, context_fingerprint: string, review_fingerprint: string}
 */
final class RelationCorrectionCandidateService
{
    public const CONTRACT = 1;

    /** @return array<string, mixed> */
    public function snapshot(RelatedIdentifier $target): array
    {
        return ['id' => $target->id, 'resource_id' => $target->resource_id, 'identifier' => $target->identifier,
            'identifier_type_id' => $target->identifier_type_id, 'identifier_type' => $target->identifierType->slug,
            'relation_type_id' => $target->relation_type_id, 'relation_type' => $target->relationType->slug,
            'relation_type_name' => $target->relationType->name, 'relation_type_information' => $target->relation_type_information,
            'related_metadata_scheme' => $target->related_metadata_scheme, 'scheme_uri' => $target->scheme_uri,
            'scheme_type' => $target->scheme_type, 'citation_label' => $target->citation_label, 'source' => $target->source,
            'resource_type_general' => $target->resource_type_general, 'position' => $target->position,
            'resource_context' => ['id' => $target->resource->id, 'doi' => $target->resource->doi,
                'version' => $target->resource->version, 'resource_type_id' => $target->resource->resource_type_id]];
    }

    /** @param list<RelationEvidence> $evidence
     * @param  list<array<string, mixed>>  $sources
     * @return Correction|null
     */
    public function build(RelatedIdentifier $target, array $evidence, array $sources = []): ?array
    {
        $own = RelationTypeRules::doi($target->resource->doi ?? '');
        $other = RelationTypeRules::doi($target->identifier);
        if ($own === null || $other === null || $own === $other || $target->identifierType->slug !== 'DOI') {
            return null;
        }
        $current = $target->relationType->slug;
        $ownAlternatives = array_any($evidence, static fn (RelationEvidence $claim): bool => $claim->primary && $claim->claimant === $own
            && $claim->fromPerspective($own) !== null && $claim->fromPerspective($own) !== $current
            && (($claim->subject === $own && $claim->object === $other) || ($claim->subject === $other && $claim->object === $own)));
        $types = [];
        $origins = [];
        $material = [];
        foreach ($evidence as $claim) {
            if (! (($claim->subject === $own && $claim->object === $other) || ($claim->subject === $other && $claim->object === $own))) {
                continue;
            }
            $type = $claim->fromPerspective($own);
            // The resource's own published current type is comparison context,
            // not an independent confirmation of its locally stored value.
            if ($claim->primary && $type !== null && ! ($claim->claimant === $own && $type === $current && ! $ownAlternatives)) {
                $types[$type] = true;
                $origins[$claim->claimant] = true;
            }
            $assertion = ['claimant' => $claim->claimant, 'subject' => $own, 'object' => $other,
                'relation' => $type, 'primary' => $claim->primary];
            if ($claim->primary) {
                $material[RelationTypeRules::fingerprint($assertion)] = $assertion;
            }
        }
        if (count($types) !== 1) {
            return null;
        }
        $proposed = array_key_first($types);
        if ($target->relationType->is_active !== true) {
            return null;
        }
        $rule = RelationTypeRules::conflict($current, $proposed, trim($target->relation_type_information ?? '') !== '');
        if ($rule === null) {
            return null;
        }
        if (! in_array($proposed, ['HasMetadata', 'IsMetadataFor'], true)
            && ($target->related_metadata_scheme !== null || $target->scheme_uri !== null || $target->scheme_type !== null)) {
            return null;
        }
        $type = RelationType::query()->where('slug', $proposed)->where('is_active', true)->first();
        if ($type === null) {
            return null;
        }
        $snapshot = $this->snapshot($target);
        $context = $snapshot;
        unset($context['citation_label'], $context['position'], $context['source'], $context['relation_type_name']);
        $context['identifier'] = $other;
        $context['resource_context']['doi'] = $own;
        $metadata = ['contract_version' => self::CONTRACT, 'policy_version' => RelationTypeRules::VERSION,
            'current' => $snapshot, 'proposed' => ['id' => $type->id, 'slug' => $type->slug, 'name' => $type->name],
            'rule' => $rule, 'confidence' => ['level' => 'high', 'score' => count($origins) > 1 ? 0.95 : 0.90,
                'basis' => count($origins) > 1 ? 'Independent primary assertions from both records' : 'Explicit primary assertion and a structural conflict rule'],
            'evidence' => array_map(static fn (RelationEvidence $claim): array => $claim->toArray(), $evidence),
            'sources' => $sources];
        $metadata['context_fingerprint'] = RelationTypeRules::fingerprint(['current' => $context, 'proposed' => $proposed, 'assertions' => array_values($material)]);
        $metadata['review_fingerprint'] = RelationTypeRules::fingerprint(['current' => $snapshot, 'proposed' => $metadata['proposed'],
            'context_fingerprint' => $metadata['context_fingerprint'], 'contract_version' => self::CONTRACT, 'policy_version' => RelationTypeRules::VERSION]);

        return $metadata;
    }
}
