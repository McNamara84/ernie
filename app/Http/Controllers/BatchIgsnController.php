<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Resource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Controller for batch operations on IGSN resources.
 *
 * Designed for extensibility - future batch operations (export, status change)
 * can be added as new methods.
 */
class BatchIgsnController extends Controller
{
    /**
     * Delete multiple IGSN resources.
     *
     * Only admins can delete IGSN resources.
     *
     * @throws ValidationException
     */
    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        // Only admins can delete IGSNs
        if ($user === null || $user->role !== UserRole::ADMIN) {
            abort(403, 'You are not authorized to delete IGSNs.');
        }

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer', 'exists:resources,id'],
        ]);

        /** @var array<int> $ids */
        $ids = array_values(array_unique($validated['ids']));

        // Use transaction with row locking for atomic validation + delete
        // This ensures no race condition between checking igsnMetadata and deleting
        DB::transaction(function () use ($ids): void {
            // Lock the rows we're about to delete to prevent concurrent modifications
            $lockedResources = Resource::whereIn('id', $ids)
                ->whereHas('igsnMetadata')
                ->lockForUpdate()
                ->get();

            // Verify all resources are valid IGSNs
            if ($lockedResources->count() !== count($ids)) {
                throw ValidationException::withMessages([
                    'ids' => ['Some selected resources are not valid IGSNs.'],
                ]);
            }

            // Delete the locked resources
            $deletedCount = Resource::whereIn('id', $ids)->delete();

            // Verify all were deleted (should always succeed with locking, but safety check)
            if ($deletedCount !== count($ids)) {
                throw ValidationException::withMessages([
                    'ids' => ['Some IGSNs could not be deleted. Please refresh and try again.'],
                ]);
            }
        });

        $count = count($ids);
        $message = $count === 1
            ? 'IGSN deleted successfully.'
            : "{$count} IGSNs deleted successfully.";

        return redirect()
            ->route('igsns.index')
            ->with('success', $message);
    }

    // Future methods for batch operations:
    // public function export(Request $request): StreamedResponse { ... }
    // public function updateStatus(Request $request): RedirectResponse { ... }
}
