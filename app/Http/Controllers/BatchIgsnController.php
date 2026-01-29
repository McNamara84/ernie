<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Resource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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
        $ids = $validated['ids'];

        // Verify all resources are IGSNs (have igsnMetadata)
        $igsnCount = Resource::whereIn('id', $ids)
            ->whereHas('igsnMetadata')
            ->count();

        if ($igsnCount !== count($ids)) {
            abort(422, 'Some selected resources are not valid IGSNs.');
        }

        // Delete only resources that are valid IGSNs (same constraint as validation)
        // This prevents race conditions where igsnMetadata could be removed between check and delete
        $deletedCount = Resource::whereIn('id', $ids)
            ->whereHas('igsnMetadata')
            ->delete();

        // Verify all requested resources were deleted
        if ($deletedCount !== count($ids)) {
            abort(422, 'Some IGSNs could not be deleted. Please refresh and try again.');
        }

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
