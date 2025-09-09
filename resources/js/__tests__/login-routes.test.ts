import { describe, it, expect } from 'vitest';
import { store } from '@/routes/login';

describe('login routes', () => {
  it('generates store route definitions', () => {
    expect(store()).toEqual({ url: '/login', method: 'post' });
    expect(store.url()).toBe('/login');
    expect(store.url({ query: { ref: 'x' } })).toBe('/login?ref=x');
    expect(store.post({ query: { token: 'abc' } })).toEqual({ url: '/login?token=abc', method: 'post' });
    expect(store.form.post({ query: { token: 'abc' } })).toEqual({ action: '/login?token=abc', method: 'post' });
  });
});
