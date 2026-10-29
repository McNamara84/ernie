<?php

declare(strict_types=1);

namespace App\Services\B2inst;

use InvalidArgumentException;

/**
 * @phpstan-type Instrument array{id: string, pid: string, pidType: string, name: string, description: string, landingPage: string, owners: list<string>, manufacturers: list<string>, model: string|null, instrumentTypes: list<string>, measuredVariables: list<string>}
 */
class B2instInstrumentNormalizer
{
    public function __construct(private readonly B2instPidResolver $resolver) {}

    /**
     * Invalid records are excluded; resolver uncertainty/conflicts abort the import.
     *
     * @param  array<string, mixed>  $record
     * @return Instrument
     */
    public function normalize(array $record): array
    {
        if (data_get($record, 'access.record') !== 'public' || ($record['is_published'] ?? null) !== true
            || data_get($record, 'deletion_status.is_deleted') !== false || data_get($record, 'versions.is_latest') !== true) {
            throw new InvalidArgumentException('Record is not public, published, current and undeleted.');
        }
        $meta = $record['metadata'] ?? null;
        if (! is_array($meta) || ($meta['SchemaVersion'] ?? null) !== '1.0') {
            throw new InvalidArgumentException('Unsupported or missing PIDINST SchemaVersion.');
        }
        $id = $this->string($record['id'] ?? null, 'id', true);
        $name = $this->string($meta['Name'] ?? null, 'Name', true);
        $identifier = data_get($meta, 'Identifier.identifierValue');
        $pid = $this->string($identifier, 'Identifier', true);
        $type = strtolower($this->string(data_get($meta, 'Identifier.identifierType'), 'identifierType', true));
        $type = match ($type) {
            'handle' => 'Handle',
            'doi' => 'DOI',
            'url' => 'URL',
            default => throw new InvalidArgumentException('Unsupported identifier type: '.$type),
        };
        // Validate all field shapes before making resolver requests.
        $instrument = [
            'id' => $id, 'pid' => $pid, 'pidType' => $type, 'name' => $name,
            'description' => $this->string($meta['Description'] ?? '', 'Description'),
            'landingPage' => $this->string($meta['LandingPage'] ?? '', 'LandingPage'),
            'owners' => $this->names($meta['Owner'] ?? [], 'ownerName'),
            'manufacturers' => $this->names($meta['Manufacturer'] ?? [], 'manufacturerName'),
            'model' => ($meta['Model'] ?? null) === null ? null : $this->string(data_get($meta, 'Model.modelName'), 'Model.modelName', true),
            'instrumentTypes' => $this->names($meta['InstrumentType'] ?? [], 'instrumentTypeName'),
            'measuredVariables' => $this->names($meta['MeasuredVariable'] ?? [], null),
        ];
        if ($type === 'Handle') {
            $provider = data_get($record, 'pids.epic.identifier');
            $instrument['pid'] = $this->resolver->resolve($pid, $provider === null ? null : $this->string($provider, 'pids.epic.identifier', true), $record);
        } elseif ($type === 'DOI') {
            $pid = preg_replace('~^https?://(?:dx\.)?doi\.org/~i', '', $pid) ?? $pid;
            if (! preg_match('~^10\.\d{4,9}/\S+$~iD', $pid)) {
                throw new InvalidArgumentException('Invalid DOI identifier.');
            }
            $instrument['pid'] = $pid;
        } elseif (filter_var($pid, FILTER_VALIDATE_URL) === false || ! in_array(parse_url($pid, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new InvalidArgumentException('Invalid URL identifier.');
        }

        return $instrument;
    }

    /** @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public function provenance(array $record): array
    {
        return [
            'id' => $record['id'], 'parentId' => data_get($record, 'parent.id'),
            'versions' => $record['versions'] ?? null, 'revisionId' => $record['revision_id'] ?? null,
            'updated' => $record['updated'] ?? null, 'schemaVersion' => data_get($record, 'metadata.SchemaVersion'),
            'metadataIdentifier' => data_get($record, 'metadata.Identifier'), 'providerPid' => data_get($record, 'pids.epic.identifier'),
            'recordUrl' => data_get($record, 'links.self_html'), 'communities' => data_get($record, 'parent.communities.ids', []),
            'alternateIdentifiers' => data_get($record, 'metadata.AlternateIdentifier', []),
        ];
    }

    private function string(mixed $value, string $field, bool $required = false): string
    {
        if (! is_string($value) || ($required && trim($value) === '')) {
            throw new InvalidArgumentException('Invalid or missing '.$field.'.');
        }

        return trim($value);
    }

    /** @return list<string> */
    private function names(mixed $values, ?string $field): array
    {
        if (! is_array($values) || ! array_is_list($values)) {
            throw new InvalidArgumentException('Invalid list: '.($field ?? 'MeasuredVariable'));
        }

        return array_map(fn (mixed $value): string => $this->string($field === null ? $value : data_get($value, $field), $field ?? 'MeasuredVariable', true), $values);
    }
}
