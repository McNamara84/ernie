<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Saloon\XmlWrangler\XmlReader;

class UploadXmlController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xml', 'max:4096'],
        ]);

        $contents = $request->file('file')->get();

        $reader = XmlReader::fromString($contents);
        $doi = $reader->xpathValue('//identifier[@identifierType="DOI"]')->first();
        $year = $reader->xpathValue('//publicationYear')->first();

        return response()->json([
            'doi' => $doi,
            'year' => $year,
        ]);
    }
}
