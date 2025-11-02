import { describe, expect, it } from 'vitest';

import {
    getDefaultTemplate,
    getTemplateMetadata,
    getTemplateOptions,
    isValidTemplate,
    LANDING_PAGE_TEMPLATES,
} from '@/types/landing-page';

describe('Landing Page Template Registry', () => {
    describe('LANDING_PAGE_TEMPLATES', () => {
        it('should have default_gfz template', () => {
            expect(LANDING_PAGE_TEMPLATES).toHaveProperty('default_gfz');
        });

        it('should have proper structure for default_gfz', () => {
            const template = LANDING_PAGE_TEMPLATES.default_gfz;

            expect(template).toMatchObject({
                key: 'default_gfz',
                name: expect.any(String),
                description: expect.any(String),
                category: 'official',
                version: expect.any(String),
            });
        });

        it('should have consistent keys and values', () => {
            Object.entries(LANDING_PAGE_TEMPLATES).forEach(([key, template]) => {
                expect(template.key).toBe(key);
            });
        });
    });

    describe('getTemplateOptions()', () => {
        it('should return array of template options', () => {
            const options = getTemplateOptions();

            expect(Array.isArray(options)).toBe(true);
            expect(options.length).toBeGreaterThan(0);
        });

        it('should format templates correctly for select dropdown', () => {
            const options = getTemplateOptions();

            options.forEach((option) => {
                expect(option).toHaveProperty('value');
                expect(option).toHaveProperty('label');
                expect(option).toHaveProperty('description');
                expect(typeof option.value).toBe('string');
                expect(typeof option.label).toBe('string');
                expect(typeof option.description).toBe('string');
            });
        });

        it('should include default_gfz in options', () => {
            const options = getTemplateOptions();
            const defaultOption = options.find((opt) => opt.value === 'default_gfz');

            expect(defaultOption).toBeDefined();
            expect(defaultOption?.label).toBe('Default GFZ Data Services');
        });
    });

    describe('getTemplateMetadata()', () => {
        it('should return metadata for valid template key', () => {
            const metadata = getTemplateMetadata('default_gfz');

            expect(metadata).toBeDefined();
            expect(metadata?.key).toBe('default_gfz');
            expect(metadata?.name).toBe('Default GFZ Data Services');
        });

        it('should return null for invalid template key', () => {
            const metadata = getTemplateMetadata('nonexistent_template');

            expect(metadata).toBeNull();
        });

        it('should return complete metadata structure', () => {
            const metadata = getTemplateMetadata('default_gfz');

            expect(metadata).toMatchObject({
                key: expect.any(String),
                name: expect.any(String),
                description: expect.any(String),
                category: expect.any(String),
                version: expect.any(String),
            });
        });
    });

    describe('isValidTemplate()', () => {
        it('should return true for valid template key', () => {
            expect(isValidTemplate('default_gfz')).toBe(true);
        });

        it('should return false for invalid template key', () => {
            expect(isValidTemplate('invalid_template')).toBe(false);
            expect(isValidTemplate('')).toBe(false);
            expect(isValidTemplate('modern_minimal')).toBe(false); // Not added yet
        });

        it('should handle edge cases', () => {
            expect(isValidTemplate('DEFAULT_GFZ')).toBe(false); // Case-sensitive
            expect(isValidTemplate(' default_gfz ')).toBe(false); // Whitespace
            expect(isValidTemplate('default-gfz')).toBe(false); // Wrong separator
        });
    });

    describe('getDefaultTemplate()', () => {
        it('should return default_gfz', () => {
            expect(getDefaultTemplate()).toBe('default_gfz');
        });

        it('should return a valid template key', () => {
            const defaultKey = getDefaultTemplate();
            expect(isValidTemplate(defaultKey)).toBe(true);
        });

        it('should have metadata available', () => {
            const defaultKey = getDefaultTemplate();
            const metadata = getTemplateMetadata(defaultKey);

            expect(metadata).toBeDefined();
            expect(metadata?.category).toBe('official');
        });
    });

    describe('Template Registry Integration', () => {
        it('should allow safe iteration over templates', () => {
            const templateKeys = Object.keys(LANDING_PAGE_TEMPLATES);

            expect(templateKeys.length).toBeGreaterThan(0);

            templateKeys.forEach((key) => {
                expect(isValidTemplate(key)).toBe(true);
                expect(getTemplateMetadata(key)).toBeDefined();
            });
        });

        it('should maintain consistency between functions', () => {
            const options = getTemplateOptions();
            const templateKeys = Object.keys(LANDING_PAGE_TEMPLATES);

            // Options should match registry keys
            expect(options.length).toBe(templateKeys.length);

            options.forEach((option) => {
                expect(isValidTemplate(option.value)).toBe(true);
                const metadata = getTemplateMetadata(option.value);
                expect(metadata?.name).toBe(option.label);
                expect(metadata?.description).toBe(option.description);
            });
        });

        it('should support future template additions', () => {
            // This test ensures the registry pattern is extensible
            const currentCount = Object.keys(LANDING_PAGE_TEMPLATES).length;

            expect(currentCount).toBeGreaterThanOrEqual(1);

            // Template keys should follow naming convention
            Object.keys(LANDING_PAGE_TEMPLATES).forEach((key) => {
                expect(key).toMatch(/^[a-z_]+$/); // Lowercase with underscores
            });
        });
    });
});
