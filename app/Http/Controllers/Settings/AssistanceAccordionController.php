<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateAssistanceAccordionRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class AssistanceAccordionController extends Controller
{
    /**
     * Update the user's Assistance accordion preference.
     */
    public function update(UpdateAssistanceAccordionRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validated();

        $user->update([
            'assistance_collapsed_assistant_ids' => array_values($validated['collapsed_assistant_ids']),
        ]);

        return back();
    }
}
