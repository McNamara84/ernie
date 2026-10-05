<?php

declare(strict_types=1);

namespace App\Services\SchemaOrg;

/** @phpstan-import-type Content from CreativeWorkMetadataService */
final class DescribedObjectMetadataService
{
    private const OBJECT_PROPERTIES = [
        '@context', '@type', '@id', 'identifier', 'url', 'name', 'description', 'additionalType', 'subjectOf',
    ];

    public function __construct(private readonly CreativeWorkMetadataService $creativeWork) {}

    /**
     * @param  array<string, mixed>  $metadata
     * @param  Content|null  $content
     * @return array<string, mixed>
     */
    public function map(array $metadata, ?array $content): array
    {
        $object = array_intersect_key($metadata, array_flip(self::OBJECT_PROPERTIES));
        $description = array_diff_key($metadata, array_flip([
            '@context', '@type', '@id', 'identifier', 'url', 'additionalType', 'subjectOf', 'isAccessibleForFree',
        ]));
        $description['@type'] = 'CreativeWork';
        $description['name'] = 'Metadata description of '.(string) $metadata['name'];

        $baseUrl = $metadata['url'] ?? $metadata['@id'] ?? null;
        if (is_string($baseUrl) && $baseUrl !== '') {
            $description['@id'] = $baseUrl.'#resource-description';
        }
        if (isset($metadata['@id'])) {
            $description['about'] = ['@id' => $metadata['@id']];
        }
        if (isset($metadata['conditionsOfAccess'])) {
            // The record is public; this assertion describes access to the actual object.
            $description['conditionsOfAccess'] = 'Access to the described resource: '.(string) $metadata['conditionsOfAccess'];
        }

        /** @var list<array<string, mixed>> $subjectOf */
        $subjectOf = $object['subjectOf'] ?? [];
        $subjectOf[] = $this->creativeWork->map($description, $content);
        $object['subjectOf'] = $subjectOf;

        return $object;
    }
}
