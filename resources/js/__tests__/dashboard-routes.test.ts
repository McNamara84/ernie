import { afterEach, describe, expect, it } from 'vitest';
import { uploadXml } from '@/routes/dashboard';

describe('dashboard routes', () => {
    afterEach(() => {
        window.history.replaceState({}, '', '/');
    });

    it('generates upload xml route definitions', () => {
        expect(uploadXml()).toEqual({ url: '/dashboard/upload-xml', method: 'post' });
        expect(uploadXml.url()).toBe('/dashboard/upload-xml');
        expect(uploadXml.url({ query: { step: '1' } })).toBe('/dashboard/upload-xml?step=1');
    });

    it('creates request helpers for posting xml files', () => {
        expect(uploadXml.post({ query: { next: '/dashboard' } })).toEqual({
            url: '/dashboard/upload-xml?next=%2Fdashboard',
            method: 'post',
        });

        expect(uploadXml.form.post({ query: { retry: true } })).toEqual({
            action: '/dashboard/upload-xml?retry=1',
            method: 'post',
        });

        window.history.replaceState({}, '', '/dashboard/upload-xml?token=abc');
        expect(uploadXml.form.post({ mergeQuery: { retry: false } })).toEqual({
            action: '/dashboard/upload-xml?token=abc&retry=0',
            method: 'post',
        });
    });
});
