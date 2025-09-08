import { describe, it, expect } from 'vitest';
import { home, logout } from '../routes/index';

describe('generated routes', () => {
  it('generates home route definitions', () => {
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

  it('generates logout route definitions', () => {
    expect(logout()).toEqual({ url: '/logout', method: 'post' });
    expect(logout.url({ query: { ref: 'x' } })).toBe('/logout?ref=x');
    expect(logout.form.post({ query: { token: 'abc' } })).toEqual({ action: '/logout?token=abc', method: 'post' });
  });
});

