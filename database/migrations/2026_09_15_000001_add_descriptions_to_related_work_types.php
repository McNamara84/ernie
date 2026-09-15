<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The DataCite maps are intentionally inlined so this historical migration
 * remains stable when application seeders evolve for later schema versions.
 */
return new class extends Migration
{
    /**
     * DataCite 4.7 relationType definitions at the time this migration was authored.
     *
     * @var array<string, string>
     */
    private const RELATION_TYPE_DESCRIPTIONS = [
        'IsCitedBy' => 'Indicates that B includes A in a citation',
        'Cites' => 'Indicates that A includes B in a citation',
        'IsSupplementTo' => 'Indicates that A is a supplement to B',
        'IsSupplementedBy' => 'Indicates that B is a supplement to A',
        'IsTranslationOf' => 'Indicates A is a translation of B',
        'IsContinuedBy' => 'Indicates A is continued by the work B',
        'Continues' => 'Indicates A is a continuation of the work B',
        'IsDescribedBy' => 'Indicates A is described by B',
        'Describes' => 'Indicates A describes B',
        'HasMetadata' => 'Indicates resource A has additional metadata B',
        'IsMetadataFor' => 'Indicates additional metadata A for a resource B',
        'HasVersion' => 'Indicates A has a version B',
        'IsVersionOf' => 'Indicates A is a version of B',
        'IsNewVersionOf' => 'Indicates A is a new edition of B, where the new edition has been modified or updated',
        'IsPreviousVersionOf' => 'Indicates A is a previous edition of B',
        'IsPartOf' => 'Indicates A is a portion of B; may be used for elements of a series',
        'HasPart' => 'Indicates A includes the part B',
        'HasTranslation' => 'Indicates A has a translation B',
        'IsPublishedIn' => 'Indicates A is published inside B, but is independent of other things published inside of B',
        'IsReferencedBy' => 'Indicates A is used as a source of information by B',
        'References' => 'Indicates B is used as a source of information for A',
        'IsDocumentedBy' => 'Indicates B is documentation about/explaining A',
        'Documents' => 'Indicates A is documentation about/explaining B',
        'IsCompiledBy' => 'Indicates B is used to compile or create A',
        'Compiles' => 'Indicates B is the result of a compile or creation event using A',
        'IsVariantFormOf' => 'Indicates A is a variant or different form of B',
        'IsOriginalFormOf' => 'Indicates A is the original form of B',
        'IsIdenticalTo' => 'Indicates that A is identical to B, for use when there is a need to register two separate instances of the same resource',
        'IsReviewedBy' => 'Indicates that A is reviewed by B',
        'Reviews' => 'Indicates that A is a review of B',
        'IsDerivedFrom' => 'Indicates B is a source upon which A is based',
        'IsSourceOf' => 'Indicates A is a source upon which B is based',
        'IsRequiredBy' => 'Indicates A is required by B',
        'Requires' => 'Indicates A requires B',
        'IsObsoletedBy' => 'Indicates A is replaced by B',
        'Obsoletes' => 'Indicates A replaces B',
        'IsCollectedBy' => 'Indicates A is collected by B',
        'Collects' => 'Indicates A collects B',
        'Other' => 'Indicates that A is related to B and the relationship does not fit into an existing category.',
    ];

    /**
     * DataCite 4.7 relatedIdentifierType descriptions at the time this migration was authored.
     *
     * @var array<string, string>
     */
    private const IDENTIFIER_TYPE_DESCRIPTIONS = [
        'ARK' => 'A URI designed to support long-term access to information objects. In general, ARK syntax is of the form (brackets, []. indicate optional elements): [http://NMA/]ark:/NAAN/Name [Qualifier].',
        'arXiv' => 'arXiv.org is a repository of preprints of scientific papers in the fields of mathematics, physics, astronomy, computer science, quantitative biology, statistics, and quantitative finance.',
        'bibcode' => 'A standardized 19-character identifier according to the syntax yyyyjjjjjvvvvmppppa. See http://info-uri.info/registry/OAIHandler?verb=GetRecord&metadataPrefix=reg&identifier=info:bibcode/.',
        'CSTR' => 'CSTR is an identifier based on the Chinese National Standard GB/T 32843—2016 “Science and technology resource identification”, providing a unique identification service for scientific data, papers, scientific institutions, researchers, scientific instruments, patents and other scientific and technological resources.',
        'DOI' => 'A character string used to uniquely identify an object. A DOI name is divided into two parts, a prefix and a suffix, separated by a slash.',
        'EAN13' => 'A 13-digit barcoding standard that is a superset of the original 12-digit Universal Product Code (UPC) system.',
        'EISSN' => 'ISSN used to identify periodicals in electronic form (eISSN or e-ISSN).',
        'Handle' => 'This refers specifically to an ID in the Handle system operated by the Corporation for National Research Initiatives (CNRI).',
        'IGSN' => 'A code that uniquely identifies samples from our natural environment and related features-of-interest.',
        'ISBN' => 'A unique numeric book identifier. There are 2 formats: a 10-digit ISBN format and a 13-digit ISBN.',
        'ISSN' => 'A unique 8-digit number used to identify a print or electronic periodical publication.',
        'ISTC' => 'A unique “number” assigned to a textual work. An ISTC consists of 16 numbers and/or letters.',
        'LISSN' => 'The linking ISSN or ISSN-L enables collocation or linking among different media versions of a continuing resource.',
        'LSID' => 'A unique identifier for data in the Life Science domain. Format: urn:lsid:authority:namespace:identifier:revision.',
        'PMID' => 'A unique number assigned to each PubMed record.',
        'PURL' => 'A PURL has three parts: (1) a protocol, (2) a resolver address, and (3) a name.',
        'RAiD' => 'The Research Activity Identifier (RAiD) is a persistent identifier (PID) and global registry dedicated to research projects. RAiD is governed by ISO standard 23527:2022, with the Australian Research Data Commons (ARDC) as the Registration Authority and lead developer of the system. RAiD provides a system to store, update, share, and link project information across the research community.',
        'RRID' => 'A character string used to uniquely identify key inputs to an experiment including the so-called “key biological resources” as defined by the National Institutes of Health, and related tools such as core facilities and databases. An RRID name is divided into two parts, the authority and a local identifier, separated by an underscore.',
        'SWHID' => 'SWHIDs (from “SoftWare Hash IDentifiers”) are persistent, intrinsic identifiers for software source code artifacts such as source code files, source trees, commits, and other objects typically found in version control systems. A SWHID consists of two separate parts, a mandatory core identifier that can point to any software artifact (or “object”) available in the Software Heritage archive, and an optional list of qualifiers that allows to specify the context where the object is meant to be seen and point to a subpart of the object itself.',
        'UPC' => 'A barcode symbology used for tracking trade items in stores. Its most common form, the UPC-A, consists of 12 numerical digits.',
        'URL' => 'Also known as web address, a URL is a specific character string that constitutes a reference to a resource. The syntax is: scheme://domain:port/path?query_string#fragment_id.',
        'URN' => 'A unique and persistent identifier of an electronic document. The syntax is: urn:<NID>:<NSS>. The leading urn: sequence is case-insensitive, <NID> is the namespace identifier, <NSS> is the namespace-specific string.',
        'w3id' => 'Mostly used to publish vocabularies and ontologies. The letters ‘w3’ stand for “World Wide Web”.',
    ];

    public function up(): void
    {
        Schema::table('relation_types', function (Blueprint $table): void {
            $table->text('description')->nullable()->after('slug');
        });

        Schema::table('identifier_types', function (Blueprint $table): void {
            $table->text('description')->nullable()->after('slug');
        });

        foreach (self::RELATION_TYPE_DESCRIPTIONS as $slug => $description) {
            DB::table('relation_types')
                ->where('slug', $slug)
                ->update(['description' => $description]);
        }

        foreach (self::IDENTIFIER_TYPE_DESCRIPTIONS as $slug => $description) {
            DB::table('identifier_types')
                ->where('slug', $slug)
                ->update(['description' => $description]);
        }
    }

    public function down(): void
    {
        Schema::table('identifier_types', function (Blueprint $table): void {
            $table->dropColumn('description');
        });

        Schema::table('relation_types', function (Blueprint $table): void {
            $table->dropColumn('description');
        });
    }
};
