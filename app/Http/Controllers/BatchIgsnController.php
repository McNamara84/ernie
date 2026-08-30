<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Batch\DestroyIgsnsRequest;
use App\Models\Resource;
use App\Support\IgsnBulkOperation;
use Illuminate\Http\RedirectResponse;
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
    public function destroy(DestroyIgsnsRequest $request): RedirectResponse
    {
        /** @var array{ids: array<int, int>} $validated */
        $validated = $request->validated();

        /** @var list<int> $ids */
        $ids = array_values($validated['ids']);

        /** @var list<int> $lockIds */
        $lockIds = $ids;
        sort($lockIds, SORT_NUMERIC);

        // Use transaction with row locking for atomic validation + delete
        // This ensures no race condition between checking igsnMetadata and deleting
        DB::transaction(function () use ($ids, $lockIds): void {
            $lockedResources = collect();

            foreach (array_chunk($lockIds, IgsnBulkOperation::DATABASE_CHUNK_SIZE) as $chunk) {
                $lockedResources->push(...Resource::query()
                    ->whereIn('id', $chunk)
                    ->whereHas('igsnMetadata')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get());
            }

            // Verify all resources are valid IGSNs
            if ($lockedResources->count() !== count($ids)) {
                $lockedIds = $lockedResources->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
                $invalidId = array_values(array_diff($ids, $lockedIds))[0] ?? null;
                $invalidIndex = $invalidId === null ? false : array_search($invalidId, $ids, true);
                $messages = [
                    'ids' => ['Some selected resources are not valid IGSNs.'],
                ];
                if ($invalidIndex !== false) {
                    $messages["ids.{$invalidIndex}"] = ['The selected resource does not exist or is not an IGSN.'];
                }

                throw ValidationException::withMessages([
                    ...$messages,
                ]);
            }

            // Delete each resource individually to trigger Eloquent events/observers
            // This ensures ResourceObserver::deleted() fires and caches are invalidated
            foreach ($lockedResources->sortBy('id') as $resource) {
                $resource->delete();
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
