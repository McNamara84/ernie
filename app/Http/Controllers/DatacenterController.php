<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Datacenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DatacenterController extends Controller
{
    /**
     * List all datacenters (for editor dropdown).
     */
    public function index(): JsonResponse
    {
        $datacenters = Datacenter::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json($datacenters);
    }

    /**
     * Store a new datacenter (Settings management).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:datacenters,name'],
        ]);

        $datacenter = Datacenter::create($validated);

        return response()->json([
            'datacenter' => [
                'id' => $datacenter->id,
                'name' => $datacenter->name,
                'resourceCount' => 0,
            ],
            'message' => 'Datacenter created successfully.',
        ], 201);
    }

    /**
     * Delete a datacenter (blocked if resources are assigned).
     */
    public function destroy(Datacenter $datacenter): JsonResponse
    {
        if ($datacenter->resources()->exists()) {
            return response()->json([
                'message' => 'Cannot delete datacenter with assigned resources.',
            ], 422);
        }

        $datacenter->delete();

        return response()->json([
            'message' => 'Datacenter deleted successfully.',
        ]);
    }
}
