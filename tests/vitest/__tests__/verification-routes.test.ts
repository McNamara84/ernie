import { expectUrlToBe,normalizeTestUrl } from '@tests/vitest/utils/test-utils';
import { describe, expect,it } from 'vitest';

import { notice, send,verify } from '@/routes/verification';

describe('verification routes', () => {
  it('generates notice route definitions', () => {
    expect({...notice(), url: normalizeTestUrl(notice().url)}).toEqual({ url: '/verify-email', method: 'get' });
    expectUrlToBe(notice.url({ query: { foo: 'bar' } }), '/verify-email?foo=bar');
    expect({...notice.head({ query: { foo: 'bar' } }), url: normalizeTestUrl(notice.head({ query: { foo: 'bar' } }).url)}).toEqual({ url: '/verify-email?foo=bar', method: 'head' });
  });

  it('generates verify route definitions', () => {
    expect({...verify({ id: 1, hash: 'abc' }), url: normalizeTestUrl(verify({ id: 1, hash: 'abc' }).url)}).toEqual({ url: '/verify-email/1/abc', method: 'get' });
    expectUrlToBe(verify.url({ id: 2, hash: 'def' }, { query: { token: 'x' } }), '/verify-email/2/def?token=x');
    expect({...verify.head([3, 'ghi'], { query: { token: 'x' } }), url: normalizeTestUrl(verify.head([3, 'ghi'], { query: { token: 'x' } }).url)}).toEqual({ url: '/verify-email/3/ghi?token=x', method: 'head' });
  });

  it('generates send route definitions', () => {
    expect({...send(), url: normalizeTestUrl(send().url)}).toEqual({ url: '/email/verification-notification', method: 'post' });
    expectUrlToBe(send.url({ query: { foo: 'bar' } }), '/email/verification-notification?foo=bar');
    expect({...send.post({ query: { foo: 'bar' } }), url: normalizeTestUrl(send.post({ query: { foo: 'bar' } }).url)}).toEqual({ url: '/email/verification-notification?foo=bar', method: 'post' });
  });
});
