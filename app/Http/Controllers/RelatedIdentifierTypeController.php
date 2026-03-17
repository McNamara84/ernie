<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\IdentifierType;
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
            ->get(['id', 'name', 'slug']);

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
            ->get(['id', 'name', 'slug']);

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
            ->get(['id', 'name', 'slug']);

        return response()->json($this->formatResponse($types));
    }

    /**
     * Format the response with grouped patterns.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, IdentifierType>  $types
     * @return array<int, array{id: int, name: string, slug: string, patterns: array{validation: array<int, array{pattern: string, priority: int}>, detection: array<int, array{pattern: string, priority: int}>}}>
     */
    private function formatResponse($types): array
    {
        return $types->map(fn (IdentifierType $type): array => [
            'id' => $type->id,
            'name' => $type->name,
            'slug' => $type->slug,
            'patterns' => [
                'validation' => $type->patterns
                    ->where('type', 'validation')
                    ->values()
                    ->map(fn ($p): array => [
                        'pattern' => $p->pattern,
                        'priority' => $p->priority,
                    ])
                    ->all(),
                'detection' => $type->patterns
                    ->where('type', 'detection')
                    ->values()
                    ->map(fn ($p): array => [
                        'pattern' => $p->pattern,
                        'priority' => $p->priority,
                    ])
                    ->all(),
            ],
        ])->all();
    }
}
