<?php

declare(strict_types=1);

namespace Tests\Fixtures;

/**
 * Checks type existence and inherited property domains against the official vocabulary.
 * This deliberately does not implement the public validator's complete value/range checks.
 */
final class SchemaOrgVocabulary
{
    /** @return list<string> */
    public static function violations(array $document): array
    {
        $vocabulary = json_decode(file_get_contents(__DIR__.'/schemaorg-domains.json'), true, flags: JSON_THROW_ON_ERROR);
        $errors = [];
        self::visit($document, $vocabulary, $errors);

        return $errors;
    }

    private static function visit(array $node, array $vocabulary, array &$errors): void
    {
        $types = (array) ($node['@type'] ?? []);
        $ancestors = [];
        foreach ($types as $type) {
            $type = str_replace('https://schema.org/', '', $type);
            if (! array_key_exists($type, $vocabulary['classes'])) {
                $errors[] = 'Unknown object type: '.$type;
            } else {
                $ancestors = array_merge($ancestors, self::ancestors($type, $vocabulary['classes']));
            }
        }
        foreach ($node as $property => $value) {
            if ($types !== [] && ! str_starts_with($property, '@')) {
                $domains = $vocabulary['properties'][$property] ?? [];
                if (array_intersect($ancestors, $domains) === []) {
                    $errors[] = implode('/', $types).' does not support '.$property;
                }
            }
            if (is_array($value)) {
                self::visit($value, $vocabulary, $errors);
            }
        }
    }

    private static function ancestors(string $type, array $classes): array
    {
        $result = [$type];
        foreach ($classes[$type] as $parent) {
            $result = array_merge($result, self::ancestors($parent, $classes));
        }

        return $result;
    }
}
