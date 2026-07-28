<?php

declare(strict_types=1);

use App\Services\DateType\DateTypePlausibilityService;

covers(DateTypePlausibilityService::class);

beforeEach(function () {
    $this->plausibilityService = new DateTypePlausibilityService;
});

it('returns no hints for plausible date type order', function () {
    $warnings = $this->plausibilityService->hint([
        'Collected' => ['2016-07-03'],
        'Created' => ['2017-07-03'],
        'Submitted' => ['2017-07-03'],
        'Accepted' => ['2017-08-03'],
        'Issued' => ['2018-07-03'],
        'Available' => ['2018-07-04'],
    ]);
    expect($warnings)->toBe([]);
});

it('returns grouped hints for implausible date value order', function () {
    $warnings = $this->plausibilityService->hint([
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

it('does not treat array order as chronological date order', function () {
    $warnings = $this->plausibilityService->hint([
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

it('skips rules when only one side of a rule is present', function () {
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
        'Copyrighted' => ['2015-07-03'],
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

it('detects one implausible date among multiple dates of the same type', function () {
    $warnings = $this->plausibilityService->hint([
        'Collected' => ['2016-07-03', '2018-07-03'],
        'Issued' => ['2017-07-03'],
    ]);
    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]['suggestion_kind'])->toBe('hint')
        ->and($warnings[0]['message'])->toBe('Collected (2018-07-03) occurs after Issued (2017-07-03). Please check whether the date values or date types are assigned correctly.')
        ->and($warnings[0]['confidence'])->toBe('medium')
        ->and($warnings[0]['is_ambiguous'])->toBeTrue();

});

it('detects multiple implausible dates of the same type', function () {
    $warnings = $this->plausibilityService->hint([
        'Collected' => ['2016-07-03', '2018-07-03', '2019-07-03/2020-07-03'],
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

it('returns hints when an earlier range ends after later range start', function () {
    $warnings = $this->plausibilityService->hint([
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

it('returns no hint when an earlier range ends before a later range starts', function () {
    $warnings = $this->plausibilityService->hint([
        'Collected' => ['2016-07-03/2018-07-03'],
        'Issued' => ['2019-07-03/2020-07-03'],
    ]);
    expect($warnings)->toBe([]);
});

it('compares the end of an earlier range with the start of a later range', function () {
    $warnings = $this->plausibilityService->hint([
        'Collected' => ['2016-07-03/2018-07-03'],
        'Available' => ['2017-07-03/2019-07-03'],
    ]);
    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]['suggestion_kind'])->toBe('hint')
        ->and($warnings[0]['message'])->toBe('Collected (2016-07-03/2018-07-03) occurs after Available (2017-07-03/2019-07-03). Please check whether the date values or date types are assigned correctly.')
        ->and($warnings[0]['confidence'])->toBe('medium')
        ->and($warnings[0]['is_ambiguous'])->toBeTrue();
});

it('ignores invalid legacy date values during plausibility checks', function () {
    $warnings = $this->plausibilityService->hint([
        'Created' => ['2018-13-03'],
        'Issued' => ['2019-07-03'],
    ]);
    expect($warnings)->toBe([]);
});

it('handles different date formats correctly', function () {
    $warnings = $this->plausibilityService->hint([
        'Collected' => ['2016', '2017-07-03', '2018/2019', '2020-08-01/2021'],
        'Issued' => ['2018'],
    ]);
    expect($warnings)->toHaveCount(2)
        ->and($warnings[0]['suggestion_kind'])->toBe('hint')
        ->and($warnings[0]['message'])->toBe('Collected (2018/2019) occurs after Issued (2018). Please check whether the date values or date types are assigned correctly.')
        ->and($warnings[0]['confidence'])->toBe('medium')
        ->and($warnings[0]['is_ambiguous'])->toBeTrue()
        ->and($warnings[1]['suggestion_kind'])->toBe('hint')
        ->and($warnings[1]['message'])->toBe('Collected (2020-08-01/2021) occurs after Issued (2018). Please check whether the date values or date types are assigned correctly.')
        ->and($warnings[1]['confidence'])->toBe('medium')
        ->and($warnings[1]['is_ambiguous'])->toBeTrue();
});

it('does not report the issue 1034 range as after a containing partial year', function () {
    $warnings = $this->plausibilityService->hint([
        'Collected' => ['2011-01-01/2023-05-01'],
        'Created' => ['2023'],
    ]);

    expect($warnings)->toBe([]);
});

it('does not report chronologically ordered date-time boundaries with different representations', function (
    string $earlierValue,
    string $laterValue,
) {
    $warnings = $this->plausibilityService->hint([
        'Collected' => [$earlierValue],
        'Created' => [$laterValue],
    ]);

    expect($warnings)->toBe([]);
})->with([
    'lexically later value is an earlier instant' => ['2024-01-01T00:30:00+02:00', '2023-12-31T23:00:00Z'],
    'equivalent instants use different offsets' => ['2024-01-01T01:00:00+01:00', '2024-01-01T00:00:00Z'],
    'equivalent local times use optional seconds' => ['2024-01-01T00:30:00', '2024-01-01T00:30'],
    'fractional seconds use decimal commas' => ['2024-01-01T00:00:00,400Z', '2024-01-01T01:00:00,500+01:00'],
    'earlier range end is an earlier instant' => [
        '2023-12-30T00:00:00Z/2024-01-01T00:30:00+02:00',
        '2023-12-31T23:00:00Z',
    ],
]);

it('reports chronologically reversed date-time boundaries across timezone offsets', function (
    string $earlierValue,
    string $laterValue,
) {
    $warnings = $this->plausibilityService->hint([
        'Collected' => [$earlierValue],
        'Created' => [$laterValue],
    ]);

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]['message'])->toBe(sprintf(
            'Collected (%s) occurs after Created (%s). Please check whether the date values or date types are assigned correctly.',
            $earlierValue,
            $laterValue,
        ));
})->with([
    'lexically earlier value is a later instant' => ['2023-12-31T23:30:00Z', '2024-01-01T00:00:00+02:00'],
    'same local date has reversed offset instants' => ['2024-01-01T00:30:00-02:00', '2024-01-01T01:00:00Z'],
    'fractional seconds use decimal commas' => ['2024-01-01T00:00:00,500Z', '2024-01-01T01:00:00,400+01:00'],
    'timezone offset omits the colon' => ['2023-12-31T23:30:00Z', '2024-01-01T00:00:00+0200'],
    'later range start is an earlier instant' => [
        '2023-12-31T23:30:00Z',
        '2024-01-01T00:00:00+02:00/2024-01-02T00:00:00+02:00',
    ],
]);

it('ignores normalized date-times that cannot be parsed as instants', function (
    string $earlierValue,
    string $laterValue,
) {
    $warnings = $this->plausibilityService->hint([
        'Collected' => [$earlierValue],
        'Created' => [$laterValue],
    ]);

    expect($warnings)->toBe([]);
})->with([
    'invalid earlier time' => ['2024-01-01T25:00:00Z', '2024-01-01T00:00:00Z'],
    'invalid later timezone offset' => ['2024-01-01T00:00:00Z', '2024-01-01T00:00:00+25:00'],
]);

it('does not report overlapping mixed-precision boundaries as implausible', function (
    string $earlierValue,
    string $laterValue,
) {
    $warnings = $this->plausibilityService->hint([
        'Collected' => [$earlierValue],
        'Created' => [$laterValue],
    ]);

    expect($warnings)->toBe([]);
})->with([
    'full date inside later year' => ['2023-05-01', '2023'],
    'earlier year contains later full date' => ['2023', '2023-05-01'],
    'full date inside later month' => ['2023-05-15', '2023-05'],
    'earlier month contains later full date' => ['2023-05', '2023-05-15'],
    'leap day inside later leap month' => ['2024-02-29', '2024-02'],
    'partial month starts on later full date' => ['2024-03', '2024-03-01'],
    'date-time inside later year' => ['2023-05-01T12:30:00Z', '2023'],
    'full date inside partial later range start' => ['2023-05-01', '2023/2024'],
    'partial earlier range end contains later full date' => ['2022/2023', '2023-05-01'],
]);

it('reports definitively separated mixed-precision boundaries as implausible', function (
    string $earlierValue,
    string $laterValue,
) {
    $warnings = $this->plausibilityService->hint([
        'Collected' => [$earlierValue],
        'Created' => [$laterValue],
    ]);

    $expectedMessage = sprintf(
        'Collected (%s) occurs after Created (%s). Please check whether the date values or date types are assigned correctly.',
        $earlierValue,
        $laterValue,
    );

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]['suggestion_kind'])->toBe('hint')
        ->and($warnings[0]['message'])->toBe($expectedMessage)
        ->and($warnings[0]['confidence'])->toBe('medium')
        ->and($warnings[0]['is_ambiguous'])->toBeTrue();
})->with([
    'later partial year after full date' => ['2024', '2023-12-31'],
    'later partial month after full date' => ['2023-06', '2023-05-31'],
    'full date after partial year' => ['2024-01-01', '2023'],
    'full date after partial month' => ['2023-06-01', '2023-05'],
    'march follows a leap-year february' => ['2024-03-01', '2024-02'],
    'partial range end after full date' => ['2011-01-01/2024', '2023-12-31'],
    'date-time after partial year' => ['2024-01-01T00:00:00Z', '2023'],
    'full date after partial later range start' => ['2024-01-01', '2023/2024'],
]);

it('ignores invalid ranges during plausibility checks', function () {
    $warnings = $this->plausibilityService->hint([
        'Collected' => ['2018-07-03/2016-07-03'],
        'Issued' => ['2017-07-03'],
    ]);
    expect($warnings)->toBe([]);
});
