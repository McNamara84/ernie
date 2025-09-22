import { describe, it, expect, vi, afterEach } from 'vitest';
import { __testing as basePathTesting } from '@/lib/base-path';

describe('generated routes', () => {
  afterEach(() => {
    basePathTesting.setMetaBasePath('');
    basePathTesting.resetBasePathCache();
    vi.resetModules();
  });

  it('generates home route definitions', async () => {
    const routes = await import('@/routes');
    const { home } = routes;

    expect(home()).toEqual({ url: '/', method: 'get' });
    expect(home.url()).toBe('/');
    expect(home.url({ query: { page: 1 } })).toBe('/?page=1');
    expect(home.form.get()).toEqual({ action: '/', method: 'get' });
    expect(home.form.head({ query: { foo: 'bar' } })).toEqual({
      action: '/?_method=HEAD&foo=bar',
      method: 'get',
    });
    window.history.replaceState({}, '', '/?page=1');
    expect(home.form.head({ mergeQuery: { foo: 'bar' } })).toEqual({
      action: '/?page=1&_method=HEAD&foo=bar',
      method: 'get',
    });
    window.history.replaceState({}, '', '/');
  });

  it('generates logout route definitions', async () => {
    const routes = await import('@/routes');
    const { logout } = routes;

    expect(logout()).toEqual({ url: '/logout', method: 'post' });
    expect(logout.url({ query: { ref: 'x' } })).toBe('/logout?ref=x');
    expect(logout.form.post({ query: { token: 'abc' } })).toEqual({ action: '/logout?token=abc', method: 'post' });
  });

  it('applies the configured base path to generated routes', async () => {
    basePathTesting.setMetaBasePath('/ernie');
    basePathTesting.resetBasePathCache();
    vi.resetModules();

    const routes = await import('@/routes');
    expect(routes.home.url()).toBe('/ernie/');
    expect(routes.logout.url()).toBe('/ernie/logout');
  });

  it('updates route URLs when the base path meta changes after initial load', async () => {
    basePathTesting.setMetaBasePath('');
    basePathTesting.resetBasePathCache();
    vi.resetModules();

    const routes = await import('@/routes');
    expect(routes.home.url()).toBe('/');

    basePathTesting.setMetaBasePath('/ernie');
    expect(routes.home.url()).toBe('/ernie/');
  });
});

