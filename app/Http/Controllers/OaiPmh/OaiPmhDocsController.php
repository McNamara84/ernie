<?php

declare(strict_types=1);

namespace App\Http\Controllers\OaiPmh;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Serves the OAI-PMH harvester documentation page.
 */
class OaiPmhDocsController extends Controller
{
    public function index(): Response
    {
        $resourceTypeSlugs = DB::table('resource_types')
            ->orderBy('name')
            ->pluck('slug')
            ->all();

        return Inertia::render('oai-pmh/docs', [
            'baseUrl' => config('oaipmh.base_url'),
            'adminEmail' => config('oaipmh.admin_email'),
            'metadataFormats' => config('oaipmh.metadata_formats'),
            'resourceTypeSlugs' => $resourceTypeSlugs,
            'identifierPrefix' => config('oaipmh.identifier_prefix'),
        ]);
    }
}
