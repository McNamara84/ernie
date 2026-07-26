<?php

declare(strict_types=1);

namespace App\Http\Controllers\OaiPmh;

use App\Http\Controllers\Controller;
use App\Services\Iso19115\Iso19115ResourceProfileService;
use App\Services\OaiPmh\OaiPmhSetService;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Serves the OAI-PMH harvester documentation page.
 */
class OaiPmhDocsController extends Controller
{
    public function index(OaiPmhSetService $setService, Iso19115ResourceProfileService $isoProfile): Response
    {
        $activeSets = $setService->listSets();

        $resourceTypeSlugs = collect($activeSets)
            ->filter(fn (array $set) => str_starts_with($set['spec'], 'resourcetype:'))
            ->map(fn (array $set) => substr($set['spec'], strlen('resourcetype:')))
            ->values()
            ->all();

        // Fall back to all resource types when no published sets exist yet
        if ($resourceTypeSlugs === []) {
            $resourceTypeSlugs = DB::table('resource_types')
                ->orderBy('name')
                ->pluck('slug')
                ->all();
        }

        /** @var array<string, array{schema: string, namespace: string}> $metadataFormats */
        $metadataFormats = config('oaipmh.metadata_formats', []);
        if (! $isoProfile->isEnabled()) {
            unset($metadataFormats['iso19115_3']);
        }

        return Inertia::render('oai-pmh/docs', [
            'baseUrl' => config('oaipmh.base_url'),
            'adminEmail' => config('oaipmh.admin_email'),
            'metadataFormats' => $metadataFormats,
            'isoEligibleResourceTypeSlugs' => $isoProfile->isEnabled() ? $isoProfile->eligibleResourceTypeSlugs() : [],
            'resourceTypeSlugs' => $resourceTypeSlugs,
            'identifierPrefix' => config('oaipmh.identifier_prefix'),
            'pageSize' => (int) config('oaipmh.page_size', 100),
            'tokenTtlHours' => (int) round((int) config('oaipmh.resumption_token_ttl', 86400) / 3600),
        ]);
    }
}
