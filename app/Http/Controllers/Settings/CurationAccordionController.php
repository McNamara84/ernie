<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateCurationAccordionRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class CurationAccordionController extends Controller
{
    /**
     * Update the user's curation form accordion preference.
     */
    public function update(UpdateCurationAccordionRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validated();

        $user->update([
            'curation_accordion_open_items' => array_values($validated['open_items']),
        ]);

        return back();
    }
}
