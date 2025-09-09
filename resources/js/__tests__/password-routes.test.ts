import { describe, it, expect } from 'vitest';
import { edit } from '@/routes/password';

describe('password routes', () => {
  it('generates edit route and head form with mergeQuery', () => {
    expect(edit()).toEqual({ url: '/settings/password', method: 'get' });
    expect(edit.url({ query: { page: 1 } })).toBe('/settings/password?page=1');
    expect(edit.form.head({ mergeQuery: { foo: 'bar' } })).toEqual({
      action: '/settings/password?_method=HEAD&foo=bar',
      method: 'get',
    });
  });
});
