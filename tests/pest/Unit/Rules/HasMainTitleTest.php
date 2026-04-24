<?php

declare(strict_types=1);

use App\Rules\HasMainTitle;

covers(HasMainTitle::class);

function runRule(mixed $value): ?string
{
    $error = null;
    (new HasMainTitle())->validate('titles', $value, function (string $msg) use (&$error) {
        $error = $msg;
    });

    return $error;
}

it('passes when a non-empty MainTitle is present (snake_case key)', function () {
    expect(runRule([['title' => 'Hello', 'title_type' => 'MainTitle']]))->toBeNull();
});

it('passes when a MainTitle is present (camelCase key)', function () {
    expect(runRule([['title' => 'Hello', 'titleType' => 'MainTitle']]))->toBeNull();
});

it('fails when no MainTitle is present', function () {
    expect(runRule([['title' => 'Hello', 'title_type' => 'AlternativeTitle']]))
        ->toContain('MainTitle');
});

it('fails when the MainTitle text is empty', function () {
    expect(runRule([['title' => '   ', 'title_type' => 'MainTitle']]))
        ->toContain('MainTitle');
});

it('fails when value is not an array', function () {
    expect(runRule('not-an-array'))->toContain('array');
});

it('fails when array is empty', function () {
    expect(runRule([]))->toContain('MainTitle');
});
