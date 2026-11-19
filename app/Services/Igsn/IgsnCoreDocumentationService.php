<?php

declare(strict_types=1);

namespace App\Services\Igsn;

use App\Services\Igsn\Export\IgsnCoreSchemaResolver;
use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

/** Builds the reference from the exact, checksummed XSD revision being served. */
final class IgsnCoreDocumentationService
{
    private const XS = 'http://www.w3.org/2001/XMLSchema';

    /** @var array<string, DOMElement> */
    private array $types = [];

    /** @var array<string, DOMElement> */
    private array $declarations = [];

    /** @var array<string, mixed> */
    private array $content = [];

    public function __construct(private readonly IgsnCoreSchemaResolver $schemas) {}

    /** @return array<string, mixed> */
    public function document(?string $revision = null): array
    {
        $revision ??= (string) config('igsn_core.revision');
        $locale = (string) config('igsn_core.documentation_locale', 'en');
        // Text lives outside the UI. Additional translations can use the same keys.
        $contentPath = resource_path('data/schema-docs/igsn-core/2.0/'.$locale.'.json');
        if (! is_file($contentPath)) {
            $locale = 'en';
            $contentPath = resource_path('data/schema-docs/igsn-core/2.0/en.json');
        }
        $this->content = json_decode((string) file_get_contents($contentPath), true, flags: JSON_THROW_ON_ERROR);
        $this->types = $this->declarations = [];
        $root = null;
        foreach ($this->schemas->files($revision) as $file) {
            if (! str_ends_with($file['path'], '.xsd')) {
                continue;
            }
            $artifact = $this->schemas->publicFile($file['path'], $revision);
            if ($artifact === null) {
                throw new RuntimeException('A documentation schema dependency is unavailable.');
            }
            $document = new DOMDocument;
            $document->loadXML((string) file_get_contents($artifact['path']), LIBXML_NONET);
            $schema = $document->documentElement;
            if ($schema === null) {
                throw new RuntimeException('A documentation schema is invalid.');
            }
            foreach ($this->xsChildren($schema) as $declaration) {
                $key = $schema->getAttribute('targetNamespace').'#'.$declaration->getAttribute('name');
                if (in_array($declaration->localName, ['simpleType', 'complexType'], true)) {
                    $this->types[$key] = $declaration;
                } elseif (in_array($declaration->localName, ['element', 'attribute'], true)) {
                    $this->declarations[$declaration->localName.':'.$key] = $declaration;
                    if ($file['path'] === 'core.xsd' && $declaration->getAttribute('name') === 'resource') {
                        $root = $declaration;
                    }
                }
            }
        }
        if ($root === null) {
            throw new RuntimeException('The Core resource declaration is unavailable.');
        }
        $fields = [];
        $fullXml = $this->example('full', $revision);
        $example = new DOMDocument;
        $example->loadXML($fullXml, LIBXML_NONET);
        $examplePath = new DOMXPath($example);
        $examplePath->registerNamespace('igsn', IgsnCoreSchemaResolver::NAMESPACE);
        $examplePath->registerNamespace('daci', 'http://datacite.org/schema/kernel-4');
        $this->describe($root, '', null, null, false, $fields, $examplePath);
        $manifest = json_decode((string) file_get_contents($this->schemas->directory($revision).'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $base = '/schemas/igsn/2.0/'.$revision.'/docs';

        return [
            'locale' => $locale,
            'revision' => $revision,
            'version' => '2.0',
            'namespace' => IgsnCoreSchemaResolver::NAMESPACE,
            'schemaUrl' => $this->schemas->url('core.xsd', $revision),
            'manifestUrl' => $this->schemas->url('manifest.json', $revision),
            'sourceUrl' => 'https://github.com/'.$manifest['repository'].'/blob/'.$manifest['commit'].'/IGSN_Core_by_GFZ_DataServices.xsd',
            'publicationUrl' => 'https://doi.org/10.5281/zenodo.23831602',
            'citation' => $this->content['citation'],
            'versions' => array_map(fn (string $key): array => [
                'revision' => $key,
                'label' => $this->content['revisions'][$key] ?? $key,
                'url' => url('/schemas/igsn/2.0/'.$key.'/docs'),
            ], array_keys((array) config('igsn_core.revisions'))),
            'copy' => $this->content['interface'],
            'introduction' => $this->content['introduction'],
            'notes' => $this->content['compatibility'][$revision] ?? [],
            'fields' => $fields,
            'examples' => [
                'minimal' => ['xml' => $this->example('minimal', $revision), 'url' => url($base.'/examples/minimal.xml')],
                'full' => ['xml' => $fullXml, 'url' => url($base.'/examples/full.xml')],
            ],
        ];
    }

    public function example(string $name, ?string $revision = null): string
    {
        $revision ??= (string) config('igsn_core.revision');
        if (! in_array($name, ['minimal', 'full'], true) || ! is_string(config('igsn_core.revisions.'.$revision))) {
            throw new RuntimeException('The requested Core example is unavailable.');
        }
        $path = resource_path('data/schema-docs/igsn-core/2.0/'.$revision.'/'.$name.'.xml');
        if (! is_file($path)) {
            throw new RuntimeException('The documented Core example is missing.');
        }

        return (string) file_get_contents($path);
    }

    /** @param list<array<string, mixed>> $fields */
    private function describe(DOMElement $declaration, string $parentPath, ?string $parentId, ?string $groupId, bool $optionalParent, array &$fields, DOMXPath $example): void
    {
        $name = $declaration->getAttribute('name');
        if ($name === '' || substr_count($parentPath, '/') > 20) {
            throw new RuntimeException('An unsupported Core documentation declaration was found.');
        }
        $qname = $this->qualifiedName($declaration, false);
        $path = $parentPath.'/'.$qname;
        $id = str_replace(['/', ':'], ['-', '-'], ltrim($path, '/'));
        $groupId ??= $parentId === null ? null : $id;
        $type = $this->type($declaration);
        $children = $type === null ? [] : $this->members($type, 'element');
        $attributes = [];
        foreach ($type === null ? [] : $this->members($type, 'attribute') as $attribute) {
            $attributeName = $attribute->getAttribute('name');
            if ($attributeName === '' || $attribute->getAttribute('use') === 'prohibited') {
                continue;
            }
            $attributeType = $this->type($attribute);
            $text = $this->content['attributes'][$attributeName] ?? [];
            $attributes[] = [
                'name' => $attributeName,
                'qname' => $this->qualifiedName($attribute, true),
                'label' => $text['label'] ?? $attributeName,
                'description' => $text['description'] ?? $this->annotation($attribute),
                'notes' => $text['notes'] ?? [],
                'required' => $attribute->getAttribute('use') === 'required',
                'type' => $this->typeName($attribute, $attributeType),
                'values' => $this->values($attributeType),
                'constraints' => $this->constraints($attributeType),
                'fixed' => $attribute->hasAttribute('fixed') ? $attribute->getAttribute('fixed') : null,
                'default' => $attribute->hasAttribute('default') ? $attribute->getAttribute('default') : null,
            ];
        }
        $text = $this->content['elements'][$name] ?? [];
        $required = ! $declaration->hasAttribute('minOccurs') || $declaration->getAttribute('minOccurs') !== '0';
        $exampleNodes = $example->query($path);
        $node = $exampleNodes === false ? null : $exampleNodes->item(0);
        $fields[] = [
            'id' => $id, 'parentId' => $parentId, 'groupId' => $groupId ?? $id,
            'name' => $name, 'qname' => $qname, 'path' => $path,
            'label' => $text['label'] ?? $name,
            'description' => $text['description'] ?? $this->annotation($declaration),
            'notes' => $text['notes'] ?? [],
            'required' => $required, 'conditional' => $required && $optionalParent,
            'min' => $declaration->hasAttribute('minOccurs') ? $declaration->getAttribute('minOccurs') : '1',
            'max' => $declaration->getAttribute('maxOccurs') ?: '1',
            'type' => $this->typeName($declaration, $type),
            'values' => $this->values($type), 'constraints' => $this->constraints($type), 'attributes' => $attributes,
            'children' => array_map(fn (DOMElement $child): string => $id.'-'.str_replace(':', '-', $this->qualifiedName($child, false)), $children),
            'example' => $node instanceof DOMElement ? trim((string) $node->ownerDocument?->saveXML($node)) : null,
        ];
        foreach ($children as $child) {
            $this->describe($child, $path, $id, $groupId, $optionalParent || ! $required, $fields, $example);
        }
    }

    /** @return list<DOMElement> */
    private function xsChildren(DOMElement $element): array
    {
        $children = [];
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement && $child->namespaceURI === self::XS) {
                $children[] = $child;
            }
        }

        return $children;
    }

    private function type(DOMElement $declaration): ?DOMElement
    {
        foreach ($this->xsChildren($declaration) as $child) {
            if (in_array($child->localName, ['simpleType', 'complexType'], true)) {
                return $child;
            }
        }

        return $this->namedType($declaration, $declaration->getAttribute('type'));
    }

    private function namedType(DOMElement $context, string $name): ?DOMElement
    {
        if ($name === '') {
            return null;
        }
        $parts = explode(':', $name, 2);
        $namespace = $context->lookupNamespaceURI(count($parts) === 2 ? $parts[0] : null);

        return $this->types[$namespace.'#'.end($parts)] ?? null;
    }

    /** @return list<DOMElement> */
    private function members(DOMElement $type, string $kind): array
    {
        $members = [];
        foreach ($this->xsChildren($type) as $child) {
            if ($child->localName === $kind) {
                if ($child->hasAttribute('ref')) {
                    $parts = explode(':', $child->getAttribute('ref'), 2);
                    $namespace = $child->lookupNamespaceURI(count($parts) === 2 ? $parts[0] : null);
                    $child = $this->declarations[$kind.':'.$namespace.'#'.end($parts)] ?? $child;
                }
                $members[] = $child;
            } elseif (in_array($child->localName, ['sequence', 'choice', 'all', 'simpleContent', 'complexContent', 'extension', 'restriction'], true)) {
                $base = $this->namedType($child, $child->getAttribute('base'));
                if ($base !== null && $base !== $type) {
                    array_push($members, ...$this->members($base, $kind));
                }
                array_push($members, ...$this->members($child, $kind));
            }
        }

        return $members;
    }

    /** @return list<string> */
    private function values(?DOMElement $type): array
    {
        if ($type === null) {
            return [];
        }
        $values = [];
        foreach ($this->xsChildren($type) as $child) {
            if ($child->localName === 'enumeration') {
                $values[] = $child->getAttribute('value');
            } elseif ($child->localName === 'restriction') {
                $base = $this->namedType($child, $child->getAttribute('base'));
                $values = [...$this->values($base), ...$this->values($child)];
            }
        }

        return array_values(array_unique($values));
    }

    private function typeName(DOMElement $declaration, ?DOMElement $type): string
    {
        if ($declaration->hasAttribute('type')) {
            return $declaration->getAttribute('type');
        }
        if ($type !== null) {
            foreach ($this->xsChildren($type) as $child) {
                if ($child->localName === 'restriction' && $child->hasAttribute('base')) {
                    return $child->getAttribute('base');
                }
                if ($child->localName === 'simpleContent') {
                    foreach ($this->xsChildren($child) as $extension) {
                        if ($extension->hasAttribute('base')) {
                            return $extension->getAttribute('base');
                        }
                    }
                }
            }
        }

        return $type?->localName === 'complexType' ? 'xs:complexType' : 'xs:string';
    }

    /** @return list<array{name: string, value: string}> */
    private function constraints(?DOMElement $type): array
    {
        if ($type === null) {
            return [];
        }
        $constraints = [];
        foreach ($this->xsChildren($type) as $child) {
            if (in_array($child->localName, ['minInclusive', 'maxInclusive', 'minExclusive', 'maxExclusive', 'minLength', 'maxLength', 'length', 'pattern'], true)) {
                $constraints[] = ['name' => $child->localName, 'value' => $child->getAttribute('value')];
            } elseif ($child->localName === 'restriction') {
                array_push($constraints, ...$this->constraints($this->namedType($child, $child->getAttribute('base'))), ...$this->constraints($child));
            }
        }

        return $constraints;
    }

    private function qualifiedName(DOMElement $declaration, bool $attribute): string
    {
        $schema = $declaration->ownerDocument?->documentElement;
        $namespace = $schema?->getAttribute('targetNamespace') ?? '';
        $form = $declaration->getAttribute('form') ?: $schema?->getAttribute($attribute ? 'attributeFormDefault' : 'elementFormDefault');
        $prefix = match ($namespace) {
            IgsnCoreSchemaResolver::NAMESPACE => 'igsn:',
            'http://datacite.org/schema/kernel-4' => 'daci:',
            'http://www.w3.org/XML/1998/namespace' => 'xml:',
            default => '',
        };

        return (($form === 'qualified' || $declaration->parentNode === $schema) ? $prefix : '').$declaration->getAttribute('name');
    }

    private function annotation(DOMElement $declaration): string
    {
        $texts = [];
        foreach ($this->xsChildren($declaration) as $annotation) {
            if ($annotation->localName === 'annotation') {
                foreach ($this->xsChildren($annotation) as $documentation) {
                    if ($documentation->localName === 'documentation') {
                        $texts[] = trim((string) preg_replace('/\s+/u', ' ', $documentation->textContent));
                    }
                }
            }
        }

        return implode(' ', $texts);
    }
}
