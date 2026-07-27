<?php

declare(strict_types=1);

use App\Services\DataCite\Mapping\DataCiteDescriptionMappingService;

covers(DataCiteDescriptionMappingService::class);

beforeEach(function (): void {
    $this->mapper = new DataCiteDescriptionMappingService;
});

it('leaves a single-line description unchanged', function (): void {
    expect($this->mapper->toJsonValue('A single line.'))->toBe('A single line.');
});

it('normalizes every supported line ending to a datacite break', function (string $lineEnding): void {
    expect($this->mapper->toJsonValue('First'.$lineEnding.'Second'))
        ->toBe('First<br>Second');
})->with([
    'LF' => "\n",
    'CRLF' => "\r\n",
    'CR' => "\r",
]);

it('retains consecutive leading and trailing line breaks', function (): void {
    $description = "\nFirst\n\nSecond\n";

    expect($this->mapper->segments($description))->toBe(['', 'First', '', 'Second', ''])
        ->and($this->mapper->toJsonValue($description))->toBe('<br>First<br><br>Second<br>');
});

it('preserves unicode and plain-text symbols', function (): void {
    $description = "Temperatur < 5 °C & Schnee\n第二行";

    expect($this->mapper->toJsonValue($description))
        ->toBe('Temperatur < 5 °C & Schnee<br>第二行');
});
