<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateFontSizeRequest;
use Illuminate\Http\RedirectResponse;

class FontSizeController extends Controller
{
    /**
     * Update the user's font size preference.
     */
    public function update(UpdateFontSizeRequest $request): RedirectResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $user->update([
            'font_size_preference' => $request->validated()['font_size_preference'],
        ]);

        return back();
    }
}
