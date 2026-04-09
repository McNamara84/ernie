<?php

declare(strict_types=1);

namespace App\Services\OaiPmh;

use DOMDocument;
use DOMElement;

/**
 * Builds well-formed OAI-PMH 2.0 XML responses.
 *
 * @see http://www.openarchives.org/OAI/openarchivesprotocol.html
 */
class OaiPmhXmlResponseBuilder
{
    private const OAI_NAMESPACE = 'http://www.openarchives.org/OAI/2.0/';

    private const XSI_NAMESPACE = 'http://www.w3.org/2001/XMLSchema-instance';

    private const SCHEMA_LOCATION = 'http://www.openarchives.org/OAI/2.0/ http://www.openarchives.org/OAI/2.0/OAI-PMH.xsd';

    private const OAI_IDENTIFIER_NAMESPACE = 'http://www.openarchives.org/OAI/2.0/oai-identifier';

    private const OAI_IDENTIFIER_SCHEMA = 'http://www.openarchives.org/OAI/2.0/oai-identifier http://www.openarchives.org/OAI/2.0/oai-identifier.xsd';

    private const DC_NAMESPACE = 'http://purl.org/dc/elements/1.1/';

    private const OAI_DC_NAMESPACE = 'http://www.openarchives.org/OAI/2.0/oai_dc/';

    private const OAI_DC_SCHEMA = 'http://www.openarchives.org/OAI/2.0/oai_dc/ http://www.openarchives.org/OAI/2.0/oai_dc.xsd';

    private DOMDocument $dom;

    private DOMElement $root;

    /**
     * Create a new XML response envelope with the standard OAI-PMH wrapper.
     *
     * @param  string  $verb  The OAI-PMH verb being responded to
     * @param  array<string, string>  $requestAttributes  Attributes for the <request> element
     */
    public function createEnvelope(string $verb, array $requestAttributes = []): self
    {
        $this->dom = new DOMDocument('1.0', 'UTF-8');
        $this->dom->formatOutput = true;

        $this->root = $this->dom->createElementNS(self::OAI_NAMESPACE, 'OAI-PMH');
        $this->root->setAttributeNS(
            'http://www.w3.org/2000/xmlns/',
            'xmlns:xsi',
            self::XSI_NAMESPACE,
        );
        $this->root->setAttributeNS(
            self::XSI_NAMESPACE,
            'xsi:schemaLocation',
            self::SCHEMA_LOCATION,
        );
        $this->dom->appendChild($this->root);

        // <responseDate>
        $this->appendTextElement($this->root, 'responseDate', gmdate('Y-m-d\TH:i:s\Z'));

        // <request>
        $requestEl = $this->appendTextElement(
            $this->root,
            'request',
            (string) config('oaipmh.base_url'),
        );
        if ($verb !== '') {
            $requestEl->setAttribute('verb', $verb);
        }
        foreach ($requestAttributes as $name => $value) {
            $requestEl->setAttribute($name, $value);
        }

        return $this;
    }

    /**
     * Build and return the Identify response content.
     */
    public function addIdentifyContent(string $earliestDatestamp, string $sampleIdentifier): self
    {
        $identify = $this->dom->createElementNS(self::OAI_NAMESPACE, 'Identify');
        $this->root->appendChild($identify);

        $this->appendTextElement($identify, 'repositoryName', (string) config('oaipmh.repository_name'));
        $this->appendTextElement($identify, 'baseURL', (string) config('oaipmh.base_url'));
        $this->appendTextElement($identify, 'protocolVersion', (string) config('oaipmh.protocol_version'));
        $this->appendTextElement($identify, 'adminEmail', (string) config('oaipmh.admin_email'));
        $this->appendTextElement($identify, 'earliestDatestamp', $earliestDatestamp);
        $this->appendTextElement($identify, 'deletedRecord', (string) config('oaipmh.deleted_record'));
        $this->appendTextElement($identify, 'granularity', (string) config('oaipmh.granularity'));

        // oai-identifier description
        $description = $this->dom->createElementNS(self::OAI_NAMESPACE, 'description');
        $identify->appendChild($description);

        $oaiId = $this->dom->createElementNS(self::OAI_IDENTIFIER_NAMESPACE, 'oai-identifier');
        $oaiId->setAttributeNS(
            'http://www.w3.org/2000/xmlns/',
            'xmlns:xsi',
            self::XSI_NAMESPACE,
        );
        $oaiId->setAttributeNS(
            self::XSI_NAMESPACE,
            'xsi:schemaLocation',
            self::OAI_IDENTIFIER_SCHEMA,
        );
        $description->appendChild($oaiId);

        $this->appendTextElement($oaiId, 'scheme', 'oai', self::OAI_IDENTIFIER_NAMESPACE);
        $this->appendTextElement($oaiId, 'repositoryIdentifier', 'ernie.gfz.de', self::OAI_IDENTIFIER_NAMESPACE);
        $this->appendTextElement($oaiId, 'delimiter', ':', self::OAI_IDENTIFIER_NAMESPACE);
        $this->appendTextElement($oaiId, 'sampleIdentifier', $sampleIdentifier, self::OAI_IDENTIFIER_NAMESPACE);

        return $this;
    }

    /**
     * Add ListMetadataFormats content.
     *
     * @param  array<string, array{schema: string, namespace: string}>  $formats
     */
    public function addListMetadataFormatsContent(array $formats): self
    {
        $listFormats = $this->dom->createElementNS(self::OAI_NAMESPACE, 'ListMetadataFormats');
        $this->root->appendChild($listFormats);

        foreach ($formats as $prefix => $format) {
            $mf = $this->dom->createElementNS(self::OAI_NAMESPACE, 'metadataFormat');
            $listFormats->appendChild($mf);

            $this->appendTextElement($mf, 'metadataPrefix', $prefix);
            $this->appendTextElement($mf, 'schema', $format['schema']);
            $this->appendTextElement($mf, 'metadataNamespace', $format['namespace']);
        }

        return $this;
    }

    /**
     * Add ListSets content.
     *
     * @param  array<int, array{spec: string, name: string}>  $sets
     */
    public function addListSetsContent(array $sets): self
    {
        $listSets = $this->dom->createElementNS(self::OAI_NAMESPACE, 'ListSets');
        $this->root->appendChild($listSets);

        foreach ($sets as $set) {
            $setEl = $this->dom->createElementNS(self::OAI_NAMESPACE, 'set');
            $listSets->appendChild($setEl);

            $this->appendTextElement($setEl, 'setSpec', $set['spec']);
            $this->appendTextElement($setEl, 'setName', $set['name']);
        }

        return $this;
    }

    /**
     * Begin a ListIdentifiers container.
     */
    public function beginListIdentifiers(): DOMElement
    {
        $el = $this->dom->createElementNS(self::OAI_NAMESPACE, 'ListIdentifiers');
        $this->root->appendChild($el);

        return $el;
    }

    /**
     * Begin a ListRecords container.
     */
    public function beginListRecords(): DOMElement
    {
        $el = $this->dom->createElementNS(self::OAI_NAMESPACE, 'ListRecords');
        $this->root->appendChild($el);

        return $el;
    }

    /**
     * Begin a GetRecord container.
     */
    public function beginGetRecord(): DOMElement
    {
        $el = $this->dom->createElementNS(self::OAI_NAMESPACE, 'GetRecord');
        $this->root->appendChild($el);

        return $el;
    }

    /**
     * Add a record header element.
     *
     * @param  list<string>  $setSpecs
     */
    public function addHeader(
        DOMElement $parent,
        string $identifier,
        string $datestamp,
        array $setSpecs = [],
        bool $deleted = false,
    ): DOMElement {
        $header = $this->dom->createElementNS(self::OAI_NAMESPACE, 'header');
        if ($deleted) {
            $header->setAttribute('status', 'deleted');
        }
        $parent->appendChild($header);

        $this->appendTextElement($header, 'identifier', $identifier);
        $this->appendTextElement($header, 'datestamp', $datestamp);

        foreach ($setSpecs as $spec) {
            $this->appendTextElement($header, 'setSpec', $spec);
        }

        return $header;
    }

    /**
     * Add a full record element (header + metadata).
     *
     * @param  list<string>  $setSpecs
     */
    public function addRecord(
        DOMElement $parent,
        string $identifier,
        string $datestamp,
        array $setSpecs,
        string $metadataXml,
    ): void {
        $record = $this->dom->createElementNS(self::OAI_NAMESPACE, 'record');
        $parent->appendChild($record);

        $this->addHeader($record, $identifier, $datestamp, $setSpecs);

        $metadata = $this->dom->createElementNS(self::OAI_NAMESPACE, 'metadata');
        $record->appendChild($metadata);

        // Import metadata XML fragment
        $metadataDoc = new DOMDocument('1.0', 'UTF-8');
        $metadataDoc->loadXML($metadataXml);

        if ($metadataDoc->documentElement !== null) {
            $imported = $this->dom->importNode($metadataDoc->documentElement, true);
            $metadata->appendChild($imported);
        }
    }

    /**
     * Add a deleted record (header only, no metadata).
     *
     * @param  list<string>  $setSpecs
     */
    public function addDeletedRecord(
        DOMElement $parent,
        string $identifier,
        string $datestamp,
        array $setSpecs = [],
    ): void {
        $record = $this->dom->createElementNS(self::OAI_NAMESPACE, 'record');
        $parent->appendChild($record);

        $this->addHeader($record, $identifier, $datestamp, $setSpecs, deleted: true);
    }

    /**
     * Add a resumption token element.
     */
    public function addResumptionToken(
        DOMElement $parent,
        ?string $token,
        int $completeListSize,
        int $cursor,
        ?string $expirationDate = null,
    ): void {
        $el = $this->dom->createElementNS(self::OAI_NAMESPACE, 'resumptionToken');
        if ($token !== null) {
            $el->textContent = $token;
        }
        $el->setAttribute('completeListSize', (string) $completeListSize);
        $el->setAttribute('cursor', (string) $cursor);
        if ($expirationDate !== null) {
            $el->setAttribute('expirationDate', $expirationDate);
        }
        $parent->appendChild($el);
    }

    /**
     * Add an OAI-PMH error element.
     */
    public function addError(string $code, string $message): self
    {
        $error = $this->dom->createElementNS(self::OAI_NAMESPACE, 'error');
        $error->setAttribute('code', $code);
        $error->textContent = $message;
        $this->root->appendChild($error);

        return $this;
    }

    /**
     * Build Dublin Core metadata XML for a resource.
     *
     * @param  array<string, list<string>>  $dcElements  DC elements mapped as element_name => values
     */
    public function buildDublinCoreXml(array $dcElements): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $dcRoot = $doc->createElementNS(self::OAI_DC_NAMESPACE, 'oai_dc:dc');
        $dcRoot->setAttributeNS(
            'http://www.w3.org/2000/xmlns/',
            'xmlns:dc',
            self::DC_NAMESPACE,
        );
        $dcRoot->setAttributeNS(
            'http://www.w3.org/2000/xmlns/',
            'xmlns:xsi',
            self::XSI_NAMESPACE,
        );
        $dcRoot->setAttributeNS(
            self::XSI_NAMESPACE,
            'xsi:schemaLocation',
            self::OAI_DC_SCHEMA,
        );
        $doc->appendChild($dcRoot);

        foreach ($dcElements as $element => $values) {
            foreach ($values as $value) {
                $el = $doc->createElementNS(self::DC_NAMESPACE, "dc:{$element}");
                $el->textContent = $value;
                $dcRoot->appendChild($el);
            }
        }

        return $doc->saveXML($doc->documentElement) ?: '';
    }

    /**
     * Render the final XML string.
     */
    public function toXml(): string
    {
        return $this->dom->saveXML() ?: '';
    }

    /**
     * Append a namespaced text element to a parent.
     *
     * Defaults to the OAI-PMH namespace so child elements inherit the root
     * namespace declaration instead of producing an empty xmlns="".
     */
    private function appendTextElement(DOMElement $parent, string $name, string $value, string $namespace = self::OAI_NAMESPACE): DOMElement
    {
        $el = $this->dom->createElementNS($namespace, $name);
        $el->textContent = $value;
        $parent->appendChild($el);

        return $el;
    }
}
