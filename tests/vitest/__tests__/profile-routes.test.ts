import { describe, it, expect } from 'vitest';
import { edit, update, destroy } from '@/routes/profile';
import { normalizeTestUrl, expectUrlToBe } from '@tests/utils/test-utils';

describe('profile routes', () => {
  it('generates edit route and forms', () => {
    expect({...edit(), url: normalizeTestUrl(edit().url)}).toEqual({ url: '/settings/profile', method: 'get' });
    expectUrlToBe(edit.url(), '/settings/profile');
    expectUrlToBe(edit.url({ query: { ref: 'abc' } }), '/settings/profile?ref=abc');
    expect(normalizeTestUrl(edit.form.get({ query: { q: '1' } }).action)).toBe('/settings/profile?q=1');
    expect(normalizeTestUrl(edit.form.head({ query: { q: '1' } }).action)).toBe('/settings/profile?_method=HEAD&q=1');
  });

  it('generates update route and forms', () => {
    expect({...update(), url: normalizeTestUrl(update().url)}).toEqual({ url: '/settings/profile', method: 'patch' });
    expectUrlToBe(update.url({ query: { foo: 'bar' } }), '/settings/profile?foo=bar');
    expect({...update.patch({ query: { id: '1' } }), url: normalizeTestUrl(update.patch({ query: { id: '1' } }).url)}).toEqual({ url: '/settings/profile?id=1', method: 'patch' });
    expect(normalizeTestUrl(update.form.patch({ query: { id: '1' } }).action)).toBe('/settings/profile?_method=PATCH&id=1');
  });

  it('generates destroy route and forms', () => {
    expect({...destroy(), url: normalizeTestUrl(destroy().url)}).toEqual({ url: '/settings/profile', method: 'delete' });
    expectUrlToBe(destroy.url({ query: { foo: 'bar' } }), '/settings/profile?foo=bar');
    expect({...destroy.delete({ query: { foo: 'bar' } }), url: normalizeTestUrl(destroy.delete({ query: { foo: 'bar' } }).url)}).toEqual({ url: '/settings/profile?foo=bar', method: 'delete' });
    expect(normalizeTestUrl(destroy.form.delete({ query: { id: 1 } }).action)).toBe('/settings/profile?_method=DELETE&id=1');
  });
});
