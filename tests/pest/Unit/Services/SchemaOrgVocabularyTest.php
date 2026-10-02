<?php

declare(strict_types=1);

use Tests\Fixtures\SchemaOrgVocabulary;

it('detects the original Dataset-only property error and invalid literal object types', function (): void {
    expect(SchemaOrgVocabulary::violations(['@type' => 'ImageObject', 'distribution' => []]))
        ->toBe(['ImageObject does not support distribution'])
        ->and(SchemaOrgVocabulary::violations(['@type' => 'Text', 'name' => 'A text']))
        ->toContain('Unknown object type: Text');
});

it('checks nested typed nodes and accepts inherited domains and both software types', function (): void {
    expect(SchemaOrgVocabulary::violations([
        '@type' => ['SoftwareSourceCode', 'SoftwareApplication'],
        'name' => 'Code',
        'downloadUrl' => 'https://example.org/code.zip',
        'codeRepository' => 'https://example.org/repository',
        'subjectOf' => [['@type' => 'Thing', 'creator' => []]],
    ]))->toBe(['Thing does not support creator']);
});
