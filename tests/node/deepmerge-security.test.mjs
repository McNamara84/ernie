import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import test from 'node:test';

// Resolve the dependency Swagger actually loads, including nested installs.
const require = createRequire(import.meta.url);
const swaggerRequire = createRequire(require.resolve('swagger-client'));
const merge = swaggerRequire('deepmerge');

test('deepmerge preserves own data keys without poisoning source, target or result prototypes', () => {
    const payload = () => JSON.parse('{"__proto__":{"admin":true},"constructor":{"prototype":{"admin":true}},"prototype":{"admin":true},"safe":1}');
    for (const result of [
        merge(Object.create(null), payload()),
        merge(payload(), {}),
        merge(payload(), payload()),
        ...[null, undefined, false, 0, ''].map((session) => merge({ session }, { session: payload() }).session),
        merge.all([Object.create(null), payload()]),
    ]) {
        assert.equal(Object.getPrototypeOf(result), Object.prototype);
        assert.equal(result.admin, undefined);
        assert.equal(result.safe, 1);
        for (const key of ['__proto__', 'constructor', 'prototype']) {
            assert.equal(Object.hasOwn(result, key), true);
            assert.deepEqual(result[key], payload()[key]);
        }
    }
    assert.equal({}.admin, undefined);
});

test('deepmerge never reads inherited target values while merging ordinary data keys', () => {
    const inherited = {};
    for (const key of ['__proto__', 'constructor', 'prototype']) {
        Object.defineProperty(inherited, key, {
            enumerable: true,
            get() { throw new Error(`Read inherited ${key}`); },
            set() { throw new Error(`Wrote inherited ${key}`); },
        });
    }
    const source = JSON.parse('{"__proto__":{"description":"data"},"constructor":{"type":"string"},"prototype":{"type":"number"}}');
    const result = merge(Object.create(inherited), source);
    assert.deepEqual(result, source);
    assert.equal(Object.getPrototypeOf(result), Object.prototype);
    assert.equal(Object.getPrototypeOf(source), Object.prototype);
});

test('deepmerge preserves cloning, symbols, custom merges and array options', () => {
    const symbol = Symbol('metadata');
    const source = { nested: { right: 2 }, list: [{ id: 2 }], [symbol]: { value: 3 } };
    const result = merge({ nested: { left: 1 }, list: [{ id: 1 }] }, source);
    assert.deepEqual(result, { nested: { left: 1, right: 2 }, list: [{ id: 1 }, { id: 2 }], [symbol]: { value: 3 } });
    assert.notEqual(result.list[1], source.list[0]);
    assert.notEqual(result[symbol], source[symbol]);
    assert.deepEqual(merge({ list: [1] }, { list: [2] }, { arrayMerge: (_target, incoming) => incoming }), { list: [2] });
    assert.deepEqual(merge({ count: { value: 1 } }, { count: { value: 2 } }, {
        customMerge: (key) => key === 'count' ? (left, right) => ({ value: left.value + right.value }) : undefined,
    }), { count: { value: 3 } });
    assert.deepEqual(merge.all([{ left: 1 }, { right: 2 }]), { left: 1, right: 2 });
});

test('Swagger still resolves references and allOf schemas using the secured dependency', async () => {
    const SwaggerClient = require('swagger-client');
    const result = await SwaggerClient.resolve({
        spec: {
            openapi: '3.0.3', info: { title: 'Merge compatibility', version: '1.0.0' }, paths: {},
            components: { schemas: {
                Base: { type: 'object', required: ['id'], properties: {
                    id: { type: 'integer' }, constructor: { type: 'string' }, prototype: { type: 'number' },
                } },
                Combined: { allOf: [
                    { $ref: '#/components/schemas/Base' },
                    { type: 'object', required: ['title'], properties: {
                        title: { type: 'string' }, constructor: { description: 'Constructor field' },
                        prototype: { description: 'Prototype field' },
                    } },
                ] },
            } },
        },
    });
    assert.deepEqual(result.errors, []);
    const schema = result.spec.components.schemas.Combined;
    assert.deepEqual(schema.properties, {
        id: { type: 'integer' }, title: { type: 'string' },
        constructor: { type: 'string', description: 'Constructor field' },
        prototype: { type: 'number', description: 'Prototype field' },
    });
    assert.deepEqual(schema.required, ['id', 'title']);
});
