import { describe, it, expect } from 'vitest';
import { LANGUAGE_OPTIONS } from '../languages';

describe('LANGUAGE_OPTIONS', () => {
  it('contains expected language entries', () => {
    expect(LANGUAGE_OPTIONS).toEqual([
      { value: 'en', label: 'English' },
      { value: 'de', label: 'German' },
      { value: 'fr', label: 'French' },
    ]);
  });
});

