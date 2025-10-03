import { describe, it, expect, vi, afterEach } from 'vitest';
import { __testing as basePathTesting } from '@/lib/base-path';
import { normalizeTestUrl, expectUrlToBe } from '@tests/test-utils';

describe('generated routes', () => {
  afterEach(() => {
    basePathTesting.setMetaBasePath('');
    basePathTesting.resetBasePathCache();
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

  it('applies the configured base path to generated routes', async () => {
    basePathTesting.setMetaBasePath('/ernie');
    basePathTesting.resetBasePathCache();
    vi.resetModules();

    const routes = await import('@/routes');
    expectUrlToBe(routes.home.url(), '/ernie/');
    expectUrlToBe(routes.logout.url(), '/ernie/logout');
  });

  it('updates route URLs when the base path meta changes after initial load', async () => {
    basePathTesting.setMetaBasePath('');
    basePathTesting.resetBasePathCache();
    vi.resetModules();

    const routes = await import('@/routes');
    expectUrlToBe(routes.home.url(), '/');

    basePathTesting.setMetaBasePath('/ernie');
    expectUrlToBe(routes.home.url(), '/ernie/');
  });
});

