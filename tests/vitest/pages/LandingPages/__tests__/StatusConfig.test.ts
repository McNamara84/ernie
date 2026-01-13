import { CheckCircle, Eye, FileEdit } from 'lucide-react';
import { describe, expect, it } from 'vitest';

import { getStatusConfig } from '@/pages/LandingPages/components/StatusConfig';

describe('getStatusConfig', () => {
    it('returns published config for "published" status', () => {
        const config = getStatusConfig('published');

        expect(config.icon).toBe(CheckCircle);
        expect(config.color).toBe('text-green-600');
        expect(config.textColor).toBe('text-green-700');
        expect(config.label).toBe('Published');
    });

    it('returns draft config for "draft" status', () => {
        const config = getStatusConfig('draft');

        expect(config.icon).toBe(FileEdit);
        expect(config.color).toBe('text-amber-500');
        expect(config.textColor).toBe('text-amber-700');
        expect(config.label).toBe('Draft');
    });

    it('returns preview config for "preview" status', () => {
        const config = getStatusConfig('preview');

        expect(config.icon).toBe(Eye);
        expect(config.color).toBe('text-blue-500');
        expect(config.textColor).toBe('text-blue-700');
        expect(config.label).toBe('Review Preview');
    });

    it('handles case-insensitive status matching', () => {
        const publishedUpper = getStatusConfig('PUBLISHED');
        const publishedMixed = getStatusConfig('Published');

        expect(publishedUpper.label).toBe('Published');
        expect(publishedMixed.label).toBe('Published');
    });

    it('returns fallback config for unknown status', () => {
        const config = getStatusConfig('unknown-status');

        expect(config.icon).toBe(Eye);
        expect(config.color).toBe('text-gray-500');
        expect(config.textColor).toBe('text-gray-700');
        expect(config.label).toBe('unknown-status');
    });

    it('returns fallback config for empty string', () => {
        const config = getStatusConfig('');

        expect(config.icon).toBe(Eye);
        expect(config.color).toBe('text-gray-500');
        expect(config.textColor).toBe('text-gray-700');
        expect(config.label).toBe('');
    });
});
