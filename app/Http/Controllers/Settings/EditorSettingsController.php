<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateSettingsRequest;
use App\Models\DateType;
use App\Models\Language;
use App\Models\ResourceType;
use App\Models\Right;
use App\Models\Setting;
use App\Models\ThesaurusSetting;
use App\Models\TitleType;
use App\Services\ThesaurusStatusService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class EditorSettingsController extends Controller
{
    public function __construct(
        private readonly ThesaurusStatusService $thesaurusStatusService
    ) {}

    public function index(): Response
    {
        // Map database fields to frontend expected field names
        $resourceTypes = ResourceType::orderBy('id')->get(['id', 'name', 'is_active', 'is_elmo_active'])->map(fn ($r) => [
            'id' => $r->id,
            'name' => $r->name,
            'active' => $r->is_active,
            'elmo_active' => $r->is_elmo_active,
        ]);

        $titleTypes = TitleType::orderBy('id')->get(['id', 'name', 'slug', 'is_active', 'is_elmo_active'])->map(fn ($t) => [
            'id' => $t->id,
            'name' => $t->name,
            'slug' => $t->slug,
            'active' => $t->is_active,
            'elmo_active' => $t->is_elmo_active,
        ]);

        $licenses = Right::with('excludedResourceTypes:id')
            ->orderBy('id')
            ->get(['id', 'identifier', 'name', 'is_active', 'is_elmo_active'])
            ->map(fn ($r) => [
                'id' => $r->id,
                'identifier' => $r->identifier,
                'name' => $r->name,
                'active' => $r->is_active,
                'elmo_active' => $r->is_elmo_active,
                'excluded_resource_type_ids' => $r->excludedResourceTypes->pluck('id')->toArray(),
            ]);

        $dateTypes = DateType::orderBy('id')->get(['id', 'name', 'slug', 'is_active'])->map(fn ($d) => [
            'id' => $d->id,
            'name' => $d->name,
            'slug' => $d->slug,
            'description' => null,
            'active' => $d->is_active,
            'elmo_active' => false, // DateType doesn't have is_elmo_active
        ]);

        // Get thesaurus settings with local status information
        $thesauri = ThesaurusSetting::orderBy('id')->get()->map(function (ThesaurusSetting $thesaurus) {
            $localStatus = $this->thesaurusStatusService->getLocalStatus($thesaurus);

            return [
                'type' => $thesaurus->type,
                'displayName' => $thesaurus->display_name,
                'isActive' => $thesaurus->is_active,
                'isElmoActive' => $thesaurus->is_elmo_active,
                'exists' => $localStatus['exists'],
                'conceptCount' => $localStatus['conceptCount'],
                'lastUpdated' => $localStatus['lastUpdated'],
            ];
        });

        return Inertia::render('settings/index', [
            'resourceTypes' => $resourceTypes,
            'titleTypes' => $titleTypes,
            'licenses' => $licenses,
            'languages' => Language::orderBy('id')->get(['id', 'code', 'name', 'active', 'elmo_active']),
            'dateTypes' => $dateTypes,
            'maxTitles' => (int) Setting::getValue('max_titles', Setting::DEFAULT_LIMIT),
            'maxLicenses' => (int) Setting::getValue('max_licenses', Setting::DEFAULT_LIMIT),
            'thesauri' => $thesauri,
        ]);
    }

    public function update(UpdateSettingsRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        // Wrap all updates in a single transaction for atomicity and performance
        // Using direct DB updates instead of Eloquent for efficiency
        DB::transaction(function () use ($validated): void {
            // Update resource types
            /** @var array<int, array{id: int, name: string, active: bool, elmo_active: bool}> $resourceTypes */
            $resourceTypes = $validated['resourceTypes'];
            foreach ($resourceTypes as $type) {
                DB::table('resource_types')
                    ->where('id', $type['id'])
                    ->update([
                        'name' => $type['name'],
                        'is_active' => $type['active'],
                        'is_elmo_active' => $type['elmo_active'],
                        'updated_at' => now(),
                    ]);
            }

            // Update title types
            /** @var array<int, array{id: int, name: string, slug: string, active: bool, elmo_active: bool}> $titleTypes */
            $titleTypes = $validated['titleTypes'];
            foreach ($titleTypes as $type) {
                DB::table('title_types')
                    ->where('id', $type['id'])
                    ->update([
                        'name' => $type['name'],
                        'slug' => $type['slug'],
                        'is_active' => $type['active'],
                        'is_elmo_active' => $type['elmo_active'],
                        'updated_at' => now(),
                    ]);
            }

            // Update licenses (rights) with resource type exclusions
            /** @var array<int, array{id: int, active: bool, elmo_active: bool, excluded_resource_type_ids: array<int>}> $licenses */
            $licenses = $validated['licenses'];
            foreach ($licenses as $license) {
                DB::table('rights')
                    ->where('id', $license['id'])
                    ->update([
                        'is_active' => $license['active'],
                        'is_elmo_active' => $license['elmo_active'],
                        'updated_at' => now(),
                    ]);

                // Sync excluded resource types using a direct query to ensure it works within transaction
                /** @var int[] $excludedIds */
                $excludedIds = $license['excluded_resource_type_ids'];
                
                // Delete existing exclusions
                DB::table('right_resource_type_exclusions')
                    ->where('right_id', $license['id'])
                    ->delete();
                
                // Insert new exclusions
                if (count($excludedIds) > 0) {
                    $insertData = array_map(fn (int $resourceTypeId) => [
                        'right_id' => $license['id'],
                        'resource_type_id' => $resourceTypeId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ], $excludedIds);
                    
                    DB::table('right_resource_type_exclusions')->insert($insertData);
                }
            }

            // Update languages
            /** @var array<int, array{id: int, active: bool, elmo_active: bool}> $languages */
            $languages = $validated['languages'];
            foreach ($languages as $language) {
                DB::table('languages')
                    ->where('id', $language['id'])
                    ->update([
                        'active' => $language['active'],
                        'elmo_active' => $language['elmo_active'],
                        'updated_at' => now(),
                    ]);
            }

            // Update date types
            /** @var array<int, array{id: int, active: bool}> $dateTypes */
            $dateTypes = $validated['dateTypes'];
            foreach ($dateTypes as $dateType) {
                DB::table('date_types')
                    ->where('id', $dateType['id'])
                    ->update([
                        'is_active' => $dateType['active'],
                        'updated_at' => now(),
                    ]);
            }

            // Update max settings - inside transaction to ensure atomicity
            Setting::updateOrCreate(['key' => 'max_titles'], ['value' => $validated['maxTitles']]);
            Setting::updateOrCreate(['key' => 'max_licenses'], ['value' => $validated['maxLicenses']]);

            // Update thesaurus settings if provided
            if (isset($validated['thesauri'])) {
                /** @var array<int, array{type: string, isActive: bool, isElmoActive: bool}> $thesauri */
                $thesauri = $validated['thesauri'];
                foreach ($thesauri as $thesaurus) {
                    DB::table('thesaurus_settings')
                        ->where('type', $thesaurus['type'])
                        ->update([
                            'is_active' => $thesaurus['isActive'],
                            'is_elmo_active' => $thesaurus['isElmoActive'],
                            'updated_at' => now(),
                        ]);
                }
            }
        });

        return back()->with('success', 'Settings updated');
    }
}
