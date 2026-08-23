<?php

declare(strict_types=1);

use App\Support\LanguageTag;

covers(LanguageTag::class);

it('accepts well-formed BCP 47 language tags', function (string $tag): void {
    expect(LanguageTag::isValid($tag))->toBeTrue();
})->with([
    'simple language' => 'en',
    'language and region' => 'en-CA',
    'language script and region' => 'zh-Hans-CN',
    'numeric region' => 'es-419',
    'variants' => 'sl-rozaj-biske',
    'extension' => 'en-US-u-ca-gregory',
    'private use suffix' => 'de-CH-x-phonebk',
    'private use tag' => 'x-repo',
    'grandfathered tag' => 'i-klingon',
]);

it('rejects malformed BCP 47 language-tag structures', function (string $tag): void {
    expect(LanguageTag::isValid($tag))->toBeFalse();
})->with([
    'empty' => '',
    'one-letter language' => 'e',
    'short numeric region' => 'en-12',
    'empty subtag' => 'en--US',
    'extension without value' => 'en-a',
    'extension without value after region' => 'en-US-a',
    'private use without value' => 'en-x',
    'bare private use singleton' => 'x',
    'duplicate extension singleton' => 'en-u-ca-gregory-u-nu-latn',
    'duplicate variant' => 'sl-rozaj-rozaj',
    'oversized subtag' => 'en-abcdefghi',
]);

it('normalizes tags before returning valid imported values', function (): void {
    expect(LanguageTag::validOrNull(' EN_ca '))->toBe('en-ca')
        ->and(LanguageTag::validOrNull('en-a'))->toBeNull()
        ->and(LanguageTag::primarySubtag(' EN_ca '))->toBe('en');
});
