<?php

declare(strict_types=1);

use App\Http\Requests\StoreDraftResourceRequest;
use App\Http\Requests\StoreResourceRequest;
use App\Models\RelatedIdentifier;
use Illuminate\Support\Facades\Validator;

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

/**
 * @param  list<array<string, mixed>>  $dates
 */
function validateDraftDatePayload(array $dates): \Illuminate\Validation\Validator
{
    $request = StoreDraftResourceRequest::create('/editor/resources/draft', 'POST', [
        'titles' => [
            ['title' => 'Draft Resource', 'titleType' => 'main-title'],
        ],
        'dates' => $dates,
    ]);

    invokeDraftRequestMethod($request, 'prepareForValidation');

    $rules = array_intersect_key($request->rules(), array_flip([
        'dates',
        'dates.*.dateType',
        'dates.*.dateMode',
        'dates.*.startDate',
        'dates.*.endDate',
    ]));

    $validator = Validator::make($request->all(), $rules, $request->messages());

    foreach ($request->after() as $callback) {
        $validator->after($callback);
    }

    $validator->passes();

    return $validator;
}

it('allows closed draft periods for collected, valid, and other dates', function (string $dateType): void {
    $validator = validateDraftDatePayload([
        ['dateType' => $dateType, 'dateMode' => 'range', 'startDate' => '2024-01-01', 'endDate' => '2024-01-31'],
    ]);

    expect($validator->errors()->has('dates.0.endDate'))->toBeFalse()
        ->and($validator->errors()->has('dates.0.startDate'))->toBeFalse();
})->with(['collected', 'valid', 'other']);

it('rejects unsupported draft date periods', function (): void {
    $validator = validateDraftDatePayload([
        ['dateType' => 'available', 'dateMode' => 'range', 'startDate' => '2024-01-01', 'endDate' => '2024-01-31'],
    ]);

    expect($validator->errors()->has('dates.0.endDate'))->toBeTrue();
});

it('rejects draft end dates without start dates', function (): void {
    $validator = validateDraftDatePayload([
        ['dateType' => 'collected', 'dateMode' => 'range', 'startDate' => null, 'endDate' => '2024-01-31'],
    ]);

    expect($validator->errors()->has('dates.0.startDate'))->toBeTrue();
});

it('rejects draft periods whose end date is before the start date', function (): void {
    $validator = validateDraftDatePayload([
        ['dateType' => 'other', 'dateMode' => 'range', 'startDate' => '2024-02-01', 'endDate' => '2024-01-31'],
    ]);

    expect($validator->errors()->has('dates.0.endDate'))->toBeTrue();
});
it('rejects draft range date mode without an end date', function (): void {
    $validator = validateDraftDatePayload([
        ['dateType' => 'collected', 'dateMode' => 'range', 'startDate' => '2024-01-01', 'endDate' => null],
    ]);

    expect($validator->errors()->has('dates.0.endDate'))->toBeTrue();
});

it('rejects unknown draft date modes', function (): void {
    $validator = validateDraftDatePayload([
        ['dateType' => 'collected', 'dateMode' => 'period', 'startDate' => '2024-01-01', 'endDate' => '2024-01-31'],
    ]);

    expect($validator->errors()->has('dates.0.dateMode'))->toBeTrue();
});

it('rejects draft single date mode with an end date', function (): void {
    $validator = validateDraftDatePayload([
        ['dateType' => 'valid', 'dateMode' => 'single', 'startDate' => '2024-01-01', 'endDate' => '2024-01-31'],
    ]);

    expect($validator->errors()->has('dates.0.endDate'))->toBeTrue();
});

it('keeps date mode validation aligned between draft and final resource requests', function (): void {
    $draftRequest = new StoreDraftResourceRequest;
    $storeRequest = new StoreResourceRequest;

    expect($draftRequest->rules())->toHaveKey('dates.*.dateMode')
        ->and($storeRequest->rules())->toHaveKey('dates.*.dateMode');
});
