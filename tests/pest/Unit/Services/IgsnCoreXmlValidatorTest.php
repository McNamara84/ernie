<?php

declare(strict_types=1);

use App\Services\Igsn\Export\IgsnCoreSchemaResolver;
use App\Services\Igsn\Export\IgsnCoreValidationResult;
use App\Services\Igsn\Export\IgsnCoreXmlValidator;

covers(IgsnCoreXmlValidator::class, IgsnCoreSchemaResolver::class, IgsnCoreValidationResult::class);

test('changes only the Core import location and retains exact upstream dependency bytes', function () {
    $directory = app(IgsnCoreSchemaResolver::class)->directory('preview-1');
    $manifest = json_decode(file_get_contents($directory.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    $originalDirectory = resource_path('data/scheme/igsn-core/2.0/'.$manifest['commit'].'/upstream');
    foreach ($manifest['files'] as $file) {
        $original = file_get_contents($originalDirectory.'/'.$file['upstream_path']);
        expect(hash('sha256', $original))->toBe($file['upstream_sha256']);
        if ($file['path'] === 'core.xsd') {
            $original = str_replace('X:\\IGSN\\DataCiteSchema4-7\\kernel-4.7\\metadata.xsd', 'DataCite4.7/metadata.xsd', $original, $count);
            expect($count)->toBe(1);
        }
        expect(file_get_contents($directory.'/'.$file['path']))->toBe($original);
    }
});

test('validates the portable Core revision with local dependencies and restores the loader', function () {
    $previous = libxml_get_external_entity_loader();
    $reject = static fn () => null;
    libxml_set_external_entity_loader($reject);
    try {
        $result = app(IgsnCoreXmlValidator::class)->validate(file_get_contents(base_path('tests/pest/Fixtures/igsn-core/minimal.xml')));
        expect($result->errors)->toBe([])
            ->and($result->isValid())->toBeTrue()
            ->and(libxml_get_external_entity_loader())->toBe($reject);
    } finally {
        libxml_set_external_entity_loader($previous);
    }
});

test('rejects unqualified Core attributes', function () {
    $xml = file_get_contents(base_path('tests/pest/Fixtures/igsn-core/minimal.xml'));
    $result = app(IgsnCoreXmlValidator::class)->validate(str_replace(' igsn:', ' ', $xml));
    expect($result->isValid())->toBeFalse()->and($result->errors)->not->toBeEmpty();
});

test('validates the complete Core fixture with mixed imported namespaces', function () {
    $result = app(IgsnCoreXmlValidator::class)->validate(file_get_contents(base_path('tests/pest/Fixtures/igsn-core/full.xml')));
    expect($result->errors)->toBe([]);
});

test('rejects malformed XML, other schemas and entity declarations', function (string $xml) {
    expect(app(IgsnCoreXmlValidator::class)->validate($xml)->isValid())->toBeFalse();
})->with([
    '<broken',
    '<resource xmlns="http://datacite.org/schema/kernel-4"/>',
    '<resource xmlns="http://igsn.org/schema/1.3"/>',
    '<other xmlns="urn:FAIR-AIMS:Core:1"/>',
    '<!DOCTYPE resource [<!ENTITY secret SYSTEM "file:///etc/passwd">]><resource>&secret;</resource>',
]);

test('blocks unknown local and network schema references and restores the loader after exceptions', function () {
    $previous = libxml_get_external_entity_loader();
    try {
        app(IgsnCoreSchemaResolver::class)->withLocalSchemas(function () {
            $loader = libxml_get_external_entity_loader();
            expect($loader(null, 'file:///etc/passwd'))->toBeNull()
                ->and($loader(null, 'https://example.org/metadata.xsd'))->toBeNull()
                ->and($loader(null, '../metadata.xsd'))->toBeNull();
            throw new RuntimeException('Deliberate callback failure');
        });
        $this->fail('The callback should throw.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Deliberate callback failure');
    }
    expect(libxml_get_external_entity_loader())->toBe($previous);
});

test('rejects a changed portable artifact before loading it', function () {
    $schemas = app(IgsnCoreSchemaResolver::class);
    $manifest = file_get_contents($schemas->directory().'/manifest.json');
    $directory = sys_get_temp_dir().'/core-integrity-'.bin2hex(random_bytes(8));
    mkdir($directory, 0777, true);
    file_put_contents($directory.'/manifest.json', $manifest);
    file_put_contents($directory.'/'.IgsnCoreSchemaResolver::ENTRYPOINT, '<changed/>');
    config(['igsn_core.revisions.preview-1' => $directory]);
    try {
        expect(fn () => $schemas->publicFile(IgsnCoreSchemaResolver::ENTRYPOINT))->toThrow(RuntimeException::class, 'has changed');
    } finally {
        unlink($directory.'/'.IgsnCoreSchemaResolver::ENTRYPOINT);
        unlink($directory.'/manifest.json');
        rmdir($directory);
    }
});
