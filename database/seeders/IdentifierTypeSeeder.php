<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\IdentifierType;
use Illuminate\Database\Seeder;

/**
 * Seeder for Identifier Types (DataCite #12)
 *
 * @see https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/relatedidentifier/
 */
class IdentifierTypeSeeder extends Seeder
{
    /**
     * DataCite 4.7 relatedIdentifierType controlled values and descriptions.
     *
     * @var list<array{name: string, slug: string, description: string}>
     *
     * @see https://datacite-metadata-schema.readthedocs.io/en/4.7/appendices/appendix-1/relatedIdentifierType/
     */
    public const TYPES = [
        ['name' => 'ARK', 'slug' => 'ARK', 'description' => 'A URI designed to support long-term access to information objects. In general, ARK syntax is of the form (brackets, []. indicate optional elements): [http://NMA/]ark:/NAAN/Name [Qualifier].'],
        ['name' => 'arXiv', 'slug' => 'arXiv', 'description' => 'arXiv.org is a repository of preprints of scientific papers in the fields of mathematics, physics, astronomy, computer science, quantitative biology, statistics, and quantitative finance.'],
        ['name' => 'bibcode', 'slug' => 'bibcode', 'description' => 'A standardized 19-character identifier according to the syntax yyyyjjjjjvvvvmppppa. See http://info-uri.info/registry/OAIHandler?verb=GetRecord&metadataPrefix=reg&identifier=info:bibcode/.'],
        ['name' => 'CSTR', 'slug' => 'CSTR', 'description' => 'CSTR is an identifier based on the Chinese National Standard GB/T 32843—2016 “Science and technology resource identification”, providing a unique identification service for scientific data, papers, scientific institutions, researchers, scientific instruments, patents and other scientific and technological resources.'],
        ['name' => 'DOI', 'slug' => 'DOI', 'description' => 'A character string used to uniquely identify an object. A DOI name is divided into two parts, a prefix and a suffix, separated by a slash.'],
        ['name' => 'EAN13', 'slug' => 'EAN13', 'description' => 'A 13-digit barcoding standard that is a superset of the original 12-digit Universal Product Code (UPC) system.'],
        ['name' => 'EISSN', 'slug' => 'EISSN', 'description' => 'ISSN used to identify periodicals in electronic form (eISSN or e-ISSN).'],
        ['name' => 'Handle', 'slug' => 'Handle', 'description' => 'This refers specifically to an ID in the Handle system operated by the Corporation for National Research Initiatives (CNRI).'],
        ['name' => 'IGSN', 'slug' => 'IGSN', 'description' => 'A code that uniquely identifies samples from our natural environment and related features-of-interest.'],
        ['name' => 'ISBN', 'slug' => 'ISBN', 'description' => 'A unique numeric book identifier. There are 2 formats: a 10-digit ISBN format and a 13-digit ISBN.'],
        ['name' => 'ISSN', 'slug' => 'ISSN', 'description' => 'A unique 8-digit number used to identify a print or electronic periodical publication.'],
        ['name' => 'ISTC', 'slug' => 'ISTC', 'description' => 'A unique “number” assigned to a textual work. An ISTC consists of 16 numbers and/or letters.'],
        ['name' => 'LISSN', 'slug' => 'LISSN', 'description' => 'The linking ISSN or ISSN-L enables collocation or linking among different media versions of a continuing resource.'],
        ['name' => 'LSID', 'slug' => 'LSID', 'description' => 'A unique identifier for data in the Life Science domain. Format: urn:lsid:authority:namespace:identifier:revision.'],
        ['name' => 'PMID', 'slug' => 'PMID', 'description' => 'A unique number assigned to each PubMed record.'],
        ['name' => 'PURL', 'slug' => 'PURL', 'description' => 'A PURL has three parts: (1) a protocol, (2) a resolver address, and (3) a name.'],
        ['name' => 'RAiD', 'slug' => 'RAiD', 'description' => 'The Research Activity Identifier (RAiD) is a persistent identifier (PID) and global registry dedicated to research projects. RAiD is governed by ISO standard 23527:2022, with the Australian Research Data Commons (ARDC) as the Registration Authority and lead developer of the system. RAiD provides a system to store, update, share, and link project information across the research community.'],
        ['name' => 'RRID', 'slug' => 'RRID', 'description' => 'A character string used to uniquely identify key inputs to an experiment including the so-called “key biological resources” as defined by the National Institutes of Health, and related tools such as core facilities and databases. An RRID name is divided into two parts, the authority and a local identifier, separated by an underscore.'],
        ['name' => 'SWHID', 'slug' => 'SWHID', 'description' => 'SWHIDs (from “SoftWare Hash IDentifiers”) are persistent, intrinsic identifiers for software source code artifacts such as source code files, source trees, commits, and other objects typically found in version control systems. A SWHID consists of two separate parts, a mandatory core identifier that can point to any software artifact (or “object”) available in the Software Heritage archive, and an optional list of qualifiers that allows to specify the context where the object is meant to be seen and point to a subpart of the object itself.'],
        ['name' => 'UPC', 'slug' => 'UPC', 'description' => 'A barcode symbology used for tracking trade items in stores. Its most common form, the UPC-A, consists of 12 numerical digits.'],
        ['name' => 'URL', 'slug' => 'URL', 'description' => 'Also known as web address, a URL is a specific character string that constitutes a reference to a resource. The syntax is: scheme://domain:port/path?query_string#fragment_id.'],
        ['name' => 'URN', 'slug' => 'URN', 'description' => 'A unique and persistent identifier of an electronic document. The syntax is: urn:<NID>:<NSS>. The leading urn: sequence is case-insensitive, <NID> is the namespace identifier, <NSS> is the namespace-specific string.'],
        ['name' => 'w3id', 'slug' => 'w3id', 'description' => 'Mostly used to publish vocabularies and ontologies. The letters ‘w3’ stand for “World Wide Web”.'],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (self::TYPES as $type) {
            $identifierType = IdentifierType::firstOrCreate(
                ['slug' => $type['slug']],
                [
                    'name' => $type['name'],
                    'description' => $type['description'],
                ]
            );

            if ($identifierType->description !== $type['description']) {
                $identifierType->update(['description' => $type['description']]);
            }
        }
    }
}
