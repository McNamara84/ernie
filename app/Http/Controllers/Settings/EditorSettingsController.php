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
            'resourceTypes' => ResourceType::orderBy('id')->get(['id', 'name', 'is_active', 'is_elmo_active']),
            'titleTypes' => TitleType::orderBy('id')->get(['id', 'name', 'slug', 'is_active', 'is_elmo_active']),
            'rights' => Right::orderBy('id')->get(['id', 'identifier', 'name', 'is_active', 'is_elmo_active']),
            'languages' => Language::orderBy('id')->get(['id', 'code', 'name', 'active', 'elmo_active']),
            'dateTypes' => DateType::orderBy('id')->get(['id', 'name', 'slug', 'is_active']),
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
                'is_active' => $type['is_active'],
                'is_elmo_active' => $type['is_elmo_active'],
            ]);
        }

        foreach ($validated['titleTypes'] as $type) {
            TitleType::where('id', $type['id'])->update([
                'name' => $type['name'],
                'slug' => $type['slug'],
                'is_active' => $type['is_active'],
                'is_elmo_active' => $type['is_elmo_active'],
            ]);
        }

        foreach ($validated['rights'] as $right) {
            Right::where('id', $right['id'])->update([
                'is_active' => $right['is_active'],
                'is_elmo_active' => $right['is_elmo_active'],
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
                'is_active' => $dateType['is_active'],
            ]);
        }

        Setting::updateOrCreate(['key' => 'max_titles'], ['value' => $validated['maxTitles']]);
        Setting::updateOrCreate(['key' => 'max_licenses'], ['value' => $validated['maxLicenses']]);

        return back()->with('success', 'Settings updated');
    }
}
