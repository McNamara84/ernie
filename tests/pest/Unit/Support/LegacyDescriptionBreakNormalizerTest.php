<?php

declare(strict_types=1);

use App\Support\LegacyDescriptionBreakNormalizer;

covers(LegacyDescriptionBreakNormalizer::class);

it('halves consecutive legacy break tags pairwise', function (string $input, string $expected, int $replacements): void {
    expect((new LegacyDescriptionBreakNormalizer)->normalizeHtml($input))->toBe([
        'value' => $expected,
        'replacements' => $replacements,
    ]);
})->with([
    'two adjacent tags' => ['A<br><br>B', 'A<br>B', 1],
    'two tags separated by a space' => ['A<br> <br>B', 'A<br>B', 1],
    'mixed variants and casing' => ['A<BR/><br /><Br>B', 'A<br><br>B', 1],
    'four tags retain two' => ['A<br><br><br><br>B', 'A<br><br>B', 2],
    'five tags retain three' => ['A<br><br><br><br><br>B', 'A<br><br><br>B', 2],
    'separate runs' => ['A<br><br>B<br/> <br />C', 'A<br>B<br>C', 2],
]);

it('halves canonical newline runs pairwise', function (string $input, string $expected, int $replacements): void {
    expect((new LegacyDescriptionBreakNormalizer)->normalizePlainText($input))->toBe([
        'value' => $expected,
        'replacements' => $replacements,
    ]);
})->with([
    'two LF newlines' => ["A\n\nB", "A\nB", 1],
    'three LF newlines' => ["A\n\n\nB", "A\n\nB", 1],
    'four LF newlines' => ["A\n\n\n\nB", "A\n\nB", 2],
    'CRLF newlines' => ["A\r\n\r\nB", "A\r\nB", 1],
    'CR newlines with whitespace' => ["A\r \t\rB", "A\rB", 1],
]);

it('prefers tag normalization for stored html values without changing unrelated newlines', function (): void {
    $input = "First\n\nA<br> \n <br>B";

    expect((new LegacyDescriptionBreakNormalizer)->normalizeStoredValue($input))->toBe([
        'value' => "First\n\nA<br>B",
        'replacements' => 1,
    ]);
});

it('leaves single breaks and unrelated html unchanged', function (): void {
    $input = "<p>First</p><br><strong>Second</strong>\nThird";

    expect((new LegacyDescriptionBreakNormalizer)->normalizeStoredValue($input))->toBe([
        'value' => $input,
        'replacements' => 0,
    ]);
});
