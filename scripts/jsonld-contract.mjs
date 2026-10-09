import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';

import jsonld from 'jsonld';

const property = (term) => `https://w3id.org/tib/datacite/property/${term}`;
const vocab = (term, value) => `https://w3id.org/tib/datacite/vocab/${term}/${value}`;
const rdfValue = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#value';
const xsd = (term) => `http://www.w3.org/2001/XMLSchema#${term}`;
const schema = (term) => `http://schema.org/${term}`;
const enums = new Set([
    'identifierType',
    'nameType',
    'titleType',
    'contributorType',
    'dateType',
    'resourceTypeGeneral',
    'relatedIdentifierType',
    'relatedItemType',
    'relatedItemIdentifierType',
    'relationType',
    'descriptionType',
    'funderIdentifierType',
    'numberType',
]);
const iris = new Set(['schemeUri', 'rightsUri', 'valueUri', 'awardUri']);
const names = { schemeUri: 'schemeURI', rightsUri: 'rightsURI', valueUri: 'valueURI', awardUri: 'awardURI' };

function entries(node, term, count) {
    const result = node[property(term)];
    assert.ok(Array.isArray(result), `Missing property ${term}`);
    if (count !== undefined) assert.equal(result.length, count, `Cardinality of ${term}`);
    return result;
}

function attributes(node, source) {
    for (const [key, value] of Object.entries(source)) {
        if (value === null) continue;
        const iri = key === 'lang' ? 'http://purl.org/dc/terms/language' : property(names[key] ?? key);
        const actual = node[iri];
        assert.equal(actual?.length, 1, `Attribute ${key}`);
        if (enums.has(key)) {
            const scheme = key === 'relatedItemType' ? 'resourceTypeGeneral' : key === 'relatedItemIdentifierType' ? 'relatedIdentifierType' : key;
            const term = value === 'Crossref Funder ID' ? 'CrossrefFunderID' : value;
            assert.equal(actual[0]['@id'], vocab(scheme, term), `Vocabulary ${key}`);
        } else if (iris.has(key)) {
            assert.equal(actual[0]['@id'], value, `IRI ${key}`);
        } else {
            assert.equal(String(actual[0]['@value']), String(value), `Value ${key}`);
        }
    }
}

function wrapped(node, term, value, attrs = {}, type) {
    const inner = entries(node, term, 1)[0];
    assert.equal(inner[rdfValue]?.length, 1, `Literal cardinality ${term}`);
    assert.equal(String(inner[rdfValue][0]['@value']), String(value), `Literal ${term}`);
    if (type) assert.equal(inner[rdfValue][0]['@type'], xsd(type), `Datatype ${term}`);
    attributes(inner, attrs);
}

function people(node, source, contributor = false) {
    const term = contributor ? 'contributor' : 'creator';
    const collection = entries(node, term);
    const actual = contributor ? collection : collection[0]['@list'];
    assert.equal(actual?.length, source.length, `Ordered ${term} cardinality`);
    source.forEach((person, index) => {
        const target = actual[index];
        wrapped(target, `${term}Name`, person.name, person.nameType ? { nameType: person.nameType } : {});
        for (const part of ['givenName', 'familyName']) if (person[part] !== undefined) wrapped(target, part, person[part]);
        if (person.contributorType) attributes(target, { contributorType: person.contributorType });
        if (person.nameIdentifiers) {
            const identifiers = entries(target, 'nameIdentifier', person.nameIdentifiers.length);
            person.nameIdentifiers.forEach(({ nameIdentifier, ...attrs }, i) => {
                assert.equal(identifiers[i][rdfValue][0]['@value'], nameIdentifier);
                attributes(identifiers[i], attrs);
            });
        }
        if (person.affiliation) {
            const affiliations = entries(target, 'affiliation', person.affiliation.length);
            person.affiliation.forEach(({ name, ...attrs }, i) => {
                assert.equal(affiliations[i][rdfValue][0]['@value'], name);
                attributes(affiliations[i], attrs);
            });
        }
    });
}

/** Expectations come from DataCite fields and fixed TIB IRIs, never from the context under test. */
export function verifyDataCiteGraph(root, source) {
    if (source.doi) {
        assert.equal(root['@id'], `https://doi.org/${source.doi}`);
        wrapped(root, 'identifier', source.doi, { identifierType: 'DOI' });
    }
    if (source.types)
        wrapped(root, 'resourceType', source.types.resourceType ?? '', { resourceTypeGeneral: source.types.resourceTypeGeneral }, 'string');
    if (source.publisher) {
        const { name, ...attrs } = typeof source.publisher === 'string' ? { name: source.publisher } : source.publisher;
        wrapped(root, 'publisher', name, attrs);
    }
    for (const [term, type] of Object.entries({
        publicationYear: 'gYear',
        language: 'language',
        version: 'string',
        volume: 'string',
        issue: 'string',
        firstPage: 'string',
        lastPage: 'string',
        edition: 'string',
    })) {
        if (source[term] !== undefined) wrapped(root, term, source[term], {}, type);
    }
    if (source.number !== undefined) wrapped(root, 'number', source.number, source.numberType ? { numberType: source.numberType } : {}, 'string');
    if (source.creators?.length) people(root, source.creators);
    if (source.contributors?.length) people(root, source.contributors, true);
    for (const [plural, singular, valueKey] of [
        ['titles', 'title', 'title'],
        ['subjects', 'subject', 'subject'],
        ['dates', 'date', 'date'],
        ['alternateIdentifiers', 'alternateIdentifier', 'alternateIdentifier'],
        ['relatedIdentifiers', 'relatedIdentifier', 'relatedIdentifier'],
        ['rightsList', 'rights', 'rights'],
        ['descriptions', 'description', 'description'],
    ]) {
        if (!source[plural]?.length) continue;
        const values = entries(root, singular, source[plural].length);
        source[plural].forEach((item, index) => {
            const { [valueKey]: value, ...attrs } = item;
            assert.equal(values[index][rdfValue][0]['@value'], value, `${plural}[${index}]`);
            attributes(values[index], attrs);
        });
    }
    for (const [plural, singular] of [
        ['sizes', 'size'],
        ['formats', 'format'],
    ]) {
        if (!source[plural]?.length) continue;
        const values = entries(root, singular, source[plural].length);
        source[plural].forEach((value, i) => {
            assert.equal(values[i][rdfValue][0]['@value'], value);
            assert.equal(values[i][rdfValue][0]['@type'], xsd('string'));
        });
    }
    if (source.relatedItems?.length) {
        const values = entries(root, 'relatedItem', source.relatedItems.length);
        source.relatedItems.forEach((item, i) => {
            attributes(
                values[i],
                Object.fromEntries(
                    ['relatedItemType', 'relationType', 'relationTypeInformation']
                        .filter((key) => item[key] !== undefined)
                        .map((key) => [key, item[key]]),
                ),
            );
            if (item.relatedItemIdentifier) {
                const { relatedItemIdentifier, ...attrs } = item.relatedItemIdentifier;
                wrapped(values[i], 'relatedItemIdentifier', relatedItemIdentifier, attrs);
            }
            verifyDataCiteGraph(values[i], item);
        });
    }
    if (source.geoLocations?.length) {
        const values = entries(root, 'geoLocation', source.geoLocations.length);
        source.geoLocations.forEach((geo, i) => {
            if (geo.geoLocationPlace) wrapped(values[i], 'geoLocationPlace', geo.geoLocationPlace);
            for (const term of ['geoLocationPoint', 'geoLocationBox']) {
                if (geo[term]) {
                    const inner = entries(values[i], term, 1)[0];
                    for (const [coordinate, value] of Object.entries(geo[term])) wrapped(inner, coordinate, value, {}, 'float');
                }
            }
            if (geo.geoLocationPolygon) {
                const polygon = entries(values[i], 'geoLocationPolygon', 1)[0];
                const points = entries(polygon, 'polygonPoint', 1)[0]['@list'];
                const expected = geo.geoLocationPolygon.filter((entry) => entry.polygonPoint);
                assert.equal(points.length, expected.length, 'Ordered polygon vertices');
                expected.forEach(({ polygonPoint }, p) => {
                    for (const [coordinate, value] of Object.entries(polygonPoint)) wrapped(points[p], coordinate, value, {}, 'float');
                });
                const inside = geo.geoLocationPolygon.find((entry) => entry.inPolygonPoint)?.inPolygonPoint;
                if (inside) {
                    const point = entries(polygon, 'inPolygonPoint', 1)[0];
                    for (const [coordinate, value] of Object.entries(inside)) wrapped(point, coordinate, value, {}, 'float');
                }
            }
        });
    }
    if (source.fundingReferences?.length) {
        const values = entries(root, 'fundingReference', source.fundingReferences.length);
        source.fundingReferences.forEach((funding, i) => {
            wrapped(values[i], 'funderName', funding.funderName, {}, 'string');
            if (funding.funderIdentifier)
                wrapped(
                    values[i],
                    'funderIdentifier',
                    funding.funderIdentifier,
                    Object.fromEntries(
                        ['funderIdentifierType', 'schemeUri'].filter((key) => funding[key] !== undefined).map((key) => [key, funding[key]]),
                    ),
                    'string',
                );
            if (funding.awardNumber)
                wrapped(values[i], 'awardNumber', funding.awardNumber, funding.awardUri ? { awardUri: funding.awardUri } : {}, 'string');
            if (funding.awardTitle) wrapped(values[i], 'awardTitle', funding.awardTitle, {}, 'string');
        });
    }
}

export function createOfflineLoader(contextUrls) {
    const datacite = JSON.parse(readFileSync(new URL('../resources/data/contexts/datacite-4.7-v1.jsonld', import.meta.url), 'utf8'));
    const schemaorg = JSON.parse(readFileSync(new URL('../tests/pest/Fixtures/JsonLd/schemaorg-context.jsonld', import.meta.url), 'utf8'));
    return async (url) => {
        const document = contextUrls.includes(url)
            ? datacite
            : ['https://schema.org/', 'https://schema.org', 'http://schema.org/'].includes(url)
              ? schemaorg
              : undefined;
        assert.ok(document, `Unknown offline context: ${url}`);
        return { contextUrl: null, documentUrl: url, document };
    };
}

export async function verifyReport(report) {
    assert.ok(report.profiles?.length >= 6 && report.resources?.length === 34, 'Complete PHP export report required');
    const cases = [...report.profiles, ...report.resources];
    const documentLoader = createOfflineLoader([...new Set(cases.map((item) => item.document['@context']))]);
    for (const item of cases) {
        try {
            const expanded = await jsonld.expand(item.document, { documentLoader, processingMode: 'json-ld-1.1', safe: true });
            assert.equal(expanded.length, 1, 'Single research resource');
            assert.deepEqual(expanded[0]['@type'], ['https://w3id.org/tib/datacite/class/Resource']);
            verifyDataCiteGraph(expanded[0], item.attributes);
            if (item.schemaOrg) {
                const graph = await jsonld.expand(item.schemaOrg, { documentLoader, processingMode: 'json-ld-1.1', safe: true });
                const root = graph[0];
                assert.equal(root['@id'], `https://doi.org/${item.attributes.doi}`);
                assert.ok(root['@type'].includes(schema(item.expectedType)), 'Schema.org resource type');
                assert.equal(root[schema('name')][0]['@value'], 'Research observations');
                assert.equal(root[schema('identifier')][0][schema('value')][0]['@value'], `doi:${item.attributes.doi}`);
                assert.equal(root[schema('description')][0]['@value'], 'Synthetic research metadata for Schema.org validation.');
                const metadata = root[schema('creator')] ? root : root[schema('subjectOf')].find((node) => node[schema('creator')]);
                assert.deepEqual(
                    metadata[schema('creator')][0]['@list'].map((creator) => creator[schema('name')][0]['@value']),
                    ['Lovelace, Ada', 'Example Observatory'],
                );
                assert.equal(metadata[schema('publisher')][0][schema('name')][0]['@value'], 'GFZ Data Services');
                for (const [term, value] of Object.entries({
                    datePublished: '2025-06-01',
                    dateCreated: '2024-01-01',
                    dateModified: '2025-05-01',
                    temporalCoverage: '2024-01-01/..',
                    version: item.attributes.version,
                })) {
                    assert.equal(metadata[schema(term)][0]['@value'], value, `Schema.org ${term}`);
                }
                const keywords = metadata[schema('keywords')];
                assert.equal(keywords[0]['@value'], 'Geology');
                assert.deepEqual(keywords[1]['@type'], [schema('DefinedTerm')]);
                assert.equal(keywords[1][schema('name')][0]['@value'], 'Rock');
                assert.equal(keywords[1][schema('inDefinedTermSet')][0]['@value'], 'https://example.org/vocabulary');
                assert.equal(keywords[1][schema('url')][0]['@value'], 'https://example.org/vocabulary/rock');
                assert.deepEqual(
                    metadata[schema('license')].map((license) => license['@value']),
                    ['https://spdx.org/licenses/CC-BY-4.0', 'https://creativecommons.org/licenses/by/4.0/'],
                );
                const place = metadata[schema('spatialCoverage')][0];
                assert.equal(place[schema('name')][0]['@value'], 'Potsdam');
                assert.equal(place[schema('geo')][0][schema('latitude')][0]['@value'], 52.4);
                assert.equal(place[schema('geo')][0][schema('longitude')][0]['@value'], 13);
                const grant = metadata[schema('funding')][0];
                assert.deepEqual(grant['@type'], [schema('MonetaryGrant')]);
                assert.equal(grant[schema('identifier')][0]['@value'], 'EXAMPLE-1');
                assert.equal(grant[schema('name')][0]['@value'], 'Example research grant');
                assert.equal(grant[schema('funder')][0][schema('name')][0]['@value'], 'Example Foundation');
                assert.equal(metadata[schema('citation')].length, 1, 'Only outgoing citations');
                assert.equal(metadata[schema('citation')][0]['@id'], 'https://doi.org/10.1234/example-citation');
                if (metadata !== root) {
                    assert.equal(metadata['@id'], `${root['@id']}#resource-description`);
                    assert.equal(metadata[schema('about')][0]['@id'], root['@id']);
                }
                if (item.name === 'dataset') {
                    const download = root[schema('distribution')][0];
                    assert.deepEqual(download['@type'], [schema('DataDownload')]);
                    assert.equal(download[schema('contentUrl')][0]['@value'], 'https://example.org/data.csv');
                    assert.equal(download[schema('encodingFormat')][0]['@value'], 'text/csv');
                    assert.equal(download[schema('contentSize')][0]['@value'], '12 MB');
                }
            }
        } catch (error) {
            throw new Error(`JSON-LD contract ${item.name}: ${error.message}`, { cause: error });
        }
    }
    return { dataCiteDocuments: cases.length, schemaOrgDocuments: report.resources.length };
}

export function verifyContextChecksums() {
    for (const [manifestPath, filePath] of [
        ['../resources/data/contexts/manifest.json', '../resources/data/contexts/datacite-4.7-v1.jsonld'],
        ['../tests/pest/Fixtures/JsonLd/schemaorg-context-source.json', '../tests/pest/Fixtures/JsonLd/schemaorg-context.jsonld'],
    ]) {
        const manifest = JSON.parse(readFileSync(new URL(manifestPath, import.meta.url), 'utf8'));
        const digest = createHash('sha256')
            .update(readFileSync(new URL(filePath, import.meta.url)))
            .digest('hex');
        assert.equal(digest, manifest.sha256, `Immutable context checksum: ${filePath}`);
    }
}
