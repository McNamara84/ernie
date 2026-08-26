<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('adds nullable labels for primary and imported landing-page downloads', function () {
    expect(Schema::hasColumn('landing_pages', 'primary_download_label'))->toBeTrue()
        ->and(Schema::hasColumn('landing_page_files', 'label'))->toBeTrue();
});
