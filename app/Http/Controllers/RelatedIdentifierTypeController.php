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
        $types = IdentifierType::with(['patterns' => fn ($q) => $q->active()->orderByDesc('priority')])
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
            ->active()
            ->elmoActive()
            ->with(['patterns' => fn ($q) => $q->active()->orderByDesc('priority')])
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
            ->with(['patterns' => fn ($q) => $q->active()->orderByDesc('priority')])
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'description']);

        return response()->json($this->formatResponse($types));
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
