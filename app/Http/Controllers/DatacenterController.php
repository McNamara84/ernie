<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Datacenter\StoreDatacenterRequest;
use App\Http\Requests\Datacenter\UpdateDatacenterRequest;
use App\Models\Datacenter;
use App\Services\DatacenterNameService;
use Illuminate\Http\JsonResponse;

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
    public function store(StoreDatacenterRequest $request, DatacenterNameService $names): JsonResponse
    {
        $datacenter = $names->create($request->validated('name'));

        return response()->json([
            'datacenter' => [
                'id' => $datacenter->id,
                'name' => $datacenter->name,
                'resources_count' => 0,
            ],
            'message' => 'Datacenter created successfully.',
        ], 201);
    }

    public function update(UpdateDatacenterRequest $request, Datacenter $datacenter, DatacenterNameService $names): JsonResponse
    {
        $datacenter = $names->rename($datacenter, $request->validated('name'));

        return response()->json([
            'datacenter' => [
                'id' => $datacenter->id,
                'name' => $datacenter->name,
                'resources_count' => $datacenter->resources()->count(),
            ],
            'message' => 'Datacenter renamed successfully.',
        ]);
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
