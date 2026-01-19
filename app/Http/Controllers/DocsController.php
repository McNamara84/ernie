<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CacheKey;
use App\Models\Language;
use App\Models\ResourceType;
use App\Models\Right;
use App\Models\Setting;
use App\Models\ThesaurusSetting;
use App\Models\TitleType;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for the user documentation page.
 *
 * Provides role-aware and settings-aware documentation content
 * that adapts to the user's permissions and active editor settings.
 */
class DocsController extends Controller
{
    /**
     * Display the documentation page.
     *
     * Passes the user's role and active editor settings to the frontend
     * so documentation can dynamically show/hide sections based on
     * what features are actually available.
     */
    public function show(): Response
    {
        /** @var User $user */
        $user = auth()->user();

        return Inertia::render('docs', [
            'userRole' => $user->role->value,
            'editorSettings' => $this->getEditorSettingsForDocs(),
        ]);
    }

    /**
     * Get editor settings relevant for documentation display.
     *
     * Returns a simplified view of the editor settings that the
     * documentation page needs to conditionally render content.
     * Results are cached for 1 hour to reduce database load since
     * these settings rarely change.
     *
     * @return array{
     *     thesauri: array{
     *         scienceKeywords: bool,
     *         platforms: bool,
     *         instruments: bool
     *     },
     *     features: array{
     *         hasActiveGcmd: bool,
     *         hasActiveMsl: bool,
     *         hasActiveLicenses: bool,
     *         hasActiveResourceTypes: bool,
     *         hasActiveTitleTypes: bool,
     *         hasActiveLanguages: bool
     *     },
     *     limits: array{
     *         maxTitles: int,
     *         maxLicenses: int
     *     }
     * }
     */
    private function getEditorSettingsForDocs(): array
    {
        $cacheKey = CacheKey::DOCS_EDITOR_SETTINGS;

        /** @var array{thesauri: array{scienceKeywords: bool, platforms: bool, instruments: bool}, features: array{hasActiveGcmd: bool, hasActiveMsl: bool, hasActiveLicenses: bool, hasActiveResourceTypes: bool, hasActiveTitleTypes: bool, hasActiveLanguages: bool}, limits: array{maxTitles: int, maxLicenses: int}} $result */
        $result = Cache::remember(
            $cacheKey->key(),
            $cacheKey->ttl(),
            function (): array {
                // Get thesaurus settings
                $thesauri = ThesaurusSetting::all()->keyBy('type');

                $scienceKeywordsSetting = $thesauri->get(ThesaurusSetting::TYPE_SCIENCE_KEYWORDS);
                $platformsSetting = $thesauri->get(ThesaurusSetting::TYPE_PLATFORMS);
                $instrumentsSetting = $thesauri->get(ThesaurusSetting::TYPE_INSTRUMENTS);

                $scienceKeywordsActive = $scienceKeywordsSetting !== null ? $scienceKeywordsSetting->is_active : false;
                $platformsActive = $platformsSetting !== null ? $platformsSetting->is_active : false;
                $instrumentsActive = $instrumentsSetting !== null ? $instrumentsSetting->is_active : false;

                // Check if MSL vocabulary file exists (indicates MSL is available)
                $hasMslVocabulary = Storage::exists('msl-vocabulary.json');

                return [
                    'thesauri' => [
                        'scienceKeywords' => $scienceKeywordsActive,
                        'platforms' => $platformsActive,
                        'instruments' => $instrumentsActive,
                    ],
                    'features' => [
                        'hasActiveGcmd' => $scienceKeywordsActive || $platformsActive || $instrumentsActive,
                        'hasActiveMsl' => $hasMslVocabulary,
                        'hasActiveLicenses' => Right::where('is_active', true)->exists(),
                        'hasActiveResourceTypes' => ResourceType::where('is_active', true)->exists(),
                        'hasActiveTitleTypes' => TitleType::where('is_active', true)->exists(),
                        'hasActiveLanguages' => Language::where('active', true)->exists(),
                    ],
                    'limits' => [
                        'maxTitles' => (int) Setting::getValue('max_titles', Setting::DEFAULT_LIMIT),
                        'maxLicenses' => (int) Setting::getValue('max_licenses', Setting::DEFAULT_LIMIT),
                    ],
                ];
            }
        );

        return $result;
    }
}
