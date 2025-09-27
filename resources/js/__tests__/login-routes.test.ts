import { describe, it, expect } from 'vitest';
import { store } from '@/routes/login';
import { normalizeTestUrl, expectUrlToBe } from './test-utils';

describe('login routes', () => {
  it('generates store route definitions', () => {
    expect({...store(), url: normalizeTestUrl(store().url)}).toEqual({ url: '/login', method: 'post' });
    expectUrlToBe(store.url(), '/login');
    expectUrlToBe(store.url({ query: { ref: 'x' } }), '/login?ref=x');
    expect({...store.post({ query: { token: 'abc' } }), url: normalizeTestUrl(store.post({ query: { token: 'abc' } }).url)}).toEqual({ url: '/login?token=abc', method: 'post' });
    expect(normalizeTestUrl(store.form.post({ query: { token: 'abc' } }).action)).toBe('/login?token=abc');
  });
});
