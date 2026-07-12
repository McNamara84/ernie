<?php

declare(strict_types=1);

use App\Services\DateType\DateTypePlausibilityService;

covers(DateTypePlausibilityService::class);

beforeEach(function () {
    $this->plausibilityService = new DateTypePlausibilityService();
});

it ('returns no hints for plausible date type order', function () 
{
    $warnings = $this->plausibilityService->hint
    ([
        'Collected' => ['2016-07-03'],
        'Created' => ['2017-07-03'],
        'Submitted' => ['2017-07-03'],
        'Accepted' => ['2017-08-03'],
        'Issued' => ['2018-07-03'],
        'Available' => ['2018-07-04'], 
    ]);
    expect($warnings)->toBe([]);
});


it ('returns grouped hints for implausible date value order', function () 
{
    $warnings = $this->plausibilityService->hint
    ([
        'Collected' => ['2018-07-03'],
        'Created' => ['2017-07-03'],
        'Submitted' => ['2016-07-03'],
        'Accepted' => ['2015-08-03'],
        'Issued' => ['2014-07-03'],
        'Available' => ['2013-07-04'], 
    ]);
    expect($warnings)->toHaveCount(5)
    ->and($warnings[0]['suggestion_kind'])->toBe('hint')
    ->and($warnings[0]['message'])->toBe('Collected (2018-07-03) occurs after Created (2017-07-03), Submitted (2016-07-03), Accepted (2015-08-03), Issued (2014-07-03), Available (2013-07-04). Please check whether the date values or date types are assigned correctly.')
    ->and($warnings[0]['confidence'])->toBe('medium')
    ->and($warnings[0]['is_ambiguous'])->toBeTrue()
    ->and($warnings[1]['suggestion_kind'])->toBe('hint')
    ->and($warnings[1]['message'])->toBe('Created (2017-07-03) occurs after Submitted (2016-07-03), Accepted (2015-08-03), Issued (2014-07-03), Available (2013-07-04). Please check whether the date values or date types are assigned correctly.')
    ->and($warnings[1]['confidence'])->toBe('medium')
    ->and($warnings[1]['is_ambiguous'])->toBeTrue()
    ->and($warnings[2]['suggestion_kind'])->toBe('hint')
    ->and($warnings[2]['message'])->toBe('Submitted (2016-07-03) occurs after Accepted (2015-08-03), Issued (2014-07-03), Available (2013-07-04). Please check whether the date values or date types are assigned correctly.')
    ->and($warnings[2]['confidence'])->toBe('medium')
    ->and($warnings[2]['is_ambiguous'])->toBeTrue()
    ->and($warnings[3]['suggestion_kind'])->toBe('hint')
    ->and($warnings[3]['message'])->toBe('Accepted (2015-08-03) occurs after Issued (2014-07-03), Available (2013-07-04). Please check whether the date values or date types are assigned correctly.')
    ->and($warnings[3]['confidence'])->toBe('medium')
    ->and($warnings[3]['is_ambiguous'])->toBeTrue()
    ->and($warnings[4]['suggestion_kind'])->toBe('hint')
    ->and($warnings[4]['message'])->toBe('Issued (2014-07-03) occurs after Available (2013-07-04). Please check whether the date values or date types are assigned correctly.')
    ->and($warnings[4]['confidence'])->toBe('medium')
    ->and($warnings[4]['is_ambiguous'])->toBeTrue()
    ->and($warnings[4]['source_url'])->toBeNull();

});

it ('does not treat array order as chronological date order', function () 
{
    $warnings = $this->plausibilityService->hint
    ([
        'Available' => ['2018-07-03'],
        'Issued' => ['2018-07-03'],
        'Accepted' => ['2018-07-03'],
        'Submitted' => ['2018-07-03'],
        'Created' => ['2018-07-03'],
        'Collected' => ['2016-07-03/2018-07-03'], 
    ]);
    expect($warnings)->toBe([]);

});

it('returns one hint when an earlier date type has a later value', function () {

    $warnings = $this->plausibilityService->hint([
        'Created' => ['2017-07-03'],
        'Collected' => ['2018-07-03'],

    ]);

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]['suggestion_kind'])->toBe('hint')
        ->and($warnings[0]['message'])->toBe('Collected (2018-07-03) occurs after Created (2017-07-03). Please check whether the date values or date types are assigned correctly.')
        ->and($warnings[0]['confidence'])->toBe('medium')
        ->and($warnings[0]['is_ambiguous'])->toBeTrue();

});


it ('skips rules when only one side of a rule is present', function () 
{
    expect($this->plausibilityService->hint([
        'Collected' => ['2016-07-03'],
    ]))->toBe([]);

    expect($this->plausibilityService->hint([
        'Created' => ['2017-07-03'],
    ]))->toBe([]);

    expect($this->plausibilityService->hint([
        'Submitted' => ['2017-07-03'],
    ]))->toBe([]);

    expect($this->plausibilityService->hint([
        'Accepted' => ['2017-08-03'],
    ]))->toBe([]);

    expect($this->plausibilityService->hint([
        'Issued' => ['2018-07-03'],
    ]))->toBe([]);

    expect($this->plausibilityService->hint([
        'Available' => ['2017-07-04'],
    ]))->toBe([]);

    expect($this->plausibilityService->hint([
        'Collected' => ['2016-07-03'],
        'Other' => ['2015-07-03'],
    ]))->toBe([]);

    expect($this->plausibilityService->hint([
        'Created' => ['2016-07-03'],
        'Withdrawn' => ['2015-07-03'],
    ]))->toBe([]);

    expect($this->plausibilityService->hint([
        'Issued' => ['2016-07-03'],
        'Copyrighted' =>['2015-07-03'],
    ]))->toBe([]);

    expect($this->plausibilityService->hint([
        'Available' => ['2016-07-03'],
        'Coverage' => ['2015/2016'],
    ]))->toBe([]);

});

it('adds source url when resource doi is provided', function () {
    $warnings = $this->plausibilityService->hint([
        'Created' => ['2023-02-22'],
        'Issued' => ['2018'],
    ], '10.5880/test.001');

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]['source_url'])->toBe('https://doi.org/10.5880/test.001');

});

it ('detects one implausible date among multiple dates of the same type', function () 
{
    $warnings = $this->plausibilityService->hint
    ([
        'Collected' => ['2016-07-03', '2018-07-03' ],
        'Issued' => ['2017-07-03'],
    ]);
    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]['suggestion_kind'])->toBe('hint')
        ->and($warnings[0]['message'])->toBe('Collected (2018-07-03) occurs after Issued (2017-07-03). Please check whether the date values or date types are assigned correctly.')
        ->and($warnings[0]['confidence'])->toBe('medium')
        ->and($warnings[0]['is_ambiguous'])->toBeTrue();

});

it ('detects multiple implausible dates of the same type', function () 
{
    $warnings = $this->plausibilityService->hint
    ([
        'Collected' => ['2016-07-03', '2018-07-03', '2019-07-03/2020-07-03' ],
        'Issued' => ['2017-07-03'],
    ]);
    expect($warnings)->toHaveCount(2)
        ->and($warnings[0]['suggestion_kind'])->toBe('hint')
        ->and($warnings[0]['message'])->toBe('Collected (2018-07-03) occurs after Issued (2017-07-03). Please check whether the date values or date types are assigned correctly.')
        ->and($warnings[0]['confidence'])->toBe('medium')
        ->and($warnings[0]['is_ambiguous'])->toBeTrue()
        ->and($warnings[1]['suggestion_kind'])->toBe('hint')
        ->and($warnings[1]['message'])->toBe('Collected (2019-07-03/2020-07-03) occurs after Issued (2017-07-03). Please check whether the date values or date types are assigned correctly.')
        ->and($warnings[1]['confidence'])->toBe('medium')
        ->and($warnings[1]['is_ambiguous'])->toBeTrue();
});

it ('returns hints when an earlier range ends after later range start', function () 
{
    $warnings = $this->plausibilityService->hint
    ([
        'Collected' => ['2016-07-03/2018-07-03'],
        'Available' => ['2016-07-03/2018-07-03'],
        'Issued' => ['2017-07-03'],
    ]);
    expect($warnings)->toHaveCount(2)
        ->and($warnings[0]['suggestion_kind'])->toBe('hint')
        ->and($warnings[0]['message'])->toBe('Collected (2016-07-03/2018-07-03) occurs after Issued (2017-07-03), Available (2016-07-03/2018-07-03). Please check whether the date values or date types are assigned correctly.')
        ->and($warnings[0]['confidence'])->toBe('medium')
        ->and($warnings[0]['is_ambiguous'])->toBeTrue()
        ->and($warnings[1]['suggestion_kind'])->toBe('hint')
        ->and($warnings[1]['message'])->toBe('Issued (2017-07-03) occurs after Available (2016-07-03/2018-07-03). Please check whether the date values or date types are assigned correctly.')
        ->and($warnings[1]['confidence'])->toBe('medium')
        ->and($warnings[1]['is_ambiguous'])->toBeTrue();
});

it ('returns no hint when an earlier range ends before a later date starts', function () 
{
    $warnings = $this->plausibilityService->hint
    ([
        'Collected' => ['2016-07-03/2018-07-03'],
        'Issued' => ['2019-07-03'],
    ]);
    expect($warnings)->toBe([]);
});

it ('compares the end of an earlier range with the start of a later range', function () 
{
    $warnings = $this->plausibilityService->hint
    ([
        'Collected' => ['2016-07-03/2018-07-03'],
        'Available' => ['2017-07-03/2019-07-03'],
    ]);
    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]['suggestion_kind'])->toBe('hint')
        ->and($warnings[0]['message'])->toBe('Collected (2016-07-03/2018-07-03) occurs after Available (2017-07-03/2019-07-03). Please check whether the date values or date types are assigned correctly.')
        ->and($warnings[0]['confidence'])->toBe('medium')
        ->and($warnings[0]['is_ambiguous'])->toBeTrue();
});

it ('ignores invalid legacy date values during plausibility checks', function () 
{
    $warnings = $this->plausibilityService->hint
    ([
        'Created' => ['2018-13-03'],
        'Issued' => ['2019-07-03'],
    ]);
    expect($warnings)->toBe([]);
});