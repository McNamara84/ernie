import { expectUrlToBe,normalizeTestUrl } from '@tests/vitest/utils/test-utils';
import { describe, expect,it } from 'vitest';

import { destroy,edit, update } from '@/routes/profile';

describe('profile routes', () => {
  it('generates edit route and HTTP methods', () => {
    expect({...edit(), url: normalizeTestUrl(edit().url)}).toEqual({ url: '/settings/profile', method: 'get' });
    expectUrlToBe(edit.url(), '/settings/profile');
    expectUrlToBe(edit.url({ query: { ref: 'abc' } }), '/settings/profile?ref=abc');
    expect({...edit.get({ query: { q: '1' } }), url: normalizeTestUrl(edit.get({ query: { q: '1' } }).url)}).toEqual({ url: '/settings/profile?q=1', method: 'get' });
    expect({...edit.head({ query: { q: '1' } }), url: normalizeTestUrl(edit.head({ query: { q: '1' } }).url)}).toEqual({ url: '/settings/profile?q=1', method: 'head' });
  });

  it('generates update route and HTTP methods', () => {
    expect({...update(), url: normalizeTestUrl(update().url)}).toEqual({ url: '/settings/profile', method: 'patch' });
    expectUrlToBe(update.url({ query: { foo: 'bar' } }), '/settings/profile?foo=bar');
    expect({...update.patch({ query: { id: '1' } }), url: normalizeTestUrl(update.patch({ query: { id: '1' } }).url)}).toEqual({ url: '/settings/profile?id=1', method: 'patch' });
  });

  it('generates destroy route and HTTP methods', () => {
    expect({...destroy(), url: normalizeTestUrl(destroy().url)}).toEqual({ url: '/settings/profile', method: 'delete' });
    expectUrlToBe(destroy.url({ query: { foo: 'bar' } }), '/settings/profile?foo=bar');
    expect({...destroy.delete({ query: { foo: 'bar' } }), url: normalizeTestUrl(destroy.delete({ query: { foo: 'bar' } }).url)}).toEqual({ url: '/settings/profile?foo=bar', method: 'delete' });
  });
});
