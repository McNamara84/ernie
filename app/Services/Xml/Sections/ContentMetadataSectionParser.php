<?php

declare(strict_types=1);

namespace App\Services\Xml\Sections;

use App\Services\SizeFormat\ContentMetadataImportService;
use Saloon\XmlWrangler\XmlReader;

final readonly class ContentMetadataSectionParser
{
    public function __construct(private ContentMetadataImportService $importer) {}

    /** @return array{formats: list<array<string, mixed>>, sizes: list<array<string, mixed>>} */
    public function parse(XmlReader $reader): array
    {
        $values = [];
        foreach (['formats' => 'format', 'sizes' => 'size'] as $group => $element) {
            $values[$group] = [];
            foreach ($reader->xpathElement('//*[local-name()="resource"]/*[local-name()="'.$group.'"]/*[local-name()="'.$element.'"]')->get() as $node) {
                $values[$group][] = $node->getContent();
            }
        }

        return ['formats' => $this->importer->formats($values['formats']), 'sizes' => $this->importer->sizes($values['sizes'])];
    }
}
