<?php

declare(strict_types=1);

namespace App\Services\DcatAp;

use App\Support\LanguageTag;
use DOMDocument;
use DOMElement;

/** A fresh, ordered RDF/XML graph per export; anonymous nodes need no generated IDs. */
final class RdfDocument
{
    public const NS = [
        'rdf' => 'http://www.w3.org/1999/02/22-rdf-syntax-ns#',
        'rdfs' => 'http://www.w3.org/2000/01/rdf-schema#',
        'dcat' => 'http://www.w3.org/ns/dcat#',
        'dct' => 'http://purl.org/dc/terms/',
        'foaf' => 'http://xmlns.com/foaf/0.1/',
        'vcard' => 'http://www.w3.org/2006/vcard/ns#',
        'skos' => 'http://www.w3.org/2004/02/skos/core#',
        'adms' => 'http://www.w3.org/ns/adms#',
        'locn' => 'http://www.w3.org/ns/locn#',
        'gsp' => 'http://www.opengis.net/ont/geosparql#',
        'xsd' => 'http://www.w3.org/2001/XMLSchema#',
    ];

    public readonly DOMDocument $dom;

    public readonly DOMElement $root;

    public function __construct()
    {
        $this->dom = new DOMDocument('1.0', 'UTF-8');
        $this->dom->formatOutput = true;
        $this->root = $this->dom->createElementNS(self::NS['rdf'], 'rdf:RDF');
        $this->dom->appendChild($this->root);
        foreach (self::NS as $prefix => $namespace) {
            $this->root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:'.$prefix, $namespace);
        }
        $this->root->setAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'xsi:schemaLocation', self::NS['rdf'].' '.url('/schemas/oai-pmh/dcat-ap/3.0.1/rdf-envelope.xsd'));
    }

    public function element(DOMElement $parent, string $name): DOMElement
    {
        $element = $this->dom->createElementNS(self::NS[explode(':', $name)[0]], $name);
        $parent->appendChild($element);

        return $element;
    }

    public function node(DOMElement $parent, string $class, ?string $uri = null): DOMElement
    {
        $node = $this->element($parent, 'rdf:Description');
        if ($uri !== null) {
            $node->setAttributeNS(self::NS['rdf'], 'rdf:about', $uri);
        }
        [$prefix, $local] = explode(':', $class, 2);
        $this->iri($node, 'rdf:type', self::NS[$prefix].$local);

        return $node;
    }

    public function object(DOMElement $parent, string $property, string $class, ?string $uri = null): DOMElement
    {
        return $this->node($this->element($parent, $property), $class, $uri);
    }

    public function literal(DOMElement $parent, string $property, ?string $value, ?string $language = null, ?string $datatype = null): void
    {
        if ($value === null || trim($value) === '') {
            return;
        }
        $element = $this->element($parent, $property);
        $element->appendChild($this->dom->createTextNode($value));
        if ($datatype !== null) {
            [$prefix, $local] = explode(':', $datatype, 2);
            $element->setAttributeNS(self::NS['rdf'], 'rdf:datatype', self::NS[$prefix].$local);
        } elseif (($lang = LanguageTag::validOrNull($language)) !== null) {
            $element->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:lang', $lang);
        }
    }

    public function iri(DOMElement $parent, string $property, ?string $uri): void
    {
        if ($uri !== null) {
            $this->element($parent, $property)->setAttributeNS(self::NS['rdf'], 'rdf:resource', $uri);
        }
    }

    public function xml(): string
    {
        $xml = $this->dom->saveXML();
        if ($xml === false) {
            throw new \RuntimeException('Cannot serialize DCAT-AP RDF/XML');
        }

        return $xml;
    }
}
