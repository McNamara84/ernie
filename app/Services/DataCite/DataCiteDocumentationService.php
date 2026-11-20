<?php

declare(strict_types=1);

namespace App\Services\DataCite;

use App\Services\DataCiteLinkedDataExporter;
use RuntimeException;

/** Public documentation for the exact published JSON schema and supported compact JSON-LD profile. */
final class DataCiteDocumentationService
{
    /** @var array<string, mixed> */
    private array $content = [];

    /** @var array<string, mixed> */
    private array $schema = [];

    /** @var array<string, mixed> */
    private array $context = [];

    /** @var array<string, mixed> */
    private array $mapping = [];

    private string $locale = 'en';

    private string $revision = 'v1';

    public function __construct(
        private readonly DataCiteSchemaPublicationService $publication,
        private readonly JsonSchemaDocumentationReaderService $reader,
        private readonly DataCiteLinkedDataExporter $exporter,
    ) {}

    /** @return array<string, mixed> */
    public function overview(?string $revision = null): array
    {
        $this->load($revision);

        return [
            'locale' => $this->locale, 'revision' => $this->revision, 'version' => '4.7',
            'copy' => $this->content['interface'], 'home' => $this->content['home'],
            'formats' => $this->formats($revision), 'versions' => $this->versions(),
            'schemaUrl' => $this->artifactUrl('schema.json'),
            'contextUrl' => $this->artifactUrl('context.jsonld'),
            'manifestUrl' => $this->artifactUrl('manifest.json'),
            'sourceUrl' => 'https://datacite-metadata-schema.readthedocs.io/en/4.7/',
            'igsnUrl' => url('/schemas/igsn/2.0/docs'),
            'citation' => $this->content['citation'],
        ];
    }

    /** @return array<string, mixed> */
    public function document(string $format, ?string $revision = null): array
    {
        $this->assertFormat($format);
        $this->load($revision);
        $jsonld = $format === 'jsonld';
        $fields = $this->reader->fields($this->schema, $this->content);
        $full = $this->exampleDocument($format, 'full');
        $copy = $this->content['interface'];
        if ($jsonld) {
            $copy = array_replace($copy, $this->content['jsonldInterface']);
        }
        $documented = [];
        foreach ($fields as $field) {
            $sourcePath = $field['path'];
            if ($jsonld) {
                $map = $this->mapping['mappings'][$sourcePath] ?? null;
                if (! is_array($map) || ! $map['supported']) {
                    continue;
                }
                $paths = $map['paths'];
                // Keep the literal DOI under identifier; @id is documented separately as a DOI URL.
                $field['path'] = $sourcePath === '$.doi' ? '$.identifier.value' : $paths[0];
                $field['sourcePath'] = $sourcePath;
                $field['qname'] = $this->term($field['path']);
                $field['name'] = $field['qname'];
                $field['semantic'] = $this->meaning($field['qname']);
                if (str_starts_with($field['type'], 'array')) {
                    $field['type'] = str_ends_with($sourcePath, '.polygonPoint') ? 'array<object>' : 'object or array';
                } else {
                    $sample = $this->sample($full, $field['path']);
                    $field['type'] = match (true) {
                        is_array($sample) => 'object', is_string($sample) => 'string',
                        is_int($sample), is_float($sample) => 'number', default => $field['type'],
                    };
                }
                if ($sourcePath === '$.publisher') {
                    $field['notes'] = $this->content['jsonldPublisherNotes'];
                }
            }
            $field['example'] = $this->fragment($full, $field['path'], $jsonld);
            $documented[] = $field;
        }
        if ($jsonld) {
            $documented[0]['name'] = $documented[0]['qname'] = 'Resource';
            $documented[0]['label'] = $this->content['jsonldRoot']['label'];
            $documented[0]['description'] = $this->content['jsonldRoot']['description'];
            unset($documented[0]['sourcePath']);
            $documented[0]['semantic'] = ['iri' => 'https://w3id.org/tib/datacite/class/Resource'];
            array_splice($documented, 1, 0, $this->keywordFields($full));
            array_unshift($documented[0]['children'], 'datacite-jsonld-context', 'datacite-jsonld-type', 'datacite-jsonld-id');
        }
        $ids = array_fill_keys(array_column($documented, 'id'), true);
        foreach ($documented as &$field) {
            $field['children'] = array_values(array_filter($field['children'], fn (string $id): bool => isset($ids[$id])));
        }
        unset($field);
        $base = $this->base($this->revision).'/'.$format;
        $validation = $this->content['validation'][$format];
        if (isset($validation['code'])) {
            $validation['code'] = str_replace('{schemaUrl}', $this->schema['$id'], $validation['code']);
        }

        return [
            'syntax' => 'json', 'fileExtension' => $format, 'fileLabel' => $jsonld ? 'JSON-LD' : 'JSON',
            'schemaName' => 'DataCite', 'eyebrow' => $copy['eyebrow'],
            'locale' => $this->locale, 'revision' => $this->revision, 'version' => '4.7',
            'format' => $format, 'formats' => $this->formats($revision), 'brandUrl' => url($this->base($revision)),
            'overviewUrl' => url($this->base($revision)), 'referenceUrl' => url($base),
            'namespace' => $jsonld ? 'https://w3id.org/tib/datacite/' : 'JSON Schema Draft 2020-12',
            'schemaUrl' => $this->artifactUrl($jsonld ? 'context.jsonld' : 'schema.json'),
            'manifestUrl' => $this->artifactUrl('manifest.json'),
            'sourceUrl' => 'https://datacite-metadata-schema.readthedocs.io/en/4.7/',
            'publicationUrl' => 'https://doi.org/10.14454/qdd3-ps68',
            'citation' => $this->content['citation'],
            'versions' => $this->versions($format), 'copy' => $copy,
            'introduction' => $this->content['introduction'][$format],
            'notes' => [...$this->content['notes'], ...($jsonld ? $this->content['jsonldNotes'] : [])],
            'validation' => $validation, 'fields' => $documented,
            'links' => $this->content['links'][$format],
            'examples' => [
                'minimal' => ['code' => $this->encode($this->exampleDocument($format, 'minimal')), 'url' => url($base.'/examples/minimal.'.$format)],
                'full' => ['code' => $this->encode($full), 'url' => url($base.'/examples/full.'.$format)],
            ],
        ];
    }

    public function example(string $format, string $name, ?string $revision = null): string
    {
        $this->assertFormat($format);
        if (! in_array($name, ['minimal', 'full'], true)) {
            throw new RuntimeException('The requested documentation example is unavailable.');
        }
        $this->load($revision);

        return $this->encode($this->exampleDocument($format, $name));
    }

    private function load(?string $revision): void
    {
        $this->revision = $revision ?? (string) config('datacite.schemas.revision');
        $schema = $this->publication->publicFile('schema.json', $this->revision);
        $context = $this->publication->publicFile('context.jsonld', $this->revision);
        if ($schema === null || $context === null) {
            throw new RuntimeException('The documentation revision is unavailable.');
        }
        $this->schema = $this->json($schema['path']);
        $this->context = $this->json($context['path'])['@context'];
        $this->mapping = $this->json(resource_path('data/schema-docs/datacite/4.7/'.$this->revision.'/profile-map.json'));
        if ($this->mapping['schemaSha256'] !== $schema['hash'] || $this->mapping['contextSha256'] !== $context['hash']) {
            throw new RuntimeException('The documentation profile does not match the published artifacts.');
        }
        $this->locale = (string) config('datacite.schemas.documentation_locale', 'en');
        $content = resource_path('data/schema-docs/datacite/4.7/'.$this->locale.'.json');
        if (! is_file($content)) {
            $this->locale = 'en';
            $content = resource_path('data/schema-docs/datacite/4.7/en.json');
        }
        $this->content = $this->json($content);
    }

    /** @return array<string, mixed> */
    private function json(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('A documentation source is unavailable.');
        }
        $value = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($value)) {
            throw new RuntimeException('A documentation source is invalid.');
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private function exampleDocument(string $format, string $name): array
    {
        $attributes = $this->json(resource_path('data/schema-docs/datacite/4.7/'.$this->revision.'/'.$name.'.json'));
        if ($format === 'json') {
            return $attributes;
        }
        $document = $this->exporter->exportAttributes($attributes);
        $document['@context'] = $this->artifactUrl('context.jsonld');

        return $document;
    }

    /** @param array<string, mixed> $value */
    private function encode(array $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    private function assertFormat(string $format): void
    {
        if (! in_array($format, ['json', 'jsonld'], true)) {
            throw new RuntimeException('The requested documentation format is unavailable.');
        }
    }

    private function base(?string $revision): string
    {
        return '/schemas/datacite/4.7/'.($revision === null ? '' : $revision.'/').'docs';
    }

    private function artifactUrl(string $file): string
    {
        return rtrim((string) config('app.url'), '/').'/schemas/datacite/4.7/'.$this->revision.'/'.$file;
    }

    /** @return list<array{key: string, label: string, url: string}> */
    private function formats(?string $revision): array
    {
        return [
            ['key' => 'json', 'label' => 'JSON', 'url' => url($this->base($revision).'/json')],
            ['key' => 'jsonld', 'label' => 'JSON-LD', 'url' => url($this->base($revision).'/jsonld')],
        ];
    }

    /** @return list<array{revision: string, label: string, url: string}> */
    private function versions(?string $format = null): array
    {
        return array_map(fn (string $revision): array => [
            'revision' => $revision, 'label' => $this->content['revisions'][$revision] ?? $revision,
            'url' => url($this->base($revision).($format === null ? '' : '/'.$format)),
        ], array_keys((array) config('datacite.schemas.revisions')));
    }

    private function term(string $path): string
    {
        $tokens = explode('.', str_replace('[]', '', substr($path, 2)));
        if (end($tokens) === 'value') {
            array_pop($tokens);
        }

        return $path === '$' ? 'Resource' : (string) end($tokens);
    }

    /** @return array<string, string> */
    private function meaning(string $term): array
    {
        $definition = $this->context[$term] ?? $this->findTerm($this->context, $term);
        if ($definition === null) {
            return [];
        }
        $iri = is_string($definition) ? $definition : ($definition['@id'] ?? null);
        $meaning = [];
        if (is_string($iri) && ! str_starts_with($iri, '@')) {
            $meaning['iri'] = $this->expand($iri);
        }
        if (is_array($definition)) {
            $type = $definition['@context']['value']['@type'] ?? $definition['@type'] ?? null;
            if (is_string($type)) {
                $meaning['valueType'] = $this->expand($type);
            }
            if (isset($definition['@container']) && is_string($definition['@container'])) {
                $meaning['container'] = $definition['@container'];
            }
            if (isset($definition['@context']['@vocab']) && is_string($definition['@context']['@vocab'])) {
                $meaning['vocabulary'] = $this->expand($definition['@context']['@vocab']);
            }
        }

        return $meaning;
    }

    /** @param array<string, mixed> $context */
    private function findTerm(array $context, string $term): mixed
    {
        if (array_key_exists($term, $context)) {
            return $context[$term];
        }
        foreach ($context as $value) {
            if (is_array($value)) {
                $found = $this->findTerm($value, $term);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    private function expand(string $iri): string
    {
        $parts = explode(':', $iri, 2);
        $prefix = $this->context[$parts[0]] ?? null;

        return count($parts) === 2 && is_string($prefix) && str_starts_with($prefix, 'http') ? $prefix.$parts[1] : $iri;
    }

    /** @param array<string, mixed> $document */
    private function sample(array $document, string $path): mixed
    {
        if ($path === '$') {
            return $document;
        }
        $tokens = explode('.', substr($path, 2));
        $value = $document;
        foreach ($tokens as $index => $token) {
            $key = str_replace('[]', '', $token);
            if (! is_array($value) || ! array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
            if (str_ends_with($token, '[]') && $index !== count($tokens) - 1 && is_array($value) && array_is_list($value)) {
                $value = $value[0] ?? null;
            }
        }

        return $value;
    }

    /** @param array<string, mixed> $document */
    private function fragment(array $document, string $path, bool $jsonld): ?string
    {
        if ($path === '$') {
            return $this->encode($document);
        }
        if ($jsonld && str_ends_with($path, '.value')) {
            $path = substr($path, 0, -6);
        }
        if ($jsonld && str_contains($path, '.attrs.')) {
            $path = substr($path, 0, (int) strpos($path, '.attrs.'));
        }
        $value = $this->sample($document, $path);
        if ($value === null) {
            return null;
        }
        $key = str_replace('[]', '', (string) substr($path, (int) strrpos($path, '.') + 1));

        return $this->encode([$key => $value]);
    }

    /** @param array<string, mixed> $full
     * @return list<array<string, mixed>>
     */
    private function keywordFields(array $full): array
    {
        $fields = [];
        foreach ($this->content['keywordFields'] as $key => $definition) {
            ['name' => $name, 'label' => $label, 'description' => $description, 'required' => $required] = $definition;
            $id = 'datacite-jsonld-'.$key;
            $fields[] = [
                'id' => $id, 'parentId' => 'datacite-root', 'groupId' => $id,
                'name' => $name, 'qname' => $name, 'path' => '$.'.$name,
                'label' => $label, 'description' => $description,
                'notes' => [$this->content['keywordNote']],
                'required' => $required, 'conditional' => false, 'min' => $required ? '1' : '0', 'max' => '1',
                'type' => 'string', 'values' => $key === 'type' ? ['Resource'] : [],
                'constraints' => $key === 'id' ? [['name' => 'format', 'value' => 'uri']] : [],
                'attributes' => [], 'children' => [], 'example' => $this->fragment($full, '$.'.$name, true),
            ];
        }

        return $fields;
    }
}
