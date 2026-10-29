<?php

declare(strict_types=1);

namespace App\Services\Igsn\Export;

use App\Models\Resource;
use DOMDocument;
use RuntimeException;
use XSLTProcessor;

final class IgsnCoreXmlExporter
{
    public function __construct(
        private readonly IgsnCoreSourceBuilder $sourceBuilder,
        private readonly IgsnCoreSchemaResolver $schemas,
    ) {}

    public function export(Resource $resource): IgsnCoreExportResult
    {
        $source = $this->sourceBuilder->build($resource);
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $stylesheet = new DOMDocument;
            if (! $stylesheet->load((string) config('igsn_core.stylesheet'), LIBXML_NONET) || $stylesheet->doctype !== null) {
                throw new RuntimeException('The IGSN Core stylesheet is unavailable.');
            }
            $processor = new XSLTProcessor;
            $processor->setSecurityPrefs(XSL_SECPREF_READ_FILE | XSL_SECPREF_WRITE_FILE | XSL_SECPREF_CREATE_DIRECTORY | XSL_SECPREF_READ_NETWORK | XSL_SECPREF_WRITE_NETWORK);
            if (! $processor->importStylesheet($stylesheet)) {
                throw new RuntimeException('The IGSN Core stylesheet could not be compiled.');
            }
            $processor->setParameter('', 'schema-url', $this->schemas->url());
            $xml = $processor->transformToXML($source->document);
            if (! is_string($xml) || $xml === '') {
                throw new RuntimeException('The IGSN Core transformation failed.');
            }

            return new IgsnCoreExportResult($xml, $source->omissions);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
