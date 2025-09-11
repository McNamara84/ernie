<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\ResourceType;
use Illuminate\Support\Str;
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
        $version = $reader->xpathValue('//version')->first();
        $language = $reader->xpathValue('//language')->first();

        $resourceTypeElement = $reader->xpathElement('//resourceType')->first();
        $resourceTypeName = $resourceTypeElement?->getAttribute('resourceTypeGeneral');
        $resourceType = null;

        if ($resourceTypeName !== null) {
            $resourceTypeModel = ResourceType::whereRaw('LOWER(name) = ?', [Str::lower($resourceTypeName)])->first();
            $resourceType = $resourceTypeModel?->slug;
        }

        return response()->json([
            'doi' => $doi,
            'year' => $year,
            'version' => $version,
            'language' => $language,
            'resourceType' => $resourceType,
        ]);
    }
}
