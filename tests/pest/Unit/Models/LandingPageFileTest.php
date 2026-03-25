<?php

declare(strict_types=1);

use App\Models\LandingPage;
use App\Models\LandingPageFile;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

describe('LandingPageFile', function () {
    it('has landingPage BelongsTo relationship', function () {
        $model = new LandingPageFile;
        expect($model->landingPage())->toBeInstanceOf(BelongsTo::class);
    });

    it('can be created and belongs to a landing page', function () {
        $landingPage = LandingPage::factory()->published()->create();

        $file = LandingPageFile::create([
            'landing_page_id' => $landingPage->id,
            'url' => 'https://datapub.gfz.de/download/10.5880/test',
            'position' => 0,
        ]);

        expect($file)->toBeInstanceOf(LandingPageFile::class)
            ->and($file->landing_page_id)->toBe($landingPage->id)
            ->and($file->url)->toBe('https://datapub.gfz.de/download/10.5880/test')
            ->and($file->position)->toBe(0)
            ->and($file->landingPage->id)->toBe($landingPage->id);
    });

    it('casts position to integer', function () {
        $landingPage = LandingPage::factory()->published()->create();

        $file = LandingPageFile::create([
            'landing_page_id' => $landingPage->id,
            'url' => 'https://datapub.gfz.de/download/test',
            'position' => 3,
        ]);

        expect($file->position)->toBeInt();
    });

    it('is deleted when landing page is deleted (cascade)', function () {
        $landingPage = LandingPage::factory()->published()->create();

        LandingPageFile::create([
            'landing_page_id' => $landingPage->id,
            'url' => 'https://datapub.gfz.de/download/test1',
            'position' => 0,
        ]);
        LandingPageFile::create([
            'landing_page_id' => $landingPage->id,
            'url' => 'https://datapub.gfz.de/download/test2',
            'position' => 1,
        ]);

        expect(LandingPageFile::where('landing_page_id', $landingPage->id)->count())->toBe(2);

        $landingPage->delete();

        expect(LandingPageFile::where('landing_page_id', $landingPage->id)->count())->toBe(0);
    });
});

describe('LandingPage files relationship', function () {
    it('has files HasMany relationship', function () {
        $model = new LandingPage;
        expect($model->files())->toBeInstanceOf(HasMany::class);
    });

    it('returns files ordered by position', function () {
        $landingPage = LandingPage::factory()->published()->create();

        LandingPageFile::create([
            'landing_page_id' => $landingPage->id,
            'url' => 'https://datapub.gfz.de/download/second',
            'position' => 2,
        ]);
        LandingPageFile::create([
            'landing_page_id' => $landingPage->id,
            'url' => 'https://datapub.gfz.de/download/first',
            'position' => 0,
        ]);
        LandingPageFile::create([
            'landing_page_id' => $landingPage->id,
            'url' => 'https://datapub.gfz.de/download/middle',
            'position' => 1,
        ]);

        $files = $landingPage->files;

        expect($files)->toHaveCount(3)
            ->and($files[0]->url)->toBe('https://datapub.gfz.de/download/first')
            ->and($files[1]->url)->toBe('https://datapub.gfz.de/download/middle')
            ->and($files[2]->url)->toBe('https://datapub.gfz.de/download/second');
    });
});
