<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateSettingsRequest;
use App\Models\DateType;
use App\Models\Language;
use App\Models\Right;
use App\Models\ResourceType;
use App\Models\Setting;
use App\Models\TitleType;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class EditorSettingsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('settings/index', [
            'resourceTypes' => ResourceType::orderBy('id')->get(['id', 'name', 'active', 'elmo_active']),
            'titleTypes' => TitleType::orderBy('id')->get(['id', 'name', 'slug', 'active', 'elmo_active']),
            'licenses' => Right::orderBy('id')->get(['id', 'identifier', 'name', 'active', 'elmo_active']),
            'languages' => Language::orderBy('id')->get(['id', 'code', 'name', 'active', 'elmo_active']),
            'dateTypes' => DateType::orderBy('id')->get(['id', 'name', 'slug', 'description', 'active', 'elmo_active']),
            'maxTitles' => (int) Setting::getValue('max_titles', Setting::DEFAULT_LIMIT),
            'maxLicenses' => (int) Setting::getValue('max_licenses', Setting::DEFAULT_LIMIT),
        ]);
    }

    public function update(UpdateSettingsRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        foreach ($validated['resourceTypes'] as $type) {
            ResourceType::where('id', $type['id'])->update([
                'name' => $type['name'],
                'active' => $type['active'],
                'elmo_active' => $type['elmo_active'],
            ]);
        }

        foreach ($validated['titleTypes'] as $type) {
            TitleType::where('id', $type['id'])->update([
                'name' => $type['name'],
                'slug' => $type['slug'],
                'active' => $type['active'],
                'elmo_active' => $type['elmo_active'],
            ]);
        }

        foreach ($validated['licenses'] as $license) {
            Right::where('id', $license['id'])->update([
                'active' => $license['active'],
                'elmo_active' => $license['elmo_active'],
            ]);
        }

        foreach ($validated['languages'] as $language) {
            Language::where('id', $language['id'])->update([
                'active' => $language['active'],
                'elmo_active' => $language['elmo_active'],
            ]);
        }

        foreach ($validated['dateTypes'] as $dateType) {
            DateType::where('id', $dateType['id'])->update([
                'active' => $dateType['active'],
                'elmo_active' => $dateType['elmo_active'],
            ]);
        }

        Setting::updateOrCreate(['key' => 'max_titles'], ['value' => $validated['maxTitles']]);
        Setting::updateOrCreate(['key' => 'max_licenses'], ['value' => $validated['maxLicenses']]);

        return back()->with('success', 'Settings updated');
    }
}
