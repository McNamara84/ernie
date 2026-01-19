import { expectUrlToBe,normalizeTestUrl } from '@tests/vitest/utils/test-utils';
import { describe, expect,it } from 'vitest';

import { edit } from '@/routes/password';

describe('password routes', () => {
  it('generates edit route and head method with query params', () => {
    expect({...edit(), url: normalizeTestUrl(edit().url)}).toEqual({ url: '/settings/password', method: 'get' });
    expectUrlToBe(edit.url({ query: { page: 1 } }), '/settings/password?page=1');
    expect({...edit.head({ query: { foo: 'bar' } }), url: normalizeTestUrl(edit.head({ query: { foo: 'bar' } }).url)}).toEqual({ url: '/settings/password?foo=bar', method: 'head' });
  });
});
