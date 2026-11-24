<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CacheKey;
use App\Enums\EditorContext;
use App\Exceptions\VocabularyCorruptedException;
use App\Exceptions\VocabularyNotFoundException;
use App\Exceptions\VocabularyReadException;
use App\Models\PidSetting;
use App\Models\ThesaurusSetting;
use App\Services\CgiSimpleLithologyVocabularyService;
use App\Services\MslLaboratoryVocabularyService;
use App\Services\Pid4instVocabularyService;
use App\Services\VocabularyCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemException;

class VocabularyController extends Controller
{
    private const THESAURI = [
        'gcmd-science-keywords' => ThesaurusSetting::TYPE_SCIENCE_KEYWORDS,
        'gcmd-platforms' => ThesaurusSetting::TYPE_PLATFORMS,
        'gcmd-instruments' => ThesaurusSetting::TYPE_INSTRUMENTS,
        'msl' => ThesaurusSetting::TYPE_MSL_KEYWORDS,
        'msl-laboratories' => ThesaurusSetting::TYPE_MSL_LABORATORIES,
        'chronostrat-timescale' => ThesaurusSetting::TYPE_CHRONOSTRAT,
        'gemet' => ThesaurusSetting::TYPE_GEMET,
        'analytical-methods' => ThesaurusSetting::TYPE_ANALYTICAL_METHODS,
        'euroscivoc' => ThesaurusSetting::TYPE_EUROSCIVOC,
        'cgi-simple-lithology' => ThesaurusSetting::TYPE_SIMPLE_LITHOLOGY,
    ];

    private const PID_REGISTRIES = [
        'pid4inst-instruments' => PidSetting::TYPE_PID4INST,
        'raid-projects' => PidSetting::TYPE_RAID,
        'ror-affiliations' => PidSetting::TYPE_ROR,
    ];

    public function __construct(
        private readonly VocabularyCacheService $cacheService,
        private readonly MslLaboratoryVocabularyService $mslLaboratoryVocabularyService,
        private readonly CgiSimpleLithologyVocabularyService $simpleLithologyVocabularyService,
        private readonly Pid4instVocabularyService $instrumentVocabulary,
    ) {}

    public function visibility(): JsonResponse
    {
        $column = $this->editorContext()->activationColumn();
        $thesauri = ThesaurusSetting::query()->pluck($column, 'type');
        $registries = PidSetting::query()->pluck($column, 'type');

        $visibility = [];
        foreach (self::THESAURI as $slug => $type) {
            $visibility[$slug] = (bool) $thesauri->get($type, ThesaurusSetting::isEnabledByDefault($type));
        }
        foreach (self::PID_REGISTRIES as $slug => $type) {
            $visibility[$slug] = (bool) $registries->get($type, true);
        }

        return response()->json($visibility);
    }

    public function data(string $vocab): JsonResponse
    {
        try {
            if (isset(self::THESAURI[$vocab])) {
                return $this->readThesaurus(self::THESAURI[$vocab]);
            }

            return match (self::PID_REGISTRIES[$vocab] ?? null) {
                PidSetting::TYPE_PID4INST => $this->readPid4instInstruments(),
                PidSetting::TYPE_RAID => $this->readRaidProjects(),
                PidSetting::TYPE_ROR => $this->readRorAffiliations(normalizeSnapshot: true),
                default => response()->json(['error' => 'Unknown vocabulary.'], 404),
            };
        } catch (FilesystemException $exception) {
            report($exception);

            return response()->json(['error' => 'Failed to read vocabulary file.'], 500);
        }
    }

    private function legacyThesaurus(string $type): JsonResponse
    {
        if (! $this->isThesaurusActive($type)) {
            return response()->json(['error' => 'Thesaurus is disabled'], 404);
        }

        return $this->readThesaurus($type);
    }

    private function readThesaurus(string $type): JsonResponse
    {
        $setting = new ThesaurusSetting(['type' => $type]);

        return match ($type) {
            ThesaurusSetting::TYPE_MSL_LABORATORIES => $this->readMslLaboratories(),
            ThesaurusSetting::TYPE_SIMPLE_LITHOLOGY => $this->readSimpleLithology(),
            default => $this->getCachedVocabulary(
                $setting->getCacheKey(),
                $setting->getFilePath(),
                'php artisan '.$setting->getArtisanCommand(),
            ),
        };
    }

    /**
     * Return GCMD Science Keywords vocabulary.
     */
    public function gcmdScienceKeywords(): JsonResponse
    {
        return $this->legacyThesaurus(ThesaurusSetting::TYPE_SCIENCE_KEYWORDS);
    }

    /**
     * Return GCMD Platforms vocabulary.
     */
    public function gcmdPlatforms(): JsonResponse
    {
        return $this->legacyThesaurus(ThesaurusSetting::TYPE_PLATFORMS);
    }

    /**
     * Return GCMD Instruments vocabulary.
     */
    public function gcmdInstruments(): JsonResponse
    {
        return $this->legacyThesaurus(ThesaurusSetting::TYPE_INSTRUMENTS);
    }

    /**
     * Return MSL Vocabulary (EPOS Multi-Scale Laboratories).
     */
    public function mslVocabulary(): JsonResponse
    {
        return $this->legacyThesaurus(ThesaurusSetting::TYPE_MSL_KEYWORDS);
    }

    /**
     * Return the locally stored MSL laboratories vocabulary.
     */
    public function mslLaboratories(): JsonResponse
    {
        return $this->legacyThesaurus(ThesaurusSetting::TYPE_MSL_LABORATORIES);
    }

    private function readMslLaboratories(): JsonResponse
    {
        try {
            $payload = $this->mslLaboratoryVocabularyService->getPublicPayload();

            if ($payload === null) {
                return response()->json([
                    'error' => 'Vocabulary file not found. Please run: php artisan get-msl-laboratories',
                ], 404);
            }

            return response()->json($payload);
        } catch (\RuntimeException $exception) {
            return response()->json([
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    /**
     * Generic method to retrieve a cached vocabulary file.
     */
    private function getCachedVocabulary(
        CacheKey $cacheKey,
        string $filename,
        string $command
    ): JsonResponse {
        try {
            $data = $this->cacheService->cacheVocabulary(
                $cacheKey,
                function () use ($filename, $command): array {
                    if (! Storage::exists($filename)) {
                        throw new VocabularyNotFoundException($command);
                    }

                    $content = Storage::get($filename);

                    if ($content === null) {
                        throw new VocabularyReadException;
                    }

                    $decoded = json_decode($content, true);

                    if (json_last_error() !== JSON_ERROR_NONE) {
                        throw new VocabularyCorruptedException(json_last_error_msg());
                    }

                    // Ensure decoded data is not null (e.g., if JSON contains literal "null")
                    if ($decoded === null) {
                        throw new VocabularyCorruptedException('Vocabulary file contains null data');
                    }

                    if (! is_array($decoded)) {
                        throw new VocabularyCorruptedException('Expected a vocabulary array or object');
                    }

                    return $decoded;
                }
            );

            return response()->json($data);
        } catch (VocabularyNotFoundException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 404);
        } catch (VocabularyReadException|VocabularyCorruptedException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Return thesauri availability status.
     *
     * Returns availability for the editor specified by the route.
     */
    public function thesauriAvailability(): JsonResponse
    {
        $column = $this->editorContext()->activationColumn();

        $thesauri = ThesaurusSetting::all()->mapWithKeys(function (ThesaurusSetting $setting) use ($column): array {
            $available = (bool) $setting->getAttribute($column);

            if ($available && $setting->type === ThesaurusSetting::TYPE_MSL_LABORATORIES) {
                try {
                    $available = $this->mslLaboratoryVocabularyService->getLocalPayload() !== null;
                } catch (\RuntimeException) {
                    $available = false;
                }
            }

            if ($available && $setting->type === ThesaurusSetting::TYPE_SIMPLE_LITHOLOGY) {
                try {
                    $available = $this->simpleLithologyVocabularyService->localPayload() !== null;
                } catch (\RuntimeException) {
                    $available = false;
                }
            }

            return [
                $setting->type => [
                    'available' => $available,
                    'displayName' => $setting->display_name,
                ],
            ];
        });

        return response()->json($thesauri);
    }

    /**
     * Return PID4INST instruments vocabulary.
     */
    public function pid4instInstruments(): JsonResponse
    {
        if (! $this->isPidActive(PidSetting::TYPE_PID4INST)) {
            return response()->json(['error' => 'PID4INST is disabled'], 404);
        }

        return $this->readPid4instInstruments();
    }

    private function readPid4instInstruments(): JsonResponse
    {
        try {
            return response()->json($this->instrumentVocabulary->getPublicPayload());
        } catch (VocabularyNotFoundException $exception) {
            return response()->json(['error' => $exception->getMessage()], 404);
        } catch (VocabularyReadException|VocabularyCorruptedException $exception) {
            return response()->json(['error' => $exception->getMessage()], 500);
        }
    }

    /**
     * Return public RAiD projects for ELMO and ERNIE integrations.
     */
    public function raidProjects(): JsonResponse
    {
        if (! $this->isPidActive(PidSetting::TYPE_RAID)) {
            return response()->json(['error' => 'RAiD is disabled'], 404);
        }

        return $this->readRaidProjects();
    }

    private function readRaidProjects(): JsonResponse
    {
        return $this->getCachedVocabulary(
            CacheKey::RAID_PROJECTS,
            'raid/raid-projects.json',
            'php artisan get-raid-projects'
        );
    }

    /**
     * Return ICS Chronostratigraphy vocabulary.
     */
    public function chronostratTimescale(): JsonResponse
    {
        return $this->legacyThesaurus(ThesaurusSetting::TYPE_CHRONOSTRAT);
    }

    /**
     * Return GEMET Thesaurus vocabulary.
     */
    public function gemetThesaurus(): JsonResponse
    {
        return $this->legacyThesaurus(ThesaurusSetting::TYPE_GEMET);
    }

    /**
     * Return Analytical Methods for Geochemistry vocabulary.
     */
    public function analyticalMethods(): JsonResponse
    {
        return $this->legacyThesaurus(ThesaurusSetting::TYPE_ANALYTICAL_METHODS);
    }

    /**
     * Return European Science Vocabulary (EuroSciVoc).
     */
    public function euroSciVoc(): JsonResponse
    {
        return $this->legacyThesaurus(ThesaurusSetting::TYPE_EUROSCIVOC);
    }

    /**
     * Return CGI Simple Lithology.
     */
    public function cgiSimpleLithology(): JsonResponse
    {
        return $this->legacyThesaurus(ThesaurusSetting::TYPE_SIMPLE_LITHOLOGY);
    }

    private function readSimpleLithology(): JsonResponse
    {
        try {
            $payload = $this->cacheService->cacheVocabulary(
                CacheKey::CGI_SIMPLE_LITHOLOGY,
                function (): array {
                    $payload = $this->simpleLithologyVocabularyService->localPayload();
                    if ($payload === null) {
                        throw new VocabularyNotFoundException('php artisan get-cgi-simple-lithology');
                    }

                    return $payload;
                },
            );

            return response()->json($payload);
        } catch (VocabularyNotFoundException $exception) {
            return response()->json(['error' => $exception->getMessage()], 404);
        } catch (\RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 500);
        }
    }

    /**
     * Return ROR affiliations vocabulary for ELMO.
     *
     * Returns the complete ROR data with metadata wrapper
     * including total count and last update timestamp.
     */
    public function rorAffiliations(): JsonResponse
    {
        if (! $this->isPidActive(PidSetting::TYPE_ROR)) {
            return response()->json(['error' => 'ROR is disabled'], 404);
        }

        return $this->readRorAffiliations(normalizeSnapshot: false);
    }

    private function readRorAffiliations(bool $normalizeSnapshot): JsonResponse
    {
        $filePath = 'ror/ror-affiliations.json';

        if (! Storage::exists($filePath)) {
            return response()->json([
                'error' => 'Vocabulary file not found. Please run: php artisan get-ror-ids',
            ], 404);
        }

        $content = Storage::get($filePath);

        if ($content === null) {
            return response()->json([
                'error' => 'Failed to read vocabulary file.',
            ], 500);
        }

        if ($normalizeSnapshot) {
            $root = ltrim($content)[0] ?? '';
            if (! in_array($root, ['{', '['], true) || ! json_validate($content)) {
                return response()->json(['error' => 'Invalid ROR vocabulary file.'], 500);
            }

            // Older snapshots store only the list and have no embedded update time.
            if ($root === '[') {
                /** @var list<mixed> $records */
                $records = json_decode($content);

                return response()->json([
                    'lastUpdated' => gmdate(DATE_ATOM, Storage::lastModified($filePath)),
                    'total' => count($records),
                    'data' => $records,
                ]);
            }
        }

        // Keep the full ROR registry as raw JSON to avoid materializing a second copy.
        return new JsonResponse(data: $content, json: true);
    }

    /**
     * Return PID availability status.
     *
     * Returns availability for the editor specified by the route.
     */
    public function pidAvailability(): JsonResponse
    {
        $column = $this->editorContext()->activationColumn();

        $pids = PidSetting::all()->mapWithKeys(fn (PidSetting $p) => [
            $p->type => [
                'available' => (bool) $p->getAttribute($column),
                'displayName' => $p->display_name,
            ],
        ]);

        return response()->json($pids);
    }

    /**
     * Check if a thesaurus is active for the current request context.
     */
    private function isThesaurusActive(string $type): bool
    {
        $setting = ThesaurusSetting::where('type', $type)->first();

        if (! $setting) {
            return true; // Default to active if no setting exists
        }

        return (bool) $setting->getAttribute($this->editorContext()->activationColumn());
    }

    /**
     * Check if a PID registry is active for the current request context.
     */
    private function isPidActive(string $type): bool
    {
        $setting = PidSetting::where('type', $type)->first();

        if (! $setting) {
            return true; // Default to active if no setting exists
        }

        return (bool) $setting->getAttribute($this->editorContext()->activationColumn());
    }

    /** Resolve the editor from trusted route defaults, independently of authentication. */
    private function editorContext(): EditorContext
    {
        $editor = request()->route()?->defaults['editor'] ?? EditorContext::ERNIE->value;

        return EditorContext::from($editor);
    }
}
