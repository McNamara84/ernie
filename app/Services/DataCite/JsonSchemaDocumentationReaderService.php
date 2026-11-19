<?php

declare(strict_types=1);

namespace App\Services\DataCite;

use RuntimeException;

/** Reads field rules from the published schema, including local references and object alternatives. */
final class JsonSchemaDocumentationReaderService
{
    /** @var array<string, mixed> */
    private array $schema = [];

    /** @var array<string, mixed> */
    private array $text = [];

    /** @param array<string, mixed> $schema
     * @param  array<string, mixed>  $text
     * @return list<array<string, mixed>>
     */
    public function fields(array $schema, array $text): array
    {
        $this->schema = $schema;
        $this->text = $text;
        $fields = [];
        $this->describe($schema, '$', 'attributes', null, null, true, false, $fields);

        return $fields;
    }

    /** @param array<string, mixed> $definition
     * @param  list<array<string, mixed>>  $fields
     */
    private function describe(array $definition, string $path, string $name, ?string $parentId, ?string $groupId, bool $required, bool $conditional, array &$fields): void
    {
        if (substr_count($path, '.') > 24) {
            throw new RuntimeException('A documentation field exceeds the supported schema depth.');
        }
        $definition = $this->resolve($definition);
        $id = self::id($path);
        $groupId ??= $id;
        $types = $this->types($definition);
        $array = in_array('array', $types, true);
        $members = $array ? $this->resolve($definition['items'] ?? []) : $definition;
        $variants = $this->variants($members);
        $properties = $members['properties'] ?? [];
        $requiredChildren = $members['required'] ?? [];
        foreach ($variants as $variant) {
            $properties = array_replace($properties, $variant['properties'] ?? []);
            $requiredChildren = array_values(array_unique([...$requiredChildren, ...($variant['required'] ?? [])]));
        }
        $copy = $this->text['paths'][$path] ?? $this->text['fields'][$name] ?? [];
        $notes = $copy['notes'] ?? [];
        if (isset($definition['anyOf']) || isset($definition['oneOf'])) {
            $notes[] = $this->text['rules']['objectAlternative'];
        }
        foreach (($members['dependentRequired'] ?? []) as $trigger => $dependencies) {
            $notes[] = str_replace(['{field}', '{dependencies}'], [$trigger, implode(', ', $dependencies)], $this->text['rules']['dependentRequired']);
        }
        $field = [
            'id' => $id, 'parentId' => $parentId, 'groupId' => $parentId === null ? $id : ($groupId === self::id('$') ? $id : $groupId),
            'name' => $name, 'qname' => $name, 'path' => $path,
            'label' => $copy['label'] ?? $name,
            'description' => $copy['description'] ?? $definition['description'] ?? str_replace('{field}', $name, $this->text['rules']['fieldDescription']),
            'notes' => $notes, 'required' => $required, 'conditional' => $required && $conditional,
            'min' => $required ? (string) ($array ? ($definition['minItems'] ?? 0) : 1) : '0',
            'max' => $array ? (string) ($definition['maxItems'] ?? 'unbounded') : '1',
            'type' => implode(' or ', $types).($array ? '<'.implode(' or ', $this->types($members)).'>' : ''),
            'values' => $this->values($array ? $members : $definition),
            'constraints' => [...$this->constraints($definition), ...($array ? array_values(array_filter($this->constraints($members), fn (array $constraint): bool => $constraint['name'] === 'conditionalRules')) : [])], 'attributes' => [],
            'children' => array_map(fn (string|int $key): string => self::id($path.($array ? '[]' : '').'.'.$key), array_keys($properties)),
            'example' => null,
        ];
        $fields[] = $field;
        foreach ($properties as $key => $child) {
            if (! is_array($child)) {
                continue;
            }
            $this->describe($child, $path.($array ? '[]' : '').'.'.$key, $key, $id, $field['groupId'], in_array($key, $requiredChildren, true), $conditional || ! $required || count($types) > 1, $fields);
        }
    }

    public static function id(string $path): string
    {
        return $path === '$' ? 'datacite-root' : 'datacite-'.str_replace(['$.', '[]', '.'], ['', '', '-'], $path);
    }

    /** @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    private function resolve(array $definition): array
    {
        if (isset($definition['$ref'])) {
            $reference = (string) $definition['$ref'];
            if (! str_starts_with($reference, '#/')) {
                throw new RuntimeException('Documentation only resolves bundled local JSON Schema references.');
            }
            $target = $this->schema;
            foreach (explode('/', substr($reference, 2)) as $token) {
                $key = str_replace(['~1', '~0'], ['/', '~'], $token);
                if (! isset($target[$key]) || ! is_array($target[$key])) {
                    throw new RuntimeException('A documentation schema reference is missing.');
                }
                $target = $target[$key];
            }
            unset($definition['$ref']);
            $definition = array_replace($this->resolve($target), $definition);
        }
        foreach ($definition['allOf'] ?? [] as $part) {
            $part = $this->resolve($part);
            // Keep conditional branches scoped to their own allOf entry.
            if (isset($part['if'])) {
                continue;
            }
            $definition['properties'] = array_replace($definition['properties'] ?? [], $part['properties'] ?? []);
            $definition['required'] = array_values(array_unique([...($definition['required'] ?? []), ...($part['required'] ?? [])]));
            $definition = array_replace($part, $definition);
        }

        return $definition;
    }

    /** @param array<string, mixed> $definition
     * @return list<array<string, mixed>>
     */
    private function variants(array $definition): array
    {
        return array_values(array_map(fn (array $variant): array => $this->resolve($variant), $definition['anyOf'] ?? $definition['oneOf'] ?? []));
    }

    /** @param array<string, mixed> $definition
     * @return list<string>
     */
    private function types(array $definition): array
    {
        $types = (array) ($definition['type'] ?? []);
        foreach ($this->variants($definition) as $variant) {
            array_push($types, ...$this->types($variant));
        }

        return array_values(array_unique($types ?: ['any']));
    }

    /** @param array<string, mixed> $definition
     * @return list<string>
     */
    private function values(array $definition): array
    {
        $values = $definition['enum'] ?? (array_key_exists('const', $definition) ? [$definition['const']] : []);

        return array_values(array_map(fn (mixed $value): string => is_string($value) ? $value : (string) json_encode($value), $values));
    }

    /** @param array<string, mixed> $definition
     * @return list<array{name: string, value: string}>
     */
    private function constraints(array $definition): array
    {
        $constraints = [];
        foreach (['format', 'pattern', 'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf', 'minLength', 'maxLength', 'minItems', 'maxItems', 'uniqueItems', 'minProperties', 'maxProperties'] as $key) {
            if (array_key_exists($key, $definition)) {
                $constraints[] = ['name' => $key, 'value' => is_bool($definition[$key]) ? ($definition[$key] ? 'true' : 'false') : (string) $definition[$key]];
            }
        }
        $conditional = array_intersect_key($definition, array_fill_keys(['if', 'then', 'else', 'allOf', 'dependentRequired'], true));
        if ($conditional !== []) {
            $constraints[] = ['name' => 'conditionalRules', 'value' => json_encode($conditional, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)];
        }

        return $constraints;
    }
}
