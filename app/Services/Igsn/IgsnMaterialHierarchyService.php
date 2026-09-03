<?php

declare(strict_types=1);

namespace App\Services\Igsn;

use App\Enums\Igsn\IgsnMaterial;

final class IgsnMaterialHierarchyService
{
    /**
     * Resolve selected material tree nodes to canonical stored material values.
     *
     * @param  list<string>  $selectedNodes
     * @return list<string>|null Null means at least one selected node is unknown.
     */
    public function resolve(array $selectedNodes): ?array
    {
        $canonicalValues = array_map(
            static fn (IgsnMaterial $material): string => $material->value,
            IgsnMaterial::cases(),
        );
        $resolved = [];

        foreach ($selectedNodes as $selectedNode) {
            $matches = array_values(array_filter(
                $canonicalValues,
                static fn (string $value): bool => $value === $selectedNode
                    || str_starts_with($value, $selectedNode.'>'),
            ));

            if ($matches === []) {
                return null;
            }

            foreach ($matches as $match) {
                $resolved[$match] = true;
            }
        }

        return array_keys($resolved);
    }

    /**
     * Build the material tree from exact-value resource counts.
     *
     * @param  array<string, int>  $counts
     * @param  list<string>  $selectedNodes
     * @return list<array{value: string, label: string, count: int, children: list<mixed>}>
     */
    public function buildTree(array $counts, array $selectedNodes = []): array
    {
        /** @var array<string, array{value: string, label: string, count: int, selected: bool, children: array<string, mixed>}> $roots */
        $roots = [];
        $selectedLookup = array_fill_keys($selectedNodes, true);

        foreach (IgsnMaterial::cases() as $material) {
            $segments = explode('>', $material->value);
            $currentPath = '';
            $level = &$roots;

            foreach ($segments as $segment) {
                $currentPath = $currentPath === '' ? $segment : $currentPath.'>'.$segment;
                $level[$segment] ??= [
                    'value' => $currentPath,
                    'label' => $this->labelFor($currentPath, $segment),
                    'count' => 0,
                    'selected' => isset($selectedLookup[$currentPath]),
                    'children' => [],
                ];
                $level = &$level[$segment]['children'];
            }

            unset($level);
        }

        $tree = $this->finalizeLevel($roots, $counts);

        return array_values(array_filter(
            $tree,
            static fn (array $node): bool => $node['count'] > 0
                || in_array($node['value'], $selectedNodes, true)
                || self::containsSelectedDescendant($node, $selectedLookup),
        ));
    }

    private function labelFor(string $path, string $segment): string
    {
        if (str_contains($path, '>')) {
            return $segment;
        }

        $material = IgsnMaterial::tryFrom($path);

        return $material?->label() ?? $segment;
    }

    /**
     * @param  array<string, array{value: string, label: string, count: int, selected: bool, children: array<string, mixed>}>  $level
     * @param  array<string, int>  $counts
     * @return list<array{value: string, label: string, count: int, children: list<mixed>}>
     */
    private function finalizeLevel(array $level, array $counts): array
    {
        $nodes = [];

        foreach ($level as $node) {
            /** @var array<string, array{value: string, label: string, count: int, selected: bool, children: array<string, mixed>}> $childLevel */
            $childLevel = $node['children'];
            $children = $this->finalizeLevel($childLevel, $counts);
            $count = ($counts[$node['value']] ?? 0)
                + array_sum(array_column($children, 'count'));

            if ($count === 0 && ! $node['selected'] && $children === []) {
                continue;
            }

            $nodes[] = [
                'value' => $node['value'],
                'label' => $node['label'],
                'count' => $count,
                'children' => $children,
            ];
        }

        usort($nodes, static fn (array $left, array $right): int => strnatcasecmp($left['label'], $right['label']));

        return $nodes;
    }

    /**
     * @param  array{value: string, label: string, count: int, children: list<mixed>}  $node
     * @param  array<string, true>  $selectedLookup
     */
    private static function containsSelectedDescendant(array $node, array $selectedLookup): bool
    {
        foreach ($node['children'] as $child) {
            if (isset($selectedLookup[$child['value']]) || self::containsSelectedDescendant($child, $selectedLookup)) {
                return true;
            }
        }

        return false;
    }
}
