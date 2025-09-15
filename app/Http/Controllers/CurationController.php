<?php

namespace App\Http\Controllers;

use App\Models\Language;
use App\Models\License;
use App\Models\Resource;
use App\Models\TitleType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CurationController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'doi' => ['nullable', 'string'],
            'year' => ['required', 'integer'],
            'resourceType' => ['required', 'exists:resource_types,id'],
            'version' => ['nullable', 'string'],
            'language' => ['nullable', 'exists:languages,code'],
            'titles' => ['required', 'array', 'min:1'],
            'titles.*.title' => ['required', 'string'],
            'titles.*.titleType' => ['required', 'exists:title_types,slug'],
            'licenses' => ['required', 'array', 'min:1'],
            'licenses.*' => ['required', 'exists:licenses,identifier'],
        ]);

        $languageId = isset($validated['language'])
            ? Language::where('code', $validated['language'])->first()?->id
            : null;

        $resource = Resource::create([
            'doi' => $validated['doi'] ?? null,
            'year' => $validated['year'],
            'resource_type_id' => $validated['resourceType'],
            'version' => $validated['version'] ?? null,
            'language_id' => $languageId,
            'last_editor_id' => Auth::id(),
        ]);

        $titleTypeIds = TitleType::whereIn(
            'slug',
            collect($validated['titles'])->pluck('titleType')
        )->pluck('id', 'slug');

        foreach ($validated['titles'] as $titleData) {
            $resource->titles()->create([
                'title' => $titleData['title'],
                'title_type_id' => $titleTypeIds[$titleData['titleType']],
            ]);
        }

        $licenseIds = License::whereIn('identifier', $validated['licenses'])->pluck('id');
        $resource->licenses()->sync($licenseIds);

        return redirect()->route('curation');
    }
}
