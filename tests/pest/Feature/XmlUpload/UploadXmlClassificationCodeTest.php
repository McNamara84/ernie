<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

test('extracts classificationCode from GCMD subject', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <subjects>
    <subject subjectScheme="NASA/GCMD Earth Science Keywords" valueURI="https://gcmd.earthdata.nasa.gov/kms/concept/4e366444-01ea-4517-9d93-56f55ddf41b7" classificationCode="SCI123">BIODIVERSITY FUNCTIONS</subject>
  </subjects>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('gcmd-classification.xml', $xml);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    $response->assertSessionDataPath('gcmdKeywords.0.scheme', 'Science Keywords');
    $response->assertSessionDataPath('gcmdKeywords.0.classificationCode', 'SCI123');
    $response->assertSessionDataPath('gcmdKeywords.0.text', 'BIODIVERSITY FUNCTIONS');
});

test('extracts unknown scheme subject with classificationCode and no valueURI', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <subjects>
    <subject subjectScheme="ANZSRC Fields of Research" schemeURI="https://www.abs.gov.au/statistics/classifications/australian-and-new-zealand-standard-research-classification-anzsrc" classificationCode="310607">Nanobiotechnology</subject>
  </subjects>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('anzsrc-classification.xml', $xml);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    $response->assertSessionDataCount(1, 'gcmdKeywords');
    $response->assertSessionDataPath('gcmdKeywords.0.scheme', 'ANZSRC Fields of Research');
    $response->assertSessionDataPath('gcmdKeywords.0.classificationCode', '310607');
    $response->assertSessionDataPath('gcmdKeywords.0.text', 'Nanobiotechnology');
    $response->assertSessionDataPath('gcmdKeywords.0.id', '310607');
});

test('extracts unknown scheme subject with classificationCode and valueURI', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <subjects>
    <subject subjectScheme="DDC" schemeURI="https://dewey.info/" valueURI="https://dewey.info/class/550/" classificationCode="550">Geophysics</subject>
  </subjects>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('ddc-classification.xml', $xml);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    $response->assertSessionDataCount(1, 'gcmdKeywords');
    $response->assertSessionDataPath('gcmdKeywords.0.scheme', 'DDC');
    $response->assertSessionDataPath('gcmdKeywords.0.classificationCode', '550');
    $response->assertSessionDataPath('gcmdKeywords.0.id', 'https://dewey.info/class/550/');
    $response->assertSessionDataPath('gcmdKeywords.0.text', 'Geophysics');
});

test('ignores classificationCode on free keywords (subjects without scheme)', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <subjects>
    <subject>climate change</subject>
    <subject subjectScheme="DDC" classificationCode="550">Geophysics</subject>
  </subjects>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('free-keyword.xml', $xml);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    $response->assertSessionDataCount(1, 'freeKeywords');
    $response->assertSessionDataPath('freeKeywords.0', 'climate change');

    // DDC subject with classificationCode goes to gcmdKeywords (controlled)
    $response->assertSessionDataCount(1, 'gcmdKeywords');
    $response->assertSessionDataPath('gcmdKeywords.0.classificationCode', '550');
});

test('GCMD subject without classificationCode has no classificationCode key', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <subjects>
    <subject subjectScheme="NASA/GCMD Earth Science Keywords" valueURI="https://gcmd.earthdata.nasa.gov/kms/concept/4e366444-01ea-4517-9d93-56f55ddf41b7">BIODIVERSITY FUNCTIONS</subject>
  </subjects>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('gcmd-no-classification.xml', $xml);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    $gcmdKeywords = $response->sessionData('gcmdKeywords');
    expect($gcmdKeywords)->toHaveCount(1)
        ->and($gcmdKeywords[0])->not->toHaveKey('classificationCode');
});
