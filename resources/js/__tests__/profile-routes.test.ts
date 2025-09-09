import { describe, it, expect } from 'vitest';
import { edit, update, destroy } from '@/routes/profile';

describe('profile routes', () => {
  it('generates edit route and forms', () => {
    expect(edit()).toEqual({ url: '/settings/profile', method: 'get' });
    expect(edit.url()).toBe('/settings/profile');
    expect(edit.url({ query: { ref: 'abc' } })).toBe('/settings/profile?ref=abc');
    expect(edit.form.get({ query: { q: '1' } })).toEqual({ action: '/settings/profile?q=1', method: 'get' });
    expect(edit.form.head({ query: { q: '1' } })).toEqual({ action: '/settings/profile?_method=HEAD&q=1', method: 'get' });
  });

  it('generates update route and forms', () => {
    expect(update()).toEqual({ url: '/settings/profile', method: 'patch' });
    expect(update.url({ query: { foo: 'bar' } })).toBe('/settings/profile?foo=bar');
    expect(update.patch({ query: { id: '1' } })).toEqual({ url: '/settings/profile?id=1', method: 'patch' });
    expect(update.form.patch({ query: { id: '1' } })).toEqual({
      action: '/settings/profile?_method=PATCH&id=1',
      method: 'post',
    });
  });

  it('generates destroy route and forms', () => {
    expect(destroy()).toEqual({ url: '/settings/profile', method: 'delete' });
    expect(destroy.url({ query: { foo: 'bar' } })).toBe('/settings/profile?foo=bar');
    expect(destroy.delete({ query: { foo: 'bar' } })).toEqual({ url: '/settings/profile?foo=bar', method: 'delete' });
    expect(destroy.form.delete({ query: { id: 1 } })).toEqual({
      action: '/settings/profile?_method=DELETE&id=1',
      method: 'post',
    });
  });
});
