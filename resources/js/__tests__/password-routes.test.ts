import { describe, it, expect } from 'vitest';
import { edit } from '@/routes/password';
import { normalizeTestUrl, expectUrlToBe } from './test-utils';

describe('password routes', () => {
  it('generates edit route and head form with mergeQuery', () => {
    expect({...edit(), url: normalizeTestUrl(edit().url)}).toEqual({ url: '/settings/password', method: 'get' });
    expectUrlToBe(edit.url({ query: { page: 1 } }), '/settings/password?page=1');
    expect(normalizeTestUrl(edit.form.head({ mergeQuery: { foo: 'bar' } }).action)).toBe('/settings/password?_method=HEAD&foo=bar');
  });
});
