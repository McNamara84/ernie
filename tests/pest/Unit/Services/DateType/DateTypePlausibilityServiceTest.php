<?php

declare(strict_types=1);

use App\Services\DateType\DateTypePlausibilityService;

covers(DateTypePlausibilityService::class);

beforeEach(function () {
    $this->plausibilityService = new DateTypePlausibilityService();
});

it ('returns no hints for plausible date type order', function () 
{
    $warnings = $this->plausibilityService->review 
    ([
        'Collected' => '2016-07-03',
        'Created' => '2017-07-03',
        'Submitted' => '2017-07-03',
        'Accepted' => '2017-08-03',
        'Issued' => '2018-07-03',
        'Available' => '2018-07-04', 
    ]);
    expect($warnings)->toBe([]);
});

it ('returns hints for implausible date value order', function () 
{
    $warnings = $this->plausibilityService->review 
    ([
        'Collected' => '2018-07-03',
        'Created' => '2017-07-03',
        'Submitted' => '2016-07-03',
        'Accepted' => '2015-08-03',
        'Issued' => '2014-07-03',
        'Available' => '2013-07-04', 
    ]);
    expect($warnings)->toHaveCount(15)
    ->and($warnings[0]['suggestion_kind'])->toBe('review')
    ->and($warnings[0]['message'])->toBe('Collected (2018-07-03) occurs after Created (2017-07-03). Please check whether the date values or date types are assigned correctly.')
    ->and($warnings[0]['confidence'])->toBe('medium')
    ->and($warnings[0]['is_ambiguous'])->toBeTrue()
    ->and($warnings[1]['suggestion_kind'])->toBe('review')
    ->and($warnings[1]['message'])->toBe('Collected (2018-07-03) occurs after Submitted (2016-07-03). Please check whether the date values or date types are assigned correctly.')
    ->and($warnings[1]['confidence'])->toBe('medium')
    ->and($warnings[1]['is_ambiguous'])->toBeTrue()
    ->and($warnings[2]['suggestion_kind'])->toBe('review')
    ->and($warnings[2]['message'])->toBe('Collected (2018-07-03) occurs after Accepted (2015-08-03). Please check whether the date values or date types are assigned correctly.')
    ->and($warnings[2]['confidence'])->toBe('medium')
    ->and($warnings[2]['is_ambiguous'])->toBeTrue()
    ->and($warnings[3]['suggestion_kind'])->toBe('review')
    ->and($warnings[3]['message'])->toBe('Collected (2018-07-03) occurs after Issued (2014-07-03). Please check whether the date values or date types are assigned correctly.')
    ->and($warnings[3]['confidence'])->toBe('medium')
    ->and($warnings[3]['is_ambiguous'])->toBeTrue()
    ->and($warnings[4]['suggestion_kind'])->toBe('review')
    ->and($warnings[4]['message'])->toBe('Collected (2018-07-03) occurs after Available (2013-07-04). Please check whether the date values or date types are assigned correctly.')
    ->and($warnings[4]['confidence'])->toBe('medium')
    ->and($warnings[4]['is_ambiguous'])->toBeTrue()
    ->and($warnings[5]['suggestion_kind'])->toBe('review')
    ->and($warnings[5]['message'])->toBe('Created (2017-07-03) occurs after Submitted (2016-07-03). Please check whether the date values or date types are assigned correctly.')
    ->and($warnings[5]['confidence'])->toBe('medium')
    ->and($warnings[5]['is_ambiguous'])->toBeTrue()
    ->and($warnings[6]['suggestion_kind'])->toBe('review')
    ->and($warnings[6]['message'])->toBe('Created (2017-07-03) occurs after Accepted (2015-08-03). Please check whether the date values or date types are assigned correctly.')
    ->and($warnings[6]['confidence'])->toBe('medium')
    ->and($warnings[6]['is_ambiguous'])->toBeTrue()
    ->and($warnings[7]['suggestion_kind'])->toBe('review')
    ->and($warnings[7]['message'])->toBe('Created (2017-07-03) occurs after Issued (2014-07-03). Please check whether the date values or date types are assigned correctly.')
    ->and($warnings[7]['confidence'])->toBe('medium')
    ->and($warnings[7]['is_ambiguous'])->toBeTrue()
    ->and($warnings[8]['suggestion_kind'])->toBe('review')
    ->and($warnings[8]['message'])->toBe('Created (2017-07-03) occurs after Available (2013-07-04). Please check whether the date values or date types are assigned correctly.')
    ->and($warnings[8]['confidence'])->toBe('medium')
    ->and($warnings[8]['is_ambiguous'])->toBeTrue()
    ->and($warnings[9]['suggestion_kind'])->toBe('review')
    ->and($warnings[9]['message'])->toBe('Submitted (2016-07-03) occurs after Accepted (2015-08-03). Please check whether the date values or date types are assigned correctly.')
    ->and($warnings[9]['confidence'])->toBe('medium')
    ->and($warnings[9]['is_ambiguous'])->toBeTrue()
    ->and($warnings[10]['suggestion_kind'])->toBe('review')
    ->and($warnings[10]['message'])->toBe('Submitted (2016-07-03) occurs after Issued (2014-07-03). Please check whether the date values or date types are assigned correctly.')
    ->and($warnings[10]['confidence'])->toBe('medium')
    ->and($warnings[10]['is_ambiguous'])->toBeTrue()
    ->and($warnings[11]['suggestion_kind'])->toBe('review')
    ->and($warnings[11]['message'])->toBe('Submitted (2016-07-03) occurs after Available (2013-07-04). Please check whether the date values or date types are assigned correctly.')
    ->and($warnings[11]['confidence'])->toBe('medium')
    ->and($warnings[11]['is_ambiguous'])->toBeTrue()
    ->and($warnings[12]['suggestion_kind'])->toBe('review')
    ->and($warnings[12]['message'])->toBe('Accepted (2015-08-03) occurs after Issued (2014-07-03). Please check whether the date values or date types are assigned correctly.')
    ->and($warnings[12]['confidence'])->toBe('medium')
    ->and($warnings[12]['is_ambiguous'])->toBeTrue()
    ->and($warnings[13]['suggestion_kind'])->toBe('review')
    ->and($warnings[13]['message'])->toBe('Accepted (2015-08-03) occurs after Available (2013-07-04). Please check whether the date values or date types are assigned correctly.')
    ->and($warnings[13]['confidence'])->toBe('medium')
    ->and($warnings[13]['is_ambiguous'])->toBeTrue()
    ->and($warnings[14]['suggestion_kind'])->toBe('review')
    ->and($warnings[14]['message'])->toBe('Issued (2014-07-03) occurs after Available (2013-07-04). Please check whether the date values or date types are assigned correctly.')
    ->and($warnings[14]['confidence'])->toBe('medium')
    ->and($warnings[14]['is_ambiguous'])->toBeTrue();

});

it ('returns hints for implausible date type order', function () 
{
    $warnings = $this->plausibilityService->review 
    ([
        'Available' => '2018-07-03',
        'Issued' => '2018-07-03',
        'Accepted' => '2018-07-03',
        'Submitted' => '2018-07-03',
        'Created' => '2018-07-03',
        'Collected' => '2018-07-03', 
    ]);
    expect($warnings)->toHaveCount(15)
    ->and($warnings[0]['suggestion_kind'])->toBe('review')
    ->and($warnings[0]['message'])->toBe('Collected (2018-07-03) occurs after Created (2018-07-03). Please check whether the date values or date types are assigned correctly.')
    ->and($warnings[0]['confidence'])->toBe('medium')
    ->and($warnings[0]['is_ambiguous'])->toBeTrue()
    ->and($warnings[1]['suggestion_kind'])->toBe('review')
    ->and($warnings[1]['message'])->toBe('Collected (2018-07-03) occurs after Submitted (2018-07-03). Please check whether the date values or date types are assigned correctly.')
    ->and($warnings[1]['confidence'])->toBe('medium')
    ->and($warnings[1]['is_ambiguous'])->toBeTrue()
    ->and($warnings[2]['suggestion_kind'])->toBe('review')
    ->and($warnings[2]['message'])->toBe('Collected (2018-07-03) occurs after Accepted (2018-07-03). Please check whether the date values or date types are assigned correctly.')
    ->and($warnings[2]['confidence'])->toBe('medium')
    ->and($warnings[2]['is_ambiguous'])->toBeTrue()
    ->and($warnings[3]['suggestion_kind'])->toBe('review')
    ->and($warnings[3]['message'])->toBe('Collected (2018-07-03) occurs after Issued (2018-07-03). Please check whether the date values or date types are assigned correctly.')
    ->and($warnings[3]['confidence'])->toBe('medium')
    ->and($warnings[3]['is_ambiguous'])->toBeTrue()
    ->and($warnings[4]['suggestion_kind'])->toBe('review')
    ->and($warnings[4]['message'])->toBe('Collected (2018-07-03) occurs after Available (2018-07-03). Please check whether the date values or date types are assigned correctly.')
    ->and($warnings[4]['confidence'])->toBe('medium')
    ->and($warnings[4]['is_ambiguous'])->toBeTrue()
    ->and($warnings[5]['suggestion_kind'])->toBe('review')
    ->and($warnings[5]['message'])->toBe('Created (2018-07-03) occurs after Submitted (2018-07-03). Please check whether the date values or date types are assigned correctly.')
    ->and($warnings[5]['confidence'])->toBe('medium')
    ->and($warnings[5]['is_ambiguous'])->toBeTrue()
    ->and($warnings[6]['suggestion_kind'])->toBe('review')
    ->and($warnings[6]['message'])->toBe('Created (2018-07-03) occurs after Accepted (2018-07-03). Please check whether the date values or date types are assigned correctly.')
    ->and($warnings[6]['confidence'])->toBe('medium')
    ->and($warnings[6]['is_ambiguous'])->toBeTrue()
    ->and($warnings[7]['suggestion_kind'])->toBe('review')
    ->and($warnings[7]['message'])->toBe('Created (2018-07-03) occurs after Issued (2018-07-03). Please check whether the date values or date types are assigned correctly.')
    ->and($warnings[7]['confidence'])->toBe('medium')
    ->and($warnings[7]['is_ambiguous'])->toBeTrue()
    ->and($warnings[8]['suggestion_kind'])->toBe('review')
    ->and($warnings[8]['message'])->toBe('Created (2018-07-03) occurs after Available (2018-07-03). Please check whether the date values or date types are assigned correctly.')
    ->and($warnings[8]['confidence'])->toBe('medium')
    ->and($warnings[8]['is_ambiguous'])->toBeTrue()
    ->and($warnings[9]['suggestion_kind'])->toBe('review')
    ->and($warnings[9]['message'])->toBe('Submitted (2018-07-03) occurs after Accepted (2018-07-03). Please check whether the date values or date types are assigned correctly.')
    ->and($warnings[9]['confidence'])->toBe('medium')
    ->and($warnings[9]['is_ambiguous'])->toBeTrue()
    ->and($warnings[10]['suggestion_kind'])->toBe('review')
    ->and($warnings[10]['message'])->toBe('Submitted (2018-07-03) occurs after Issued (2018-07-03). Please check whether the date values or date types are assigned correctly.')
    ->and($warnings[10]['confidence'])->toBe('medium')
    ->and($warnings[10]['is_ambiguous'])->toBeTrue()
    ->and($warnings[11]['suggestion_kind'])->toBe('review')
    ->and($warnings[11]['message'])->toBe('Submitted (2018-07-03) occurs after Available (2018-07-03). Please check whether the date values or date types are assigned correctly.')
    ->and($warnings[11]['confidence'])->toBe('medium')
    ->and($warnings[11]['is_ambiguous'])->toBeTrue()
    ->and($warnings[12]['suggestion_kind'])->toBe('review')
    ->and($warnings[12]['message'])->toBe('Accepted (2018-07-03) occurs after Issued (2018-07-03). Please check whether the date values or date types are assigned correctly.')
    ->and($warnings[12]['confidence'])->toBe('medium')
    ->and($warnings[12]['is_ambiguous'])->toBeTrue()
    ->and($warnings[13]['suggestion_kind'])->toBe('review')
    ->and($warnings[13]['message'])->toBe('Accepted (2018-07-03) occurs after Available (2018-07-03). Please check whether the date values or date types are assigned correctly.')
    ->and($warnings[13]['confidence'])->toBe('medium')
    ->and($warnings[13]['is_ambiguous'])->toBeTrue()
    ->and($warnings[14]['suggestion_kind'])->toBe('review')
    ->and($warnings[14]['message'])->toBe('Issued (2018-07-03) occurs after Available (2018-07-03). Please check whether the date values or date types are assigned correctly.')
    ->and($warnings[14]['confidence'])->toBe('medium')
    ->and($warnings[14]['is_ambiguous'])->toBeTrue();

});

it('returns only one hint when date type and date value order are both implausible', function () {

    $warnings = $this->plausibilityService->review([
        'Created' => '2017-07-03',
        'Collected' => '2018-07-03',

    ]);

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]['suggestion_kind'])->toBe('review')
        ->and($warnings[0]['message'])->toBe('Collected (2018-07-03) occurs after Created (2017-07-03). Please check whether the date values or date types are assigned correctly.')
        ->and($warnings[0]['confidence'])->toBe('medium')
        ->and($warnings[0]['is_ambiguous'])->toBeTrue();

});


it ('skips rules when only one side of a rule is present', function () 
{
    expect($this->plausibilityService->review([
        'Collected' => '2016-07-03',
    ]))->toBe([]);

    expect($this->plausibilityService->review([
        'Created' => '2017-07-03',
    ]))->toBe([]);

    expect($this->plausibilityService->review([
        'Submitted' => '2017-07-03',
    ]))->toBe([]);

    expect($this->plausibilityService->review([
        'Accepted' => '2017-08-03',
    ]))->toBe([]);

    expect($this->plausibilityService->review([
        'Issued' => '2018-07-03',
    ]))->toBe([]);

    expect($this->plausibilityService->review([
        'Available' => '2017-07-04',
    ]))->toBe([]);

    expect($this->plausibilityService->review([
        'Collected' => '2016-07-03',
        'Other' => '2015-07-03',
    ]))->toBe([]);

    expect($this->plausibilityService->review([
        'Created' => '2016-07-03',
        'Withdrawn' => '2015-07-03',
    ]))->toBe([]);

    expect($this->plausibilityService->review([
        'Issued' => '2016-07-03',
        'Copyrighted' => '2015-07-03',
    ]))->toBe([]);

    expect($this->plausibilityService->review([
        'Available' => '2016-07-03',
        'Coverage' => '2015/2016',
    ]))->toBe([]);

});

