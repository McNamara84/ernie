import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

import jsonld from 'jsonld';

import { createOfflineLoader, verifyContextChecksums, verifyDataCiteGraph, verifyReport } from '../../scripts/jsonld-contract.mjs';

const context = 'https://example.org/metadata/contexts/datacite-4.7-v1.jsonld';
const property = (term) => `https://w3id.org/tib/datacite/property/${term}`;
const loader = createOfflineLoader([context]);

test('context snapshots match their recorded checksums', () => verifyContextChecksums());

test('offline loader rejects unknown remote and nested contexts', async () => {
    await assert.rejects(loader('https://unknown.example.org/context'), /Unknown offline context/);
    await assert.rejects(
        jsonld.expand({ '@context': [context, 'https://unknown.example.org/context'], identifier: { value: 'example' } }, { documentLoader: loader }),
        (error) => error.name === 'jsonld.InvalidUrl' && error.details.code === 'loading remote context failed',
    );
});

test('profile requires JSON-LD 1.1 and preserves ordered creators and polygon vertices', async () => {
    const document = {
        '@context': context,
        creators: { creator: [{ creatorName: { value: 'First' } }, { creatorName: { value: 'Second' } }] },
        geoLocations: {
            geoLocation: {
                geoLocationPolygon: {
                    polygonPoint: [
                        { pointLongitude: { value: '13' }, pointLatitude: { value: '52' } },
                        { pointLongitude: { value: '14' }, pointLatitude: { value: '53' } },
                    ],
                },
            },
        },
    };
    const [graph] = await jsonld.expand(document, { documentLoader: loader, safe: true, processingMode: 'json-ld-1.1' });
    verifyDataCiteGraph(graph, {
        creators: [{ name: 'First' }, { name: 'Second' }],
        geoLocations: [
            {
                geoLocationPolygon: [
                    { polygonPoint: { pointLongitude: 13, pointLatitude: 52 } },
                    { polygonPoint: { pointLongitude: 14, pointLatitude: 53 } },
                ],
            },
        ],
    });
    await assert.rejects(jsonld.expand(document, { documentLoader: loader, processingMode: 'json-ld-1.0' }), /processing mode|version/i);
});

test('semantic assertions detect a lost field and a reversed creator list', async () => {
    const [graph] = await jsonld.expand(
        {
            '@context': context,
            creators: { creator: [{ creatorName: { value: 'First' } }, { creatorName: { value: 'Second' } }] },
            titles: { title: { value: 'Research', attrs: { lang: 'en' } } },
        },
        { documentLoader: loader, safe: true },
    );
    const source = { creators: [{ name: 'First' }, { name: 'Second' }], titles: [{ title: 'Research', lang: 'en' }] };
    verifyDataCiteGraph(graph, source);
    const missing = structuredClone(graph);
    delete missing[property('title')];
    assert.throws(() => verifyDataCiteGraph(missing, source), /Missing property title/);
    graph[property('creator')][0]['@list'].reverse();
    assert.throws(() => verifyDataCiteGraph(graph, source), /Literal creatorName/);
});

test('related-item scoped value nesting preserves descendant literals and enum IRIs', async () => {
    const [graph] = await jsonld.expand(
        {
            '@context': context,
            relatedItems: {
                relatedItem: {
                    attrs: { relatedItemType: 'Presentation', relationType: 'Other', relationTypeInformation: 'Conference presentation' },
                    value: {
                        titles: { title: { value: 'Presentation', attrs: { lang: 'en' } } },
                        publicationYear: { value: '2026' },
                        creators: { creator: { creatorName: { value: 'First' } } },
                    },
                },
            },
        },
        { documentLoader: loader, safe: true },
    );
    verifyDataCiteGraph(graph, {
        relatedItems: [
            {
                relatedItemType: 'Presentation',
                relationType: 'Other',
                relationTypeInformation: 'Conference presentation',
                titles: [{ title: 'Presentation', lang: 'en' }],
                publicationYear: '2026',
                creators: [{ name: 'First' }],
            },
        ],
    });
});

test('safe expansion rejects unmapped fields instead of silently discarding them', async () => {
    await assert.rejects(
        jsonld.expand({ '@context': context, unmappedMetadata: 'must survive' }, { documentLoader: loader, safe: true }),
        /Safe mode validation error/,
    );
});

test('semantic report requires actual PHP exports for all 34 resource types', async () => {
    await assert.rejects(verifyReport({ profiles: [], resources: [] }), /Complete PHP export report required/);
    const manifest = JSON.parse(readFileSync(new URL('../../resources/data/contexts/manifest.json', import.meta.url)));
    assert.equal(manifest.processingMode, 'json-ld-1.1');
});
