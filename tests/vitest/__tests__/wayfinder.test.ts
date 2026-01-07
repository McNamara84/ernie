import { beforeEach,describe, expect, it } from 'vitest';

import {
  addUrlDefault,
  applyUrlDefaults,
  queryParams,
  setUrlDefaults,
  validateParameters,
} from '@/wayfinder/index';

describe('wayfinder utilities', () => {
  beforeEach(() => {
    // reset url defaults before each test
    setUrlDefaults({});
  });

  describe('queryParams', () => {
    it('builds a query string from simple values', () => {
      const result = queryParams({ query: { a: 1, b: 'test' } });
      expect(result).toBe('?a=1&b=test');
    });

    it('handles arrays, objects and booleans', () => {
      const result = queryParams({
        query: {
          ids: ['1', '2'],
          filter: { name: 'john' },
          flag: true,
          neg: false,
        },
      });
      expect(result).toBe(
        '?ids%5B%5D=1&ids%5B%5D=2&filter%5Bname%5D=john&flag=1&neg=0',
      );
    });

    it('merges with existing search params', () => {
      window.history.replaceState({}, '', '/?a=1');
      const result = queryParams({ mergeQuery: { b: '2', a: '3' } });
      expect(result).toBe('?a=3&b=2');
    });

    it('returns empty string when no options provided', () => {
      expect(queryParams()).toBe('');
      expect(queryParams({})).toBe('');
    });

    it('removes params when merged value is null', () => {
      window.history.replaceState({}, '', '/?a=1&b=2');
      const result = queryParams({ mergeQuery: { a: null } });
      expect(result).toBe('?b=2');
    });
  });

  describe('url defaults', () => {
    it('applies defaults and added defaults', () => {
      setUrlDefaults({ a: '1' });
      addUrlDefault('b', '2');
      expect(applyUrlDefaults({ b: 5 })).toEqual({ b: 5, a: 1 });
      expect(applyUrlDefaults(undefined)).toEqual({ a: 1, b: 2 });
    });
  });

  describe('validateParameters', () => {
    it('throws when optional parameters are missing out of order', () => {
      expect(() => validateParameters({ b: 1 }, ['a', 'b'])).toThrow();
    });

    it('allows missing trailing optional parameters', () => {
      expect(() => validateParameters({}, ['a', 'b'])).not.toThrow();
    });

    it('does not throw when all parameters are provided', () => {
      expect(() => validateParameters({ a: 1, b: 2 }, ['a', 'b'])).not.toThrow();
    });
  });
});

