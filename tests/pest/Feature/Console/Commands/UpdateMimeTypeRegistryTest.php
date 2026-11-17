<?php

declare(strict_types=1);

use App\Services\SizeFormat\MimeTypeRegistry;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->registryDirectory = storage_path('framework/testing/mime-registry-'.bin2hex(random_bytes(6)));
    File::ensureDirectoryExists($this->registryDirectory.'/data');
    $this->registryFile = $this->registryDirectory.'/data/iana-media-types.json';
    app()->instance(MimeTypeRegistry::class, new MimeTypeRegistry($this->registryFile));
    file_put_contents($this->registryFile, '{"previous":true}');
});

afterEach(function () {
    File::deleteDirectory($this->registryDirectory);
});

it('retains the previous registry after malformed, incomplete, or failed downloads', function (string $kind) {
    if ($kind === 'http') {
        Http::fake(['iana.org/*' => Http::response('', 503)]);
    } else {
        Http::fake(['iana.org/*' => Http::response($kind === 'xml' ? '<invalid>' : '<registry xmlns="http://www.iana.org/assignments" id="media-types"><registry id="text"><record><name>csv</name></record></registry></registry>')]);
    }
    $this->artisan('mime-types:update')->assertFailed();
    expect(file_get_contents($this->registryFile))->toBe('{"previous":true}');
})->with(['http', 'xml', 'incomplete']);

it('publishes complete online and offline snapshots with provenance and stable ordering', function (bool $offline) {
    $xml = '<registry xmlns="http://www.iana.org/assignments" id="media-types"><updated>2026-11-10</updated><registry id="application">';
    for ($index = 0; $index < 2000; $index++) {
        $xml .= '<record><name>vnd.test'.$index.'</name></record>';
    }
    $xml .= '</registry>';
    foreach (['audio', 'font', 'haptics', 'image', 'message', 'model', 'multipart', 'text', 'video'] as $category) {
        $xml .= '<registry id="'.$category.'"><record><name>test</name></record></registry>';
    }
    $xml .= '</registry>';
    Http::fake(['iana.org/*' => Http::response($xml)]);
    $options = [];
    if ($offline) {
        $options['--source'] = $this->registryDirectory.'/source.xml';
        file_put_contents($options['--source'], $xml);
    }
    $this->artisan('mime-types:update', $options)->assertSuccessful();
    $catalog = json_decode(file_get_contents($this->registryFile), true, flags: JSON_THROW_ON_ERROR);
    expect($catalog['types'])->toHaveCount(2009)->and($catalog['source_sha256'])->toBe(hash('sha256', $xml))
        ->and($catalog['types'][0])->toBe('application/vnd.test0');
    if ($offline) {
        Http::assertNothingSent();
    }
})->with([true, false]);
