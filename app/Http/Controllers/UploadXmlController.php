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

        $titleElements = $reader->xpathElement('/resource/titles/title')->get();
        $titles = [];

        foreach ($titleElements as $element) {
            $titleType = $element->getAttribute('titleType');
            $titles[] = [
                'title' => $element->getContent(),
                'titleType' => $titleType ? Str::kebab($titleType) : 'main-title',
            ];
        }

        $mainTitles = array_values(array_filter(
            $titles,
            fn ($t) => $t['titleType'] === 'main-title'
        ));
        $otherTitles = array_values(array_filter(
            $titles,
            fn ($t) => $t['titleType'] !== 'main-title'
        ));
        $titles = array_merge($mainTitles, $otherTitles);

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
            'titles' => $titles,
        ]);
    }
}
