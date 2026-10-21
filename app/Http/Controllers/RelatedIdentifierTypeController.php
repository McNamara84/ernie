<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\IdentifierType;
use App\Models\IdentifierTypePattern;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;

class RelatedIdentifierTypeController extends Controller
{
    /**
     * Return all identifier types with their patterns.
     */
    public function index(): JsonResponse
    {
        $types = IdentifierType::with(['patterns' => fn ($q) => $q->where('is_active', true)->orderByDesc('priority')])
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'description']);

        return response()->json($this->formatResponse($types));
    }

    /**
     * Return all identifier types that are active for ELMO, with active patterns.
     */
    public function elmo(): JsonResponse
    {
        $types = IdentifierType::query()
            ->elmoActive()
            ->with(['patterns' => fn ($q) => $q->where('is_active', true)->orderByDesc('priority')->orderBy('id')])
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'description']);

        return response()->json($this->formatElmoResponse($types));
    }

    /** Return entries independently enabled for ELMO-MSL. */
    public function elmoMsl(): JsonResponse
    {
        $types = IdentifierType::query()
            ->elmoMslActive()
            ->with(['patterns' => fn ($q) => $q->where('is_active', true)->orderByDesc('priority')])
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'description']);

        return response()->json($this->formatResponse($types));
    }

    /**
     * Return all identifier types that are active for ERNIE, with active patterns.
     */
    public function ernie(): JsonResponse
    {
        $types = IdentifierType::query()
            ->active()
            ->with(['patterns' => fn ($q) => $q->where('is_active', true)->orderByDesc('priority')])
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'description']);

        return response()->json($this->formatResponse($types));
    }

    /**
     * Add the legacy ELMO validation field without conflating detection rules.
     *
     * @param  Collection<int, IdentifierType>  $types
     * @return list<array{id: int, name: string, slug: string, description: string|null, patterns: array{validation: list<array{pattern: string, priority: int}>, detection: list<array{pattern: string, priority: int}>}, pattern: string, checksum?: array{algorithms: list<string>, mode: string}}>
     */
    private function formatElmoResponse(Collection $types): array
    {
        return array_map(static function (array $type): array {
            $alternatives = array_map(
                static fn (array $pattern): string => '(?:'.$pattern['pattern'].')',
                array_filter($type['patterns']['validation'], static fn (array $pattern): bool => $pattern['pattern'] !== ''),
            );

            // The end guard prevents JavaScript's $ anchor accepting a final
            // newline. Empty pattern lists must never become an empty regex.
            $type['pattern'] = $alternatives === [] ? '(?!)' : '^(?:'.implode('|', $alternatives).')(?![\s\S])';

            $algorithms = match ($type['slug']) {
                'ISBN' => ['isbn10-mod11', 'isbn13-mod10'],
                'EAN13' => ['gtin13-mod10'],
                default => [],
            };

            if ($algorithms !== []) {
                $type['checksum'] = ['algorithms' => $algorithms, 'mode' => 'advisory'];
            }

            return $type;
        }, $this->formatResponse($types));
    }

    /**
     * Format the response with grouped patterns.
     *
     * @param  Collection<int, IdentifierType>  $types
     * @return list<array{id: int, name: string, slug: string, description: string|null, patterns: array{validation: list<array{pattern: string, priority: int}>, detection: list<array{pattern: string, priority: int}>}}>
     */
    private function formatResponse(Collection $types): array
    {
        return array_values($types->map(fn (IdentifierType $type): array => [
            'id' => $type->id,
            'name' => $type->name,
            'slug' => $type->slug,
            'description' => $type->description,
            'patterns' => [
                'validation' => array_values($type->patterns
                    ->where('type', 'validation')
                    ->values()
                    ->map(fn (IdentifierTypePattern $pattern): array => [
                        'pattern' => $pattern->pattern,
                        'priority' => $pattern->priority,
                    ])
                    ->all()),
                'detection' => array_values($type->patterns
                    ->where('type', 'detection')
                    ->values()
                    ->map(fn (IdentifierTypePattern $pattern): array => [
                        'pattern' => $pattern->pattern,
                        'priority' => $pattern->priority,
                    ])
                    ->all()),
            ],
        ])->all());
    }
}
