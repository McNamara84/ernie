<?php

declare(strict_types=1);

namespace App\Services\Igsn\Export;

use DOMDocument;
use LibXMLError;

final class IgsnCoreXmlValidator
{
    public function __construct(private readonly IgsnCoreSchemaResolver $schemas) {}

    public function validate(string $xml): IgsnCoreValidationResult
    {
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            return new IgsnCoreValidationResult(['Document type and entity declarations are not permitted.']);
        }
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $document = new DOMDocument;
            if (! $document->loadXML($xml, LIBXML_NONET)) {
                return $this->failure();
            }
            if ($document->doctype !== null || $document->documentElement?->namespaceURI !== IgsnCoreSchemaResolver::NAMESPACE || $document->documentElement->localName !== 'resource') {
                return new IgsnCoreValidationResult(['The document must be an IGSN Core resource.']);
            }

            $valid = $this->schemas->withLocalSchemas(
                static fn (string $schema): bool => @$document->schemaValidate($schema, LIBXML_NONET),
            );

            return $valid ? new IgsnCoreValidationResult : $this->failure();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function failure(): IgsnCoreValidationResult
    {
        $errors = array_map(static fn (LibXMLError $error): string => trim($error->message), libxml_get_errors());

        return new IgsnCoreValidationResult($errors === [] ? ['IGSN Core XML validation failed.'] : array_values(array_unique($errors)));
    }
}
