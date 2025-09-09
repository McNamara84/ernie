import { describe, it, expect } from 'vitest';
import { notice, verify, send } from '@/routes/verification';

describe('verification routes', () => {
  it('generates notice route definitions', () => {
    expect(notice()).toEqual({ url: '/verify-email', method: 'get' });
    expect(notice.url({ query: { foo: 'bar' } })).toBe('/verify-email?foo=bar');
    expect(notice.form.head({ query: { foo: 'bar' } })).toEqual({
      action: '/verify-email?_method=HEAD&foo=bar',
      method: 'get',
    });
  });

  it('generates verify route definitions', () => {
    expect(verify({ id: 1, hash: 'abc' })).toEqual({ url: '/verify-email/1/abc', method: 'get' });
    expect(verify.url({ id: 2, hash: 'def' }, { query: { token: 'x' } })).toBe('/verify-email/2/def?token=x');
    expect(verify.form.head([3, 'ghi'], { query: { token: 'x' } })).toEqual({
      action: '/verify-email/3/ghi?_method=HEAD&token=x',
      method: 'get',
    });
  });

  it('generates send route definitions', () => {
    expect(send()).toEqual({ url: '/email/verification-notification', method: 'post' });
    expect(send.url({ query: { foo: 'bar' } })).toBe('/email/verification-notification?foo=bar');
    expect(send.form.post({ query: { foo: 'bar' } })).toEqual({
      action: '/email/verification-notification?foo=bar',
      method: 'post',
    });
  });
});
