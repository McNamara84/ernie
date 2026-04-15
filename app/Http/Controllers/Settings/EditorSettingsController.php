<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\ContributorCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateSettingsRequest;
use App\Models\ContributorType;
use App\Models\DateType;
use App\Models\DescriptionType;
use App\Models\IdentifierType;
use App\Models\LandingPageDomain;
use App\Models\Language;
use App\Models\PidSetting;
use App\Models\RelationType;
use App\Models\ResourceType;
use App\Models\Right;
use App\Models\Setting;
use App\Models\ThesaurusSetting;
use App\Models\TitleType;
use App\Services\Pid4instStatusService;
use App\Services\RorStatusService;
use App\Services\ThesaurusStatusService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class EditorSettingsController extends Controller
{
    public function __construct(
        private readonly ThesaurusStatusService $thesaurusStatusService,
        private readonly Pid4instStatusService $pid4instStatusService,
        private readonly RorStatusService $rorStatusService,
    ) {}

    public function index(): Response
    {
        // Ensure thesaurus and PID settings exist (auto-create if missing)
        $this->ensureThesaurusSettingsExist();
        $this->ensurePidSettingsExist();

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

        $descriptionTypes = DescriptionType::orderBy('id')->get(['id', 'name', 'slug', 'is_active', 'is_elmo_active'])->map(fn ($d) => [
            'id' => $d->id,
            'name' => $d->name,
            'slug' => $d->slug,
            'active' => $d->is_active,
            'elmo_active' => $d->is_elmo_active,
        ]);

        // Get thesaurus settings with local status information
        $thesauri = ThesaurusSetting::orderBy('id')->get()->map(function (ThesaurusSetting $thesaurus) {
            $localStatus = $this->thesaurusStatusService->getLocalStatus($thesaurus);

            return [
                'type' => $thesaurus->type,
                'displayName' => $thesaurus->display_name,
                'isActive' => $thesaurus->is_active,
                'isElmoActive' => $thesaurus->is_elmo_active,
                'version' => $thesaurus->version,
                'supportsVersioning' => $thesaurus->supportsVersioning(),
                'exists' => $localStatus['exists'],
                'conceptCount' => $localStatus['conceptCount'],
                'lastUpdated' => $localStatus['lastUpdated'],
            ];
        });

        // Get PID settings with local status information
        $pidSettings = PidSetting::orderBy('id')->get()->map(function (PidSetting $pidSetting) {
            $localStatus = $this->getPidLocalStatus($pidSetting);

            return [
                'type' => $pidSetting->type,
                'displayName' => $pidSetting->display_name,
                'isActive' => $pidSetting->is_active,
                'isElmoActive' => $pidSetting->is_elmo_active,
                'exists' => $localStatus['exists'],
                'itemCount' => $localStatus['itemCount'],
                'lastUpdated' => $localStatus['lastUpdated'],
            ];
        });

        // Load contributor types grouped by category
        $contributorTypeMapper = fn (ContributorType $ct) => [
            'id' => $ct->id,
            'name' => $ct->name,
            'slug' => $ct->slug,
            'category' => $ct->category->value,
            'active' => $ct->is_active,
            'elmo_active' => $ct->is_elmo_active,
        ];

        $contributorPersonRoles = ContributorType::where('category', ContributorCategory::PERSON)
            ->orderBy('name')->get()->map($contributorTypeMapper);

        $contributorInstitutionRoles = ContributorType::where('category', ContributorCategory::INSTITUTION)
            ->orderBy('name')->get()->map($contributorTypeMapper);

        $contributorBothRoles = ContributorType::where('category', ContributorCategory::BOTH)
            ->orderBy('name')->get()->map($contributorTypeMapper);

        $relationTypes = RelationType::orderBy('id')->get(['id', 'name', 'slug', 'is_active', 'is_elmo_active'])->map(fn ($r) => [
            'id' => $r->id,
            'name' => $r->name,
            'slug' => $r->slug,
            'active' => $r->is_active,
            'elmo_active' => $r->is_elmo_active,
        ]);

        $identifierTypes = IdentifierType::with(['patterns' => fn ($q) => $q->orderByDesc('priority')])
            ->orderBy('id')
            ->get(['id', 'name', 'slug', 'is_active', 'is_elmo_active'])
            ->map(fn ($it) => [
                'id' => $it->id,
                'name' => $it->name,
                'slug' => $it->slug,
                'active' => $it->is_active,
                'elmo_active' => $it->is_elmo_active,
                'patterns' => $it->patterns->map(fn ($p) => [
                    'id' => $p->id,
                    'type' => $p->type,
                    'pattern' => $p->pattern,
                    'is_active' => $p->is_active,
                    'priority' => $p->priority,
                ])->toArray(),
            ]);

        return Inertia::render('settings/index', [
            'resourceTypes' => $resourceTypes,
            'titleTypes' => $titleTypes,
            'licenses' => $licenses,
            'languages' => Language::orderBy('id')->get(['id', 'code', 'name', 'active', 'elmo_active']),
            'dateTypes' => $dateTypes,
            'descriptionTypes' => $descriptionTypes,
            'maxTitles' => (int) Setting::getValue('max_titles', Setting::DEFAULT_LIMIT),
            'maxLicenses' => (int) Setting::getValue('max_licenses', Setting::DEFAULT_LIMIT),
            'thesauri' => $thesauri,
            'pidSettings' => $pidSettings,
            'landingPageDomains' => LandingPageDomain::orderBy('domain')->get(['id', 'domain']),
            'contributorPersonRoles' => $contributorPersonRoles,
            'contributorInstitutionRoles' => $contributorInstitutionRoles,
            'contributorBothRoles' => $contributorBothRoles,
            'relationTypes' => $relationTypes,
            'identifierTypes' => $identifierTypes,
            'datacenters' => \App\Models\Datacenter::orderBy('name')
                ->withCount('resources')
                ->get()
                ->map(fn (\App\Models\Datacenter $dc) => [
                    'id' => $dc->id,
                    'name' => $dc->name,
                    'resources_count' => $dc->resources_count,
                ]),
        ]);
    }

    public function update(UpdateSettingsRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        // Wrap all updates in a single transaction for atomicity and performance
        // Using direct DB updates instead of Eloquent for efficiency
        DB::transaction(function () use ($validated): void {
            $now = now();

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
                        'updated_at' => $now,
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
                        'updated_at' => $now,
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
                        'updated_at' => $now,
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
                        'created_at' => $now,
                        'updated_at' => $now,
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
                        'updated_at' => $now,
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
                        'updated_at' => $now,
                    ]);
            }

            // Update description types (Abstract is always forced active)
            /** @var array<int, array{id: int, active: bool, elmo_active: bool}> $descriptionTypes */
            $descriptionTypes = $validated['descriptionTypes'];
            foreach ($descriptionTypes as $descType) {
                DB::table('description_types')
                    ->where('id', $descType['id'])
                    ->update([
                        'is_active' => $descType['active'],
                        'is_elmo_active' => $descType['elmo_active'],
                        'updated_at' => $now,
                    ]);
            }

            // Ensure Abstract is always active, regardless of what was submitted
            DB::table('description_types')
                ->where('slug', 'Abstract')
                ->update([
                    'is_active' => true,
                    'is_elmo_active' => true,
                    'updated_at' => $now,
                ]);

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
                            'updated_at' => $now,
                        ]);
                }
            }

            // Update PID settings if provided
            if (isset($validated['pidSettings'])) {
                /** @var array<int, array{type: string, isActive: bool, isElmoActive: bool}> $pidSettings */
                $pidSettings = $validated['pidSettings'];
                foreach ($pidSettings as $pidSetting) {
                    DB::table('pid_settings')
                        ->where('type', $pidSetting['type'])
                        ->update([
                            'is_active' => $pidSetting['isActive'],
                            'is_elmo_active' => $pidSetting['isElmoActive'],
                            'updated_at' => $now,
                        ]);
                }
            }

            // Update contributor roles (all three categories in one loop)
            $contributorRoleArrays = [
                $validated['contributorPersonRoles'] ?? [],
                $validated['contributorInstitutionRoles'] ?? [],
                $validated['contributorBothRoles'] ?? [],
            ];

            foreach ($contributorRoleArrays as $roles) {
                /** @var array<int, array{id: int, active: bool, elmo_active: bool, category: string}> $roles */
                foreach ($roles as $role) {
                    DB::table('contributor_types')
                        ->where('id', $role['id'])
                        ->update([
                            'is_active' => $role['active'],
                            'is_elmo_active' => $role['elmo_active'],
                            'category' => $role['category'],
                            'updated_at' => $now,
                        ]);
                }
            }

            // Update relation types
            if (isset($validated['relationTypes'])) {
                /** @var array<int, array{id: int, active: bool, elmo_active: bool}> $relationTypes */
                $relationTypes = $validated['relationTypes'];
                foreach ($relationTypes as $type) {
                    DB::table('relation_types')
                        ->where('id', $type['id'])
                        ->update([
                            'is_active' => $type['active'],
                            'is_elmo_active' => $type['elmo_active'],
                            'updated_at' => $now,
                        ]);
                }
            }

            // Update identifier types with patterns
            if (isset($validated['identifierTypes'])) {
                /** @var array<int, array{id: int, active: bool, elmo_active: bool, patterns?: array<int, array{id: int, pattern: string, is_active: bool, priority: int}>}> $identifierTypes */
                $identifierTypes = $validated['identifierTypes'];
                foreach ($identifierTypes as $type) {
                    DB::table('identifier_types')
                        ->where('id', $type['id'])
                        ->update([
                            'is_active' => $type['active'],
                            'is_elmo_active' => $type['elmo_active'],
                            'updated_at' => $now,
                        ]);

                    if (isset($type['patterns'])) {
                        foreach ($type['patterns'] as $pattern) {
                            DB::table('identifier_type_patterns')
                                ->where('id', $pattern['id'])
                                ->where('identifier_type_id', $type['id'])
                                ->update([
                                    'pattern' => $pattern['pattern'],
                                    'is_active' => $pattern['is_active'],
                                    'priority' => $pattern['priority'],
                                    'updated_at' => $now,
                                ]);
                        }
                    }
                }
            }
        });

        return back()->with('success', 'Settings updated');
    }

    /**
     * Ensure all thesaurus settings exist in the database.
     * This is a fallback mechanism to handle cases where the seeder wasn't run
     * or entries were accidentally deleted.
     */
    private function ensureThesaurusSettingsExist(): void
    {
        $thesauriConfig = [
            [
                'type' => ThesaurusSetting::TYPE_SCIENCE_KEYWORDS,
                'display_name' => 'GCMD Science Keywords',
            ],
            [
                'type' => ThesaurusSetting::TYPE_PLATFORMS,
                'display_name' => 'GCMD Platforms',
            ],
            [
                'type' => ThesaurusSetting::TYPE_INSTRUMENTS,
                'display_name' => 'GCMD Instruments',
            ],
            [
                'type' => ThesaurusSetting::TYPE_CHRONOSTRAT,
                'display_name' => 'ICS Chronostratigraphy',
            ],
        ];

        foreach ($thesauriConfig as $config) {
            ThesaurusSetting::firstOrCreate(
                ['type' => $config['type']],
                [
                    'display_name' => $config['display_name'],
                    'is_active' => true,
                    'is_elmo_active' => true,
                ]
            );
        }
    }

    /**
     * Ensure PID settings exist in the database.
     * This is a fallback mechanism to handle cases where the seeder wasn't run
     * or entries were accidentally deleted.
     */
    private function ensurePidSettingsExist(): void
    {
        PidSetting::firstOrCreate(
            ['type' => PidSetting::TYPE_PID4INST],
            [
                'display_name' => 'PID4INST (b2inst)',
                'is_active' => true,
                'is_elmo_active' => true,
            ]
        );

        PidSetting::firstOrCreate(
            ['type' => PidSetting::TYPE_ROR],
            [
                'display_name' => 'ROR (Research Organization Registry)',
                'is_active' => true,
                'is_elmo_active' => true,
            ]
        );
    }

    /**
     * Get local status for a PID setting using the appropriate status service.
     *
     * @return array{exists: bool, itemCount: int, lastUpdated: string|null}
     */
    private function getPidLocalStatus(PidSetting $pidSetting): array
    {
        return match ($pidSetting->type) {
            PidSetting::TYPE_PID4INST => $this->pid4instStatusService->getLocalStatus($pidSetting),
            PidSetting::TYPE_ROR => $this->rorStatusService->getLocalStatus($pidSetting),
            default => ['exists' => false, 'itemCount' => 0, 'lastUpdated' => null],
        };
    }
}
