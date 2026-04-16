<?php

declare(strict_types=1);

use App\Enums\CacheKey;
use App\Models\ThesaurusSetting;

covers(ThesaurusSetting::class);

describe('EuroSciVoc type mappings', function (): void {
    beforeEach(function (): void {
        $this->setting = new ThesaurusSetting;
        $this->setting->type = ThesaurusSetting::TYPE_EUROSCIVOC;
    });

    it('has correct type constant value', function (): void {
        expect(ThesaurusSetting::TYPE_EUROSCIVOC)->toBe('euroscivoc');
    });

    it('returns correct file path', function (): void {
        expect($this->setting->getFilePath())->toBe('euroscivoc.json');
    });

    it('returns correct artisan command', function (): void {
        expect($this->setting->getArtisanCommand())->toBe('get-euroscivoc');
    });

    it('returns correct cache key', function (): void {
        expect($this->setting->getCacheKey())->toBe(CacheKey::EUROSCIVOC);
    });

    it('is not GCMD type', function (): void {
        expect($this->setting->isGcmd())->toBeFalse();
    });

    it('is not ARDC type', function (): void {
        expect($this->setting->usesArdcApi())->toBeFalse();
    });

    it('is included in valid types', function (): void {
        expect(ThesaurusSetting::getValidTypes())->toContain(ThesaurusSetting::TYPE_EUROSCIVOC);
    });

    it('throws on getVocabularyType for non-GCMD type', function (): void {
        $this->setting->getVocabularyType();
    })->throws(\InvalidArgumentException::class);
});

describe('CacheKey EUROSCIVOC', function (): void {
    it('has correct key value', function (): void {
        expect(CacheKey::EUROSCIVOC->key())->toBe('vocabularies:euroscivoc');
    });

    it('has correct TTL', function (): void {
        expect(CacheKey::EUROSCIVOC->ttl())->toBe(86400);
    });

    it('is tagged under vocabularies', function (): void {
        expect(CacheKey::EUROSCIVOC->tags())->toContain('vocabularies');
    });

    it('is in vocabulary keys', function (): void {
        expect(CacheKey::vocabularyKeys())->toContain(CacheKey::EUROSCIVOC);
    });
});
