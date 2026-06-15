<?php

declare(strict_types=1);

use App\Http\Requests\StoreDraftResourceRequest;
use App\Http\Requests\StoreResourceRequest;
use App\Models\RelatedIdentifier;

covers(StoreDraftResourceRequest::class);

/**
 * @param  array<int, mixed>  $arguments
 */
function invokeDraftRequestMethod(StoreDraftResourceRequest $request, string $method, array $arguments = []): mixed
{
    $reflection = new ReflectionMethod($request, $method);
    $reflection->setAccessible(true);

    return $reflection->invokeArgs($request, $arguments);
}

it('normalizes related identifiers and keeps optional related-work fields only when non-empty', function (): void {
    $request = StoreDraftResourceRequest::create('/editor/resources/draft', 'POST', [
        'titles' => [
            ['title' => 'Draft Resource', 'titleType' => 'main-title'],
        ],
        'relatedIdentifiers' => [
            [
                'identifier' => ' 10.5880/test.2026.001 ',
                'identifierType' => ' DOI ',
                'relationType' => ' Other ',
                'relationTypeInformation' => '  Custom relationship  ',
                'citationLabel' => '  Doe, J. (2026): Example citation.  ',
            ],
            [
                'identifier' => ' https://example.org/resource ',
                'identifierType' => 'URL',
                'relationType' => 'References',
                'relationTypeInformation' => '   ',
                'citationLabel' => '   ',
            ],
            [
                'identifier' => '   ',
                'identifierType' => 'DOI',
                'relationType' => 'Cites',
            ],
            'not-an-array',
        ],
    ]);

    invokeDraftRequestMethod($request, 'prepareForValidation');

    expect($request->input('relatedIdentifiers'))->toBe([
        [
            'identifier' => '10.5880/test.2026.001',
            'identifierType' => 'DOI',
            'relationType' => 'Other',
            'relationTypeInformation' => 'Custom relationship',
            'citationLabel' => 'Doe, J. (2026): Example citation.',
        ],
        [
            'identifier' => 'https://example.org/resource',
            'identifierType' => 'URL',
            'relationType' => 'References',
        ],
    ]);
});

it('normalizes raw rights statements for draft saves', function (): void {
    $request = StoreDraftResourceRequest::create('/editor/resources/draft', 'POST', [
        'titles' => [
            ['title' => 'Draft Resource', 'titleType' => 'main-title'],
        ],
        'licenses' => [' CC-BY-4.0 ', '', 'CC-BY-4.0'],
        'rawRights' => [
            [
                'rights_text' => ' CC BY 4.0 ',
                'rightsURI' => ' http://creativecommons.org/licenses/by/4.0 ',
                'rights_identifier' => ' CC-BY-4.0 ',
                'rightsIdentifierScheme' => ' SPDX ',
                'schemeURI' => ' https://spdx.org/licenses/ ',
                'language' => ' en ',
                'source' => ' xml-upload ',
            ],
            [
                'rights' => '   ',
                'rightsUri' => null,
                'rightsIdentifier' => [],
                'source' => (object) ['ignored' => true],
            ],
            'not-a-statement',
        ],
    ]);

    invokeDraftRequestMethod($request, 'prepareForValidation');

    expect($request->input('licenses'))->toBe(['CC-BY-4.0'])
        ->and($request->input('rawRights'))->toBe([
            [
                'rights' => 'CC BY 4.0',
                'rightsUri' => 'http://creativecommons.org/licenses/by/4.0',
                'rightsIdentifier' => 'CC-BY-4.0',
                'rightsIdentifierScheme' => 'SPDX',
                'schemeUri' => 'https://spdx.org/licenses/',
                'lang' => 'en',
                'source' => 'xml-upload',
            ],
        ]);
});

it('keeps non-array raw rights input unchanged for draft validation', function (): void {
    $request = StoreDraftResourceRequest::create('/editor/resources/draft', 'POST', [
        'titles' => [
            ['title' => 'Draft Resource', 'titleType' => 'main-title'],
        ],
        'rawRights' => 'not-an-array',
    ]);

    invokeDraftRequestMethod($request, 'prepareForValidation');

    expect($request->input('rawRights'))->toBe('not-an-array');
});

it('keeps related-work citation label limits aligned between draft and store requests', function (): void {
    $draftRequest = new StoreDraftResourceRequest;
    $storeRequest = new StoreResourceRequest;

    expect($draftRequest->rules()['relatedIdentifiers.*.citationLabel'])
        ->toContain('max:'.RelatedIdentifier::MAX_CITATION_LABEL_CHARACTERS)
        ->and($storeRequest->rules()['relatedIdentifiers.*.citationLabel'])
        ->toContain('max:'.RelatedIdentifier::MAX_CITATION_LABEL_CHARACTERS);
});
