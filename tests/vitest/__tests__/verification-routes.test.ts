import { expectUrlToBe,normalizeTestUrl } from '@tests/vitest/utils/test-utils';
import { describe, expect,it } from 'vitest';

import { notice, send,verify } from '@/routes/verification';

describe('verification routes', () => {
  it('generates notice route definitions', () => {
    expect({...notice(), url: normalizeTestUrl(notice().url)}).toEqual({ url: '/verify-email', method: 'get' });
    expectUrlToBe(notice.url({ query: { foo: 'bar' } }), '/verify-email?foo=bar');
    expect(normalizeTestUrl(notice.form.head({ query: { foo: 'bar' } }).action)).toBe('/verify-email?_method=HEAD&foo=bar');
  });

  it('generates verify route definitions', () => {
    expect({...verify({ id: 1, hash: 'abc' }), url: normalizeTestUrl(verify({ id: 1, hash: 'abc' }).url)}).toEqual({ url: '/verify-email/1/abc', method: 'get' });
    expectUrlToBe(verify.url({ id: 2, hash: 'def' }, { query: { token: 'x' } }), '/verify-email/2/def?token=x');
    expect(normalizeTestUrl(verify.form.head([3, 'ghi'], { query: { token: 'x' } }).action)).toBe('/verify-email/3/ghi?_method=HEAD&token=x');
  });

  it('generates send route definitions', () => {
    expect({...send(), url: normalizeTestUrl(send().url)}).toEqual({ url: '/email/verification-notification', method: 'post' });
    expectUrlToBe(send.url({ query: { foo: 'bar' } }), '/email/verification-notification?foo=bar');
    expect(normalizeTestUrl(send.form.post({ query: { foo: 'bar' } }).action)).toBe('/email/verification-notification?foo=bar');
  });
});
