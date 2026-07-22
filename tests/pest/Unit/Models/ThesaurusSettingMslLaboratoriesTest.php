<?php

declare(strict_types=1);

use App\Enums\CacheKey;
use App\Models\ThesaurusSetting;

covers(ThesaurusSetting::class, CacheKey::class);

beforeEach(function (): void {
    $this->setting = new ThesaurusSetting([
        'type' => ThesaurusSetting::TYPE_MSL_LABORATORIES,
    ]);
});

it('maps the MSL Laboratories thesaurus to its file, command and cache key', function (): void {
    expect(ThesaurusSetting::TYPE_MSL_LABORATORIES)->toBe('msl_laboratories')
        ->and($this->setting->getFilePath())->toBe('msl-laboratories.json')
        ->and($this->setting->getArtisanCommand())->toBe('get-msl-laboratories')
        ->and($this->setting->getCacheKey())->toBe(CacheKey::MSL_LABORATORIES);
});

it('includes MSL Laboratories in centralized definitions and valid types', function (): void {
    expect(ThesaurusSetting::definitions())
        ->toHaveKey(ThesaurusSetting::TYPE_MSL_LABORATORIES, 'MSL Laboratories')
        ->and(ThesaurusSetting::getValidTypes())
        ->toContain(ThesaurusSetting::TYPE_MSL_LABORATORIES);
});

it('does not expose manual version editing or unrelated source APIs', function (): void {
    expect($this->setting->supportsVersioning())->toBeFalse()
        ->and($this->setting->isGcmd())->toBeFalse()
        ->and($this->setting->usesArdcApi())->toBeFalse();
});

it('configures the cache key as a 24-hour vocabulary cache', function (): void {
    expect(CacheKey::MSL_LABORATORIES->value)->toBe('vocabularies:msl:laboratories')
        ->and(CacheKey::MSL_LABORATORIES->ttl())->toBe(86400)
        ->and(CacheKey::MSL_LABORATORIES->tags())->toContain('vocabularies')
        ->and(CacheKey::vocabularyKeys())->toContain(CacheKey::MSL_LABORATORIES);
});
