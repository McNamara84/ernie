import { afterEach, describe, expect, it } from 'vitest';
import { local } from '@/routes/storage';

describe('storage routes', () => {
    afterEach(() => {
        window.history.replaceState({}, '', '/');
    });

    it('builds local storage urls from different argument formats', () => {
        expect(local('files/report.xml')).toEqual({ url: '/storage/files/report.xml', method: 'get' });
        expect(local.url('files/report.xml')).toBe('/storage/files/report.xml');
        expect(local.url(42)).toBe('/storage/42');
        expect(local.url([123])).toBe('/storage/123');
        expect(local.url({ path: 'uploads/data.xml/' })).toBe('/storage/uploads/data.xml');
    });

    it('creates helpers for GET and HEAD requests', () => {
        expect(local.get({ path: 'reports/summary.xml' }, { query: { download: true } })).toEqual({
            url: '/storage/reports/summary.xml?download=1',
            method: 'get',
        });

        expect(local.head('docs/test.xml', { query: { download: true } })).toEqual({
            url: '/storage/docs/test.xml?download=1',
            method: 'head',
        });
    });

    it('generates forms that support merge queries for HEAD requests', () => {
        expect(local.form.get('foo.xml')).toEqual({ action: '/storage/foo.xml', method: 'get' });

        expect(local.form.head('report.xml', { query: { download: true } })).toEqual({
            action: '/storage/report.xml?_method=HEAD&download=1',
            method: 'get',
        });

        window.history.replaceState({}, '', '/storage/report.xml?existing=1');
        expect(local.form.head('report.xml', { mergeQuery: { download: false } })).toEqual({
            action: '/storage/report.xml?existing=1&_method=HEAD&download=0',
            method: 'get',
        });
    });
});
