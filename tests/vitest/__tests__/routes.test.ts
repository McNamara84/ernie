import { expectUrlToBe,normalizeTestUrl } from '@tests/vitest/utils/test-utils';
import { afterEach,describe, expect, it, vi } from 'vitest';

describe('generated routes', () => {
  afterEach(() => {
    vi.resetModules();
  });

  it('generates home route definitions', async () => {
    const routes = await import('@/routes');
    const { home } = routes;

    expect({...home(), url: normalizeTestUrl(home().url)}).toEqual({ url: '/', method: 'get' });
    expectUrlToBe(home.url(), '/');
    expectUrlToBe(home.url({ query: { page: 1 } }), '/?page=1');
    expect({...home.form.get(), action: normalizeTestUrl(home.form.get().action)}).toEqual({ action: '/', method: 'get' });
    expect(normalizeTestUrl(home.form.head({ query: { foo: 'bar' } }).action)).toBe('/?_method=HEAD&foo=bar');
    window.history.replaceState({}, '', '/?page=1');
    expect(normalizeTestUrl(home.form.head({ mergeQuery: { foo: 'bar' } }).action)).toBe('/?page=1&_method=HEAD&foo=bar');
    window.history.replaceState({}, '', '/');
  });

  it('generates logout route definitions', async () => {
    const routes = await import('@/routes');
    const { logout } = routes;

    expect({...logout(), url: normalizeTestUrl(logout().url)}).toEqual({ url: '/logout', method: 'post' });
    expectUrlToBe(logout.url({ query: { ref: 'x' } }), '/logout?ref=x');
    expect(normalizeTestUrl(logout.form.post({ query: { token: 'abc' } }).action)).toBe('/logout?token=abc');
  });
});

