import { expectUrlToBe,normalizeTestUrl } from '@tests/vitest/utils/test-utils';
import { describe, expect,it } from 'vitest';

import { store } from '@/routes/login';

describe('login routes', () => {
  it('generates store route definitions', () => {
    expect({...store(), url: normalizeTestUrl(store().url)}).toEqual({ url: '/login', method: 'post' });
    expectUrlToBe(store.url(), '/login');
    expectUrlToBe(store.url({ query: { ref: 'x' } }), '/login?ref=x');
    expect({...store.post({ query: { token: 'abc' } }), url: normalizeTestUrl(store.post({ query: { token: 'abc' } }).url)}).toEqual({ url: '/login?token=abc', method: 'post' });
  });
});
