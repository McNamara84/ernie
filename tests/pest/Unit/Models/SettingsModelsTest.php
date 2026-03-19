<?php

declare(strict_types=1);

use App\Models\PidSetting;
use App\Models\ThesaurusSetting;

covers(PidSetting::class, ThesaurusSetting::class);

describe('PidSetting model', function () {
    it('has correct fillable attributes', function () {
        $model = new PidSetting;

        expect($model->getFillable())->toContain('type', 'display_name', 'is_active', 'is_elmo_active');
    });

    it('casts is_active to boolean', function () {
        $model = new PidSetting;
        $model->is_active = true;

        expect($model->is_active)->toBeBool();
    });

    it('returns correct file path for pid4inst', function () {
        $model = new PidSetting;
        $model->type = PidSetting::TYPE_PID4INST;

        expect($model->getFilePath())->toBe('pid4inst-instruments.json');
    });

    it('returns correct file path for ror', function () {
        $model = new PidSetting;
        $model->type = PidSetting::TYPE_ROR;

        expect($model->getFilePath())->toBe('ror/ror-affiliations.json');
    });

    it('throws for unknown type in getFilePath', function () {
        $model = new PidSetting;
        $model->type = 'unknown';

        $model->getFilePath();
    })->throws(\InvalidArgumentException::class);

    it('returns correct artisan command for pid4inst', function () {
        $model = new PidSetting;
        $model->type = PidSetting::TYPE_PID4INST;

        expect($model->getArtisanCommand())->toBe('get-pid4inst-instruments');
    });

    it('returns correct artisan command for ror', function () {
        $model = new PidSetting;
        $model->type = PidSetting::TYPE_ROR;

        expect($model->getArtisanCommand())->toBe('get-ror-ids');
    });

    it('throws for unknown type in getArtisanCommand', function () {
        $model = new PidSetting;
        $model->type = 'unknown';

        $model->getArtisanCommand();
    })->throws(\InvalidArgumentException::class);

    it('returns valid types', function () {
        $types = PidSetting::getValidTypes();

        expect($types)->toContain(PidSetting::TYPE_PID4INST)
            ->toContain(PidSetting::TYPE_ROR);
    });

    it('has correct type constants', function () {
        expect(PidSetting::TYPE_PID4INST)->toBe('pid4inst');
        expect(PidSetting::TYPE_ROR)->toBe('ror');
    });
});

describe('ThesaurusSetting model', function () {
    it('has correct fillable attributes', function () {
        $model = new ThesaurusSetting;

        expect($model->getFillable())->toContain('type', 'display_name', 'is_active', 'is_elmo_active');
    });

    it('casts is_active to boolean', function () {
        $model = new ThesaurusSetting;
        $model->is_active = true;

        expect($model->is_active)->toBeBool();
    });

    it('returns correct file path for each type', function () {
        $expectations = [
            ThesaurusSetting::TYPE_SCIENCE_KEYWORDS => 'gcmd-science-keywords.json',
            ThesaurusSetting::TYPE_PLATFORMS => 'gcmd-platforms.json',
            ThesaurusSetting::TYPE_INSTRUMENTS => 'gcmd-instruments.json',
            ThesaurusSetting::TYPE_CHRONOSTRAT => 'chronostrat-timescale.json',
            ThesaurusSetting::TYPE_GEMET => 'gemet-thesaurus.json',
        ];

        foreach ($expectations as $type => $path) {
            $model = new ThesaurusSetting;
            $model->type = $type;

            expect($model->getFilePath())->toBe($path);
        }
    });

    it('throws for unknown type in getFilePath', function () {
        $model = new ThesaurusSetting;
        $model->type = 'unknown';

        $model->getFilePath();
    })->throws(\InvalidArgumentException::class);

    it('returns correct artisan command for each type', function () {
        $expectations = [
            ThesaurusSetting::TYPE_SCIENCE_KEYWORDS => 'get-gcmd-science-keywords',
            ThesaurusSetting::TYPE_PLATFORMS => 'get-gcmd-platforms',
            ThesaurusSetting::TYPE_INSTRUMENTS => 'get-gcmd-instruments',
            ThesaurusSetting::TYPE_CHRONOSTRAT => 'get-chronostrat-timescale',
            ThesaurusSetting::TYPE_GEMET => 'get-gemet-thesaurus',
        ];

        foreach ($expectations as $type => $command) {
            $model = new ThesaurusSetting;
            $model->type = $type;

            expect($model->getArtisanCommand())->toBe($command);
        }
    });

    it('returns correct vocabulary type for GCMD thesauri', function () {
        $expectations = [
            ThesaurusSetting::TYPE_SCIENCE_KEYWORDS => 'sciencekeywords',
            ThesaurusSetting::TYPE_PLATFORMS => 'platforms',
            ThesaurusSetting::TYPE_INSTRUMENTS => 'instruments',
        ];

        foreach ($expectations as $type => $vocabularyType) {
            $model = new ThesaurusSetting;
            $model->type = $type;

            expect($model->getVocabularyType())->toBe($vocabularyType);
        }
    });

    it('throws for non-GCMD type in getVocabularyType', function () {
        $model = new ThesaurusSetting;
        $model->type = ThesaurusSetting::TYPE_CHRONOSTRAT;

        $model->getVocabularyType();
    })->throws(\InvalidArgumentException::class);

    it('correctly identifies GCMD thesauri', function () {
        $gcmdTypes = [
            ThesaurusSetting::TYPE_SCIENCE_KEYWORDS,
            ThesaurusSetting::TYPE_PLATFORMS,
            ThesaurusSetting::TYPE_INSTRUMENTS,
        ];

        foreach ($gcmdTypes as $type) {
            $model = new ThesaurusSetting;
            $model->type = $type;

            expect($model->isGcmd())->toBeTrue();
        }
    });

    it('correctly identifies non-GCMD thesauri', function () {
        $nonGcmdTypes = [
            ThesaurusSetting::TYPE_CHRONOSTRAT,
            ThesaurusSetting::TYPE_GEMET,
        ];

        foreach ($nonGcmdTypes as $type) {
            $model = new ThesaurusSetting;
            $model->type = $type;

            expect($model->isGcmd())->toBeFalse();
        }
    });

    it('returns valid types', function () {
        $types = ThesaurusSetting::getValidTypes();

        expect($types)->toContain(ThesaurusSetting::TYPE_SCIENCE_KEYWORDS)
            ->toContain(ThesaurusSetting::TYPE_PLATFORMS)
            ->toContain(ThesaurusSetting::TYPE_INSTRUMENTS)
            ->toContain(ThesaurusSetting::TYPE_CHRONOSTRAT)
            ->toContain(ThesaurusSetting::TYPE_GEMET);
    });

    it('has correct type constants', function () {
        expect(ThesaurusSetting::TYPE_SCIENCE_KEYWORDS)->toBe('science_keywords');
        expect(ThesaurusSetting::TYPE_PLATFORMS)->toBe('platforms');
        expect(ThesaurusSetting::TYPE_INSTRUMENTS)->toBe('instruments');
        expect(ThesaurusSetting::TYPE_CHRONOSTRAT)->toBe('chronostratigraphy');
        expect(ThesaurusSetting::TYPE_GEMET)->toBe('gemet');
    });
});
