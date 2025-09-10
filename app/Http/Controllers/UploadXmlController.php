<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Saloon\XmlWrangler\XmlReader;

class UploadXmlController extends Controller
{
    public function __invoke(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file'],
        ]);

        $contents = $request->file('file')->get();

        $reader = XmlReader::fromString($contents);
        $doi = $reader->xpathValue('//identifier[@identifierType="DOI"]')->first();

        return response()->json(['doi' => $doi]);
    }
}
