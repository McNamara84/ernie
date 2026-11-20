import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

import Ajv2020 from 'ajv/dist/2020.js';

const root = new URL('../../resources/data/scheme/datacite/4.7/v1/', import.meta.url);
const read = (name) => readFileSync(new URL(name, root));
const schema = JSON.parse(read('schema.json'));
// URI format assertions are opt-in; date constraints must work without any format plugin.
const validate = new Ajv2020({ strict: false, validateFormats: false, allErrors: true }).compile(schema);
const attributes = {
    creators: [{ name: 'Example Observatory', nameType: 'Organizational' }],
    titles: [{ title: 'Example observations' }],
    publisher: 'GFZ Data Services',
    publicationYear: '2026',
    types: { resourceTypeGeneral: 'Dataset' },
};
const validDate = (date) => validate({ ...attributes, dates: [{ date, dateType: 'Created' }] });

test('published files match their manifest and retain the original immutable context', () => {
    const manifest = JSON.parse(read('manifest.json'));
    assert.equal(manifest.revision, 'v1');
    for (const [path, file] of Object.entries(manifest.files)) {
        const bytes = read(path);
        assert.equal(bytes.length, file.bytes, path);
        assert.equal(createHash('sha256').update(bytes).digest('hex'), file.sha256, path);
    }
    assert.deepEqual(read('context.jsonld'), readFileSync(new URL('../../resources/data/contexts/datacite-4.7-v1.jsonld', import.meta.url)));
});

test('the portable schema preserves the internal contract without custom formats', () => {
    assert.equal(schema.$id, 'https://dataservices.gfz.de/schemas/datacite/4.7/v1/schema.json');
    const internal = JSON.parse(readFileSync(new URL('../../resources/data/scheme/datacite_4.7_schema.json', import.meta.url)));
    const restored = structuredClone(schema);
    restored.$id = internal.$id;
    restored.description = internal.description;
    delete restored.$comment;
    restored.$defs.date = internal.$defs.date;
    restored.properties.publicationYear = internal.properties.publicationYear;
    restored.properties.relatedItems.items.properties.publicationYear = internal.properties.relatedItems.items.properties.publicationYear;
    assert.deepEqual(restored, internal);
    const formats = new Set();
    const visit = (node) => {
        if (node && typeof node === 'object') {
            if (typeof node.format === 'string') formats.add(node.format);
            Object.values(node).forEach(visit);
        }
    };
    visit(schema);
    assert.deepEqual([...formats], ['uri']);
});

test('a standard 2020-12 validator checks attributes, required fields, enums and unknown fields', () => {
    assert.ok(validate(attributes), JSON.stringify(validate.errors));
    assert.ok(validate({ ...attributes, types: { resourceTypeGeneral: 'Poster' } }));
    assert.ok(!validate({ data: { type: 'dois', attributes } }));
    const missing = structuredClone(attributes);
    delete missing.creators;
    assert.ok(!validate(missing));
    assert.ok(!validate({ ...attributes, types: { resourceTypeGeneral: 'MadeUp' } }));
    assert.ok(!validate({ ...attributes, creators: [{ name: 'Example', unexpected: true }] }));
});

test('publication years are checked at the root and in related items without format extensions', () => {
    for (const publicationYear of ['0000', '1957', '2026', '9999']) {
        assert.ok(validate({ ...attributes, publicationYear }), publicationYear);
    }
    for (const publicationYear of ['', '999', '10000', '-0054', '2026-07', '2026\n', ' 2026', '２０２６']) {
        assert.ok(!validate({ ...attributes, publicationYear }), publicationYear);
        assert.ok(!validate({
            ...attributes,
            relatedItems: [{ relatedItemType: 'Dataset', relationType: 'References', titles: [{ title: 'Related' }], publicationYear }],
        }), publicationYear);
    }
});

test('portable date constraints implement Gregorian calendar boundaries, including BCE and century years', () => {
    for (const year of [-400, -100, -4, 0, 4, 100, 400, 1900, 2000, 2024, 2025, 9999]) {
        const leap = year % 400 === 0 || (year % 4 === 0 && year % 100 !== 0);
        const formattedYear = `${year < 0 ? '-' : ''}${Math.abs(year).toString().padStart(4, '0')}`;
        for (let month = 1; month <= 12; month++) {
            const days = [31, leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31][month - 1];
            for (const day of [0, 1, 28, 29, 30, 31, 32]) {
                const date = `${formattedYear}-${month.toString().padStart(2, '0')}-${day.toString().padStart(2, '0')}`;
                assert.equal(validDate(date), day >= 1 && day <= days, date);
            }
        }
    }
});

test('portable dates accept reduced precision, timezone boundaries and open or closed ranges', () => {
    const points = ['2026', '-0054', '2026-07', '2024-02-29', '2026-07-16T12:30Z',
        '2026-07-16T12:30:45.123+13:59', '2026-07-16T12:30+14:00', '2026-07-16T12:30:45-14:00'];
    for (const point of points) {
        for (const date of [point, `${point}/`, `/${point}`, `${point}/2027`]) {
            assert.ok(validDate(date), date);
        }
    }
    for (const date of ['', '/', '2020/2021/2022', '2026-7', '2026-00', '2026-13', '2026-07-16T24:00Z',
        '2026-07-16T23:60Z', '2026-07-16T23:59:60Z', '2026-07-16T12:30+14:01', '2026-07-16T12:30-14:30',
        '2026-07-16T12:30+15:00', '2026-07-16T12:30+13:60', '2026-07-16T12:30', '2026-07-16 12:30Z',
        '2026-07-16T12:30.123Z', '2026-07-16\n', ' 2026-07-16', '２０２６']) {
        assert.ok(!validDate(date), date);
    }
});
