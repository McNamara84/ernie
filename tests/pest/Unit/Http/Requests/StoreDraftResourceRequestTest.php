<?php

declare(strict_types=1);

use App\Http\Requests\StoreDraftResourceRequest;

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

it('limits related-work citation labels to the text-safe validation maximum', function (): void {
    $request = new StoreDraftResourceRequest;

    expect($request->rules()['relatedIdentifiers.*.citationLabel'])->toContain('max:65535');
});