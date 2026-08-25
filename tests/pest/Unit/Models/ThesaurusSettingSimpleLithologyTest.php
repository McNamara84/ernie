<?php

declare(strict_types=1);

use App\Enums\CacheKey;
use App\Models\ThesaurusSetting;

covers(ThesaurusSetting::class, CacheKey::class);

it('provides all CGI Simple Lithology mappings and keeps it disabled by default', function (): void {
    $setting = new ThesaurusSetting(['type' => ThesaurusSetting::TYPE_SIMPLE_LITHOLOGY]);

    expect(ThesaurusSetting::TYPE_SIMPLE_LITHOLOGY)->toBe('simple_lithology')
        ->and(ThesaurusSetting::definitions())
        ->toHaveKey(ThesaurusSetting::TYPE_SIMPLE_LITHOLOGY, 'CGI Simple Lithology')
        ->and(ThesaurusSetting::isEnabledByDefault(ThesaurusSetting::TYPE_SIMPLE_LITHOLOGY))->toBeFalse()
        ->and($setting->getFilePath())->toBe('cgi-simple-lithology.json')
        ->and($setting->getArtisanCommand())->toBe('get-cgi-simple-lithology')
        ->and($setting->getCacheKey())->toBe(CacheKey::CGI_SIMPLE_LITHOLOGY)
        ->and($setting->isGcmd())->toBeFalse()
        ->and($setting->usesArdcApi())->toBeFalse();
});

it('configures the CGI Simple Lithology cache as a daily vocabulary cache', function (): void {
    expect(CacheKey::CGI_SIMPLE_LITHOLOGY->key())->toBe('vocabularies:cgi:simple_lithology')
        ->and(CacheKey::CGI_SIMPLE_LITHOLOGY->ttl())->toBe(86400)
        ->and(CacheKey::CGI_SIMPLE_LITHOLOGY->tags())->toContain('vocabularies')
        ->and(CacheKey::vocabularyKeys())->toContain(CacheKey::CGI_SIMPLE_LITHOLOGY);
});
