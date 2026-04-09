<?php

declare(strict_types=1);

namespace App\Http\Controllers\OaiPmh;

use App\Http\Controllers\Controller;
use App\Services\OaiPmh\OaiPmhService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * OAI-PMH 2.0 harvesting endpoint.
 *
 * Single endpoint handling all 6 OAI-PMH verbs via the `verb` query parameter.
 * Supports both GET and POST as required by the OAI-PMH specification.
 *
 * @see http://www.openarchives.org/OAI/openarchivesprotocol.html
 */
class OaiPmhController extends Controller
{
    public function __invoke(Request $request, OaiPmhService $service): Response
    {
        $xml = $service->handleRequest($request);

        return response($xml, 200, [
            'Content-Type' => 'text/xml; charset=utf-8',
        ]);
    }
}
