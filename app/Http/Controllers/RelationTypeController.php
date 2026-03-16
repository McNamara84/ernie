<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\RelationType;
use Illuminate\Http\JsonResponse;

class RelationTypeController extends Controller
{
    /**
     * Return all relation types.
     */
    public function index(): JsonResponse
    {
        $types = RelationType::query()
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        return response()->json(
            $types->map(fn (RelationType $type): array => [
                'id' => $type->id,
                'name' => $type->name,
                'slug' => $type->slug,
            ])
        );
    }

    /**
     * Return all relation types that are active for ELMO.
     */
    public function elmo(): JsonResponse
    {
        $types = RelationType::query()
            ->active()
            ->elmoActive()
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        return response()->json(
            $types->map(fn (RelationType $type): array => [
                'id' => $type->id,
                'name' => $type->name,
                'slug' => $type->slug,
            ])
        );
    }

    /**
     * Return all relation types that are active for ERNIE.
     */
    public function ernie(): JsonResponse
    {
        $types = RelationType::query()
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        return response()->json(
            $types->map(fn (RelationType $type): array => [
                'id' => $type->id,
                'name' => $type->name,
                'slug' => $type->slug,
            ])
        );
    }
}
