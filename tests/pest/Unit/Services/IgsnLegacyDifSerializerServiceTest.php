<?php

declare(strict_types=1);

use App\Services\Igsn\IgsnLegacyDifSerializerService;

covers(IgsnLegacyDifSerializerService::class);

it('serializes unprefixed and namespace-prefixed attribute names without leading colons', function (): void {
    $serialized = (new IgsnLegacyDifSerializerService)->serialize(<<<'XML'
    <resource xmlns="urn:igsn" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        schemaVersion="1.3" xsi:schemaLocation="urn:igsn igsn.xsd">
      <sample publishdate="2020-01-02">
        <method methodScheme="XRF" xsi:type="legacy">Method A</method>
      </sample>
    </resource>
    XML);

    expect($serialized)->not->toBeNull();

    $fields = collect($serialized['fields'])->keyBy('path');
    expect($fields)->toHaveKeys([
        'resource/@schemaVersion',
        'resource/@xsi:schemaLocation',
        'resource/sample/@publishdate',
        'resource/sample/method',
    ])->and($fields['resource/sample/method']['attributes'])->toBe([
        'methodScheme' => 'XRF',
        'xsi:type' => 'legacy',
    ])->and($fields->keys()->contains(
        static fn (string $path): bool => str_contains($path, '@:'),
    ))->toBeFalse()
        ->and(collect($serialized['fields'])->flatMap(
            static fn (array $field): array => array_keys($field['attributes']),
        )->contains(
            static fn (string $attribute): bool => str_starts_with($attribute, ':'),
        ))->toBeFalse();
});
