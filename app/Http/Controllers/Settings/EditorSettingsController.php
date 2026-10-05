<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\ContributorCategory;
use App\Enums\EditorContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateSettingsRequest;
use App\Models\ContributorType;
use App\Models\Datacenter;
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
use App\Services\KeywordSuggestionService;
use App\Services\LandingPageDownloadUrlSuggestionService;
use App\Services\Pid4instStatusService;
use App\Services\RaidStatusService;
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
        private readonly RaidStatusService $raidStatusService,
        private readonly KeywordSuggestionService $keywordSuggestionService,
    ) {}

    public function index(): Response
    {
        // Ensure thesaurus and PID settings exist (auto-create if missing)
        $this->ensureThesaurusSettingsExist();
        $this->ensurePidSettingsExist();

        // Map database fields to frontend expected field names
        $resourceTypes = ResourceType::orderBy('id')->get(['id', 'name', 'is_active', 'is_elmo_active', 'is_elmo_msl_active'])->map(fn ($r) => [
            'id' => $r->id,
            'name' => $r->name,
            'active' => $r->is_active,
            'elmo_active' => $r->is_elmo_active,
            'elmo_msl_active' => $r->is_elmo_msl_active,
        ]);

        $titleTypes = TitleType::orderBy('id')->get(['id', 'name', 'slug', 'is_active', 'is_elmo_active', 'is_elmo_msl_active'])->map(fn ($t) => [
            'id' => $t->id,
            'name' => $t->name,
            'slug' => $t->slug,
            'active' => $t->is_active,
            'elmo_active' => $t->is_elmo_active,
            'elmo_msl_active' => $t->is_elmo_msl_active,
        ]);

        $licenses = Right::with('allExcludedResourceTypes:id')
            ->orderBy('id')
            ->get(['id', 'identifier', 'name', 'is_active', 'is_elmo_active', 'is_elmo_msl_active'])
            ->map(fn ($r) => [
                'id' => $r->id,
                'identifier' => $r->identifier,
                'name' => $r->name,
                'active' => $r->is_active,
                'elmo_active' => $r->is_elmo_active,
                'elmo_msl_active' => $r->is_elmo_msl_active,
                'excluded_resource_type_ids' => $r->allExcludedResourceTypes->where('pivot.editor', 'ernie')->pluck('id')->toArray(),
                'elmo_excluded_resource_type_ids' => $r->allExcludedResourceTypes->where('pivot.editor', 'elmo')->pluck('id')->toArray(),
                'elmo_msl_excluded_resource_type_ids' => $r->allExcludedResourceTypes->where('pivot.editor', 'elmo-msl')->pluck('id')->toArray(),
            ]);

        $dateTypes = DateType::orderBy('id')->get(['id', 'name', 'slug', 'is_active', 'is_elmo_active', 'is_elmo_msl_active'])->map(fn ($d) => [
            'id' => $d->id,
            'name' => $d->name,
            'slug' => $d->slug,
            'description' => null,
            'active' => $d->is_active,
            'elmo_active' => $d->is_elmo_active,
            'elmo_msl_active' => $d->is_elmo_msl_active,
        ]);

        $descriptionTypes = DescriptionType::orderBy('id')->get(['id', 'name', 'slug', 'is_active', 'is_elmo_active', 'is_elmo_msl_active'])->map(fn ($d) => [
            'id' => $d->id,
            'name' => $d->name,
            'slug' => $d->slug,
            'active' => $d->is_active,
            'elmo_active' => $d->is_elmo_active,
            'elmo_msl_active' => $d->is_elmo_msl_active,
        ]);

        // Get thesaurus settings with local status information
        $thesauri = ThesaurusSetting::orderBy('id')->get()->map(function (ThesaurusSetting $thesaurus) {
            $localStatus = $this->thesaurusStatusService->getLocalStatus($thesaurus);

            return [
                'type' => $thesaurus->type,
                'displayName' => $thesaurus->display_name,
                'isActive' => $thesaurus->is_active,
                'isElmoActive' => $thesaurus->is_elmo_active,
                'isElmoMslActive' => $thesaurus->is_elmo_msl_active,
                'version' => $thesaurus->type === ThesaurusSetting::TYPE_MSL_LABORATORIES
                    ? ($localStatus['version'] ?? $thesaurus->version)
                    : $thesaurus->version,
                'supportsVersioning' => $thesaurus->supportsVersioning(),
                'exists' => $localStatus['exists'],
                'conceptCount' => $localStatus['conceptCount'],
                'lastUpdated' => $localStatus['lastUpdated'],
                'sourceSha' => $localStatus['sourceSha'] ?? null,
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
                'isElmoMslActive' => $pidSetting->is_elmo_msl_active,
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
            'elmo_msl_active' => $ct->is_elmo_msl_active,
        ];

        $contributorPersonRoles = ContributorType::where('category', ContributorCategory::PERSON)
            ->orderBy('name')->get()->map($contributorTypeMapper);

        $contributorInstitutionRoles = ContributorType::where('category', ContributorCategory::INSTITUTION)
            ->orderBy('name')->get()->map($contributorTypeMapper);

        $contributorBothRoles = ContributorType::where('category', ContributorCategory::BOTH)
            ->orderBy('name')->get()->map($contributorTypeMapper);

        $relationTypes = RelationType::orderBy('id')->get(['id', 'name', 'slug', 'is_active', 'is_elmo_active', 'is_elmo_msl_active'])->map(fn ($r) => [
            'id' => $r->id,
            'name' => $r->name,
            'slug' => $r->slug,
            'active' => $r->is_active,
            'elmo_active' => $r->is_elmo_active,
            'elmo_msl_active' => $r->is_elmo_msl_active,
        ]);

        $identifierTypes = IdentifierType::with(['patterns' => fn ($q) => $q->orderByDesc('priority')])
            ->orderBy('id')
            ->get(['id', 'name', 'slug', 'is_active', 'is_elmo_active', 'is_elmo_msl_active'])
            ->map(fn ($it) => [
                'id' => $it->id,
                'name' => $it->name,
                'slug' => $it->slug,
                'active' => $it->is_active,
                'elmo_active' => $it->is_elmo_active,
                'elmo_msl_active' => $it->is_elmo_msl_active,
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
            'languages' => Language::orderBy('id')->get(['id', 'code', 'name', 'active', 'elmo_active', 'elmo_msl_active']),
            'dateTypes' => $dateTypes,
            'descriptionTypes' => $descriptionTypes,
            'thesauri' => $thesauri,
            'pidSettings' => $pidSettings,
            'downloadUrlSuggestions' => app(LandingPageDownloadUrlSuggestionService::class)->suggestions(limit: false)['domains'],
            'downloadUrlSuggestionOrder' => app(LandingPageDownloadUrlSuggestionService::class)->order(),
            'landingPageDomains' => LandingPageDomain::orderBy('domain')->get(['id', 'domain']),
            'contributorPersonRoles' => $contributorPersonRoles,
            'contributorInstitutionRoles' => $contributorInstitutionRoles,
            'contributorBothRoles' => $contributorBothRoles,
            'relationTypes' => $relationTypes,
            'identifierTypes' => $identifierTypes,
            'datacenters' => Datacenter::orderBy('name')
                ->withCount('resources')
                ->get()
                ->map(fn (Datacenter $dc) => [
                    'id' => $dc->id,
                    'name' => $dc->name,
                    'resources_count' => $dc->resources_count,
                ]),
        ]);
    }

    public function update(UpdateSettingsRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $hasThesaurusUpdates = isset($validated['thesauri']);

        // Wrap all updates in a single transaction for atomicity and performance
        // Using direct DB updates instead of Eloquent for efficiency
        DB::transaction(function () use ($validated): void {
            $now = now();
            if (array_key_exists('downloadUrlSuggestionOrder', $validated)) {
                Setting::updateOrCreate(
                    ['key' => LandingPageDownloadUrlSuggestionService::SETTING_KEY],
                    ['value' => json_encode(array_values($validated['downloadUrlSuggestionOrder']), JSON_THROW_ON_ERROR)],
                );
                LandingPageDownloadUrlSuggestionService::forgetAfterCommit();
            }

            // Update resource types
            /** @var array<int, array{id: int, name: string, active: bool, elmo_active: bool, elmo_msl_active?: bool}> $resourceTypes */
            $resourceTypes = $validated['resourceTypes'];
            foreach ($resourceTypes as $type) {
                DB::table('resource_types')
                    ->where('id', $type['id'])
                    ->update([
                        'name' => $type['name'],
                        'is_active' => $type['active'],
                        'is_elmo_active' => $type['elmo_active'],
                        ...(array_key_exists('elmo_msl_active', $type) ? ['is_elmo_msl_active' => $type['elmo_msl_active']] : []),
                        'updated_at' => $now,
                    ]);
            }

            // Update title types
            /** @var array<int, array{id: int, name: string, slug: string, active: bool, elmo_active: bool, elmo_msl_active?: bool}> $titleTypes */
            $titleTypes = $validated['titleTypes'];
            foreach ($titleTypes as $type) {
                DB::table('title_types')
                    ->where('id', $type['id'])
                    ->update([
                        'name' => $type['name'],
                        'slug' => $type['slug'],
                        'is_active' => $type['active'],
                        'is_elmo_active' => $type['elmo_active'],
                        ...(array_key_exists('elmo_msl_active', $type) ? ['is_elmo_msl_active' => $type['elmo_msl_active']] : []),
                        'updated_at' => $now,
                    ]);
            }

            // Update licenses (rights) with resource type exclusions
            /** @var array<int, array{id: int, active: bool, elmo_active: bool, elmo_msl_active?: bool, excluded_resource_type_ids: array<int>, elmo_excluded_resource_type_ids?: array<int>, elmo_msl_excluded_resource_type_ids?: array<int>}> $licenses */
            $licenses = $validated['licenses'];
            foreach ($licenses as $license) {
                DB::table('rights')
                    ->where('id', $license['id'])
                    ->update([
                        'is_active' => $license['active'],
                        'is_elmo_active' => $license['elmo_active'],
                        ...(array_key_exists('elmo_msl_active', $license) ? ['is_elmo_msl_active' => $license['elmo_msl_active']] : []),
                        'updated_at' => $now,
                    ]);

                foreach (EditorContext::cases() as $editor) {
                    $field = $editor->exclusionField();
                    if (! array_key_exists($field, $license)) {
                        continue;
                    }
                    DB::table('right_resource_type_exclusions')
                        ->where('right_id', $license['id'])->where('editor', $editor->value)->delete();
                    /** @var array<int> $excludedIds */
                    $excludedIds = $license[$field];
                    $rows = array_map(fn (int $resourceTypeId): array => [
                        'right_id' => $license['id'],
                        'resource_type_id' => $resourceTypeId,
                        'editor' => $editor->value,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ], array_unique($excludedIds));
                    if ($rows !== []) {
                        DB::table('right_resource_type_exclusions')->insert($rows);
                    }
                }
            }

            // Update languages
            /** @var array<int, array{id: int, active: bool, elmo_active: bool, elmo_msl_active?: bool}> $languages */
            $languages = $validated['languages'];
            foreach ($languages as $language) {
                DB::table('languages')
                    ->where('id', $language['id'])
                    ->update([
                        'active' => $language['active'],
                        'elmo_active' => $language['elmo_active'],
                        ...(array_key_exists('elmo_msl_active', $language) ? ['elmo_msl_active' => $language['elmo_msl_active']] : []),
                        'updated_at' => $now,
                    ]);
            }

            // Update date types
            /** @var array<int, array{id: int, active: bool, elmo_active?: bool, elmo_msl_active?: bool}> $dateTypes */
            $dateTypes = $validated['dateTypes'];
            foreach ($dateTypes as $dateType) {
                DB::table('date_types')
                    ->where('id', $dateType['id'])
                    ->update([
                        'is_active' => $dateType['active'],
                        ...(array_key_exists('elmo_active', $dateType) ? ['is_elmo_active' => $dateType['elmo_active']] : []),
                        ...(array_key_exists('elmo_msl_active', $dateType) ? ['is_elmo_msl_active' => $dateType['elmo_msl_active']] : []),
                        'updated_at' => $now,
                    ]);
            }

            // Update description types (Abstract is always forced active)
            /** @var array<int, array{id: int, active: bool, elmo_active: bool, elmo_msl_active?: bool}> $descriptionTypes */
            $descriptionTypes = $validated['descriptionTypes'];
            foreach ($descriptionTypes as $descType) {
                DB::table('description_types')
                    ->where('id', $descType['id'])
                    ->update([
                        'is_active' => $descType['active'],
                        'is_elmo_active' => $descType['elmo_active'],
                        ...(array_key_exists('elmo_msl_active', $descType) ? ['is_elmo_msl_active' => $descType['elmo_msl_active']] : []),
                        'updated_at' => $now,
                    ]);
            }

            // Ensure Abstract is always active, regardless of what was submitted
            DB::table('description_types')
                ->where('slug', 'Abstract')
                ->update([
                    'is_active' => true,
                    'is_elmo_active' => true,
                    'is_elmo_msl_active' => true,
                    'updated_at' => $now,
                ]);

            // Update thesaurus settings if provided
            if (isset($validated['thesauri'])) {
                /** @var array<int, array{type: string, isActive: bool, isElmoActive: bool, isElmoMslActive?: bool}> $thesauri */
                $thesauri = $validated['thesauri'];
                foreach ($thesauri as $thesaurus) {
                    DB::table('thesaurus_settings')
                        ->where('type', $thesaurus['type'])
                        ->update([
                            'is_active' => $thesaurus['isActive'],
                            'is_elmo_active' => $thesaurus['isElmoActive'],
                            ...(array_key_exists('isElmoMslActive', $thesaurus) ? ['is_elmo_msl_active' => $thesaurus['isElmoMslActive']] : []),
                            'updated_at' => $now,
                        ]);
                }
            }

            // Update PID settings if provided
            if (isset($validated['pidSettings'])) {
                /** @var array<int, array{type: string, isActive: bool, isElmoActive: bool, isElmoMslActive?: bool}> $pidSettings */
                $pidSettings = $validated['pidSettings'];
                foreach ($pidSettings as $pidSetting) {
                    DB::table('pid_settings')
                        ->where('type', $pidSetting['type'])
                        ->update([
                            'is_active' => $pidSetting['isActive'],
                            'is_elmo_active' => $pidSetting['isElmoActive'],
                            ...(array_key_exists('isElmoMslActive', $pidSetting) ? ['is_elmo_msl_active' => $pidSetting['isElmoMslActive']] : []),
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
                /** @var array<int, array{id: int, active: bool, elmo_active: bool, elmo_msl_active?: bool, category: string}> $roles */
                foreach ($roles as $role) {
                    DB::table('contributor_types')
                        ->where('id', $role['id'])
                        ->update([
                            'is_active' => $role['active'],
                            'is_elmo_active' => $role['elmo_active'],
                            ...(array_key_exists('elmo_msl_active', $role) ? ['is_elmo_msl_active' => $role['elmo_msl_active']] : []),
                            'category' => $role['category'],
                            'updated_at' => $now,
                        ]);
                }
            }

            // Update relation types
            if (isset($validated['relationTypes'])) {
                /** @var array<int, array{id: int, active: bool, elmo_active: bool, elmo_msl_active?: bool}> $relationTypes */
                $relationTypes = $validated['relationTypes'];
                foreach ($relationTypes as $type) {
                    DB::table('relation_types')
                        ->where('id', $type['id'])
                        ->update([
                            'is_active' => $type['active'],
                            'is_elmo_active' => $type['elmo_active'],
                            ...(array_key_exists('elmo_msl_active', $type) ? ['is_elmo_msl_active' => $type['elmo_msl_active']] : []),
                            'updated_at' => $now,
                        ]);
                }
            }

            // Update identifier types with patterns
            if (isset($validated['identifierTypes'])) {
                /** @var array<int, array{id: int, active: bool, elmo_active: bool, elmo_msl_active?: bool, patterns?: array<int, array{id: int, pattern: string, is_active: bool, priority: int}>}> $identifierTypes */
                $identifierTypes = $validated['identifierTypes'];
                foreach ($identifierTypes as $type) {
                    DB::table('identifier_types')
                        ->where('id', $type['id'])
                        ->update([
                            'is_active' => $type['active'],
                            'is_elmo_active' => $type['elmo_active'],
                            ...(array_key_exists('elmo_msl_active', $type) ? ['is_elmo_msl_active' => $type['elmo_msl_active']] : []),
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

        if ($hasThesaurusUpdates) {
            $this->keywordSuggestionService->invalidateCache();
        }

        return back()->with('success', 'Settings updated');
    }

    /**
     * Ensure all thesaurus settings exist in the database.
     * This is a fallback mechanism to handle cases where the seeder wasn't run
     * or entries were accidentally deleted.
     */
    private function ensureThesaurusSettingsExist(): void
    {
        foreach (ThesaurusSetting::definitions() as $type => $displayName) {
            ThesaurusSetting::firstOrCreate(
                ['type' => $type],
                [
                    'display_name' => $displayName,
                    'is_active' => ThesaurusSetting::isEnabledByDefault($type),
                    'is_elmo_active' => ThesaurusSetting::isEnabledByDefault($type),
                    'is_elmo_msl_active' => ThesaurusSetting::isEnabledByDefault($type),
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
                'is_elmo_msl_active' => true,
            ]
        );

        PidSetting::firstOrCreate(
            ['type' => PidSetting::TYPE_ROR],
            [
                'display_name' => 'ROR (Research Organization Registry)',
                'is_active' => true,
                'is_elmo_active' => true,
                'is_elmo_msl_active' => true,
            ]
        );

        PidSetting::firstOrCreate(
            ['type' => PidSetting::TYPE_RAID],
            [
                'display_name' => 'RAiD (Research Activity Identifier)',
                'is_active' => true,
                'is_elmo_active' => true,
                'is_elmo_msl_active' => true,
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
            PidSetting::TYPE_RAID => $this->raidStatusService->getLocalStatus($pidSetting),
            default => ['exists' => false, 'itemCount' => 0, 'lastUpdated' => null],
        };
    }
}
