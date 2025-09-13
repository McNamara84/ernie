<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateSettingsRequest;
use App\Models\ResourceType;
use App\Models\Setting;
use Inertia\Inertia;

class EditorSettingsController extends Controller
{
    public function index()
    {
        return Inertia::render('settings/index', [
            'resourceTypes' => ResourceType::orderBy('id')->get(['id', 'name', 'active', 'elmo_active']),
            'maxTitles' => (int) Setting::getValue('max_titles', Setting::DEFAULT_LIMIT),
            'maxLicenses' => (int) Setting::getValue('max_licenses', Setting::DEFAULT_LIMIT),
        ]);
    }

    public function update(UpdateSettingsRequest $request)
    {
        $validated = $request->validated();

        foreach ($validated['resourceTypes'] as $type) {
            ResourceType::where('id', $type['id'])->update([
                'name' => $type['name'],
                'active' => $type['active'],
                'elmo_active' => $type['elmo_active'],
            ]);
        }

        Setting::updateOrCreate(['key' => 'max_titles'], ['value' => $validated['maxTitles']]);
        Setting::updateOrCreate(['key' => 'max_licenses'], ['value' => $validated['maxLicenses']]);

        return back()->with('success', 'Settings updated');
    }
}
