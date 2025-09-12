<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\ResourceType;
use App\Models\Setting;
use Illuminate\Http\Request;
use Inertia\Inertia;

class EditorSettingsController extends Controller
{
    public function index()
    {
        return Inertia::render('settings/index', [
            'resourceTypes' => ResourceType::orderBy('id')->get(['id', 'name', 'active']),
            'maxTitles' => (int) Setting::getValue('max_titles', Setting::DEFAULT_LIMIT),
            'maxLicenses' => (int) Setting::getValue('max_licenses', Setting::DEFAULT_LIMIT),
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'resourceTypes' => ['required', 'array'],
            'resourceTypes.*.id' => ['required', 'integer', 'exists:resource_types,id'],
            'resourceTypes.*.name' => ['required', 'string'],
            'resourceTypes.*.active' => ['required', 'boolean'],
            'maxTitles' => ['required', 'integer', 'min:1'],
            'maxLicenses' => ['required', 'integer', 'min:1'],
        ]);

        foreach ($validated['resourceTypes'] as $type) {
            ResourceType::where('id', $type['id'])->update([
                'name' => $type['name'],
                'active' => $type['active'],
            ]);
        }

        Setting::updateOrCreate(['key' => 'max_titles'], ['value' => $validated['maxTitles']]);
        Setting::updateOrCreate(['key' => 'max_licenses'], ['value' => $validated['maxLicenses']]);

        return back()->with('success', 'Settings updated');
    }
}
