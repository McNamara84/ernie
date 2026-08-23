<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateCurationAccordionRequest;
use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class CurationAccordionController extends Controller
{
    private const REVISION_HEADER = 'X-Curation-Accordion-Revision';

    /**
     * Update the user's curation form accordion preference.
     */
    public function update(UpdateCurationAccordionRequest $request): Response
    {
        $validated = $request->validated();
        /** @var User $authenticatedUser */
        $authenticatedUser = $request->user();
        $userId = (int) $authenticatedUser->getAuthIdentifier();
        $revision = (int) $validated['revision'];

        $currentRevision = DB::transaction(function () use ($userId, $revision, $validated): int {
            /** @var User $user */
            $user = User::query()->lockForUpdate()->findOrFail($userId);

            if ($user->curation_accordion_revision !== null && $user->curation_accordion_revision >= $revision) {
                return $user->curation_accordion_revision;
            }

            $user->update([
                'curation_accordion_open_items' => array_values($validated['open_items']),
                'curation_accordion_revision' => $revision,
            ]);

            return $revision;
        });

        return response()->noContent()->header(self::REVISION_HEADER, (string) $currentRevision);
    }
}
