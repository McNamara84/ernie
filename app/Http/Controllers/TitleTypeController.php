<?php

namespace App\Http\Controllers;

use App\Models\TitleType;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class TitleTypeController extends Controller
{
    /**
     * Return all title types.
     */
    public function index(): JsonResponse
    {
        $types = TitleType::query()
            ->orderByName()
            ->get(['id', 'name', 'slug']);

        return response()->json(
            $types->map(fn (TitleType $type): array => [
                'id' => $type->id,
                'name' => $type->name,
                'slug' => Str::kebab($type->slug),
            ])
        );
    }

    /**
     * Return all title types that are active for ELMO.
     */
    public function elmo(): JsonResponse
    {
        $types = TitleType::query()
            ->active()
            ->elmoActive()
            ->orderByName()
            ->get(['id', 'name', 'slug']);

        return response()->json(
            $types->map(fn (TitleType $type): array => [
                'id' => $type->id,
                'name' => $type->name,
                'slug' => Str::kebab($type->slug),
            ])
        );
    }

    /**
     * Return all title types that are active for Ernie.
     */
    public function ernie(): JsonResponse
    {
        $types = TitleType::query()
            ->active()
            ->orderByName()
            ->get(['id', 'name', 'slug']);

        return response()->json(
            $types->map(fn (TitleType $type): array => [
                'id' => $type->id,
                'name' => $type->name,
                'slug' => Str::kebab($type->slug),
            ])
        );
    }
}
