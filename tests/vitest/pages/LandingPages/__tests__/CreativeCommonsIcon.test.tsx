/**
 * @vitest-environment jsdom
 */
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { CreativeCommonsIcon, getCreativeCommonsBadgePath, isCreativeCommonsLicense } from '@/pages/LandingPages/components/CreativeCommonsIcon';

describe('CreativeCommonsIcon', () => {
    it.each([
        ['CC-BY-4.0', '/images/creative-commons/88x31/by.svg'],
        ['CC-BY-SA-4.0', '/images/creative-commons/88x31/by-sa.svg'],
        ['CC-BY-NC-4.0', '/images/creative-commons/88x31/by-nc.svg'],
        ['CC-BY-ND-4.0', '/images/creative-commons/88x31/by-nd.svg'],
        ['CC-BY-NC-SA-4.0', '/images/creative-commons/88x31/by-nc-sa.svg'],
        ['CC-BY-NC-ND-4.0', '/images/creative-commons/88x31/by-nc-nd.svg'],
        ['CC0-1.0', '/images/creative-commons/88x31/cc-zero.svg'],
    ])('maps %s to the official Creative Commons badge', (spdxId, expectedPath) => {
        expect(getCreativeCommonsBadgePath(spdxId)).toBe(expectedPath);
    });

    it('renders the official badge image for a CC license', () => {
        render(<CreativeCommonsIcon spdxId="CC-BY-4.0" />);

        const badge = screen.getByRole('img', { name: 'Creative Commons CC-BY-4.0' });
        expect(badge).toHaveAttribute('src', '/images/creative-commons/88x31/by.svg');
        expect(badge).toHaveAttribute('width', '88');
        expect(badge).toHaveAttribute('height', '31');
        expect(badge).toHaveClass('h-[31px]', 'w-[88px]', 'shrink-0');
    });

    it('handles lowercase SPDX identifiers', () => {
        render(<CreativeCommonsIcon spdxId="cc-by-4.0" />);

        expect(screen.getByRole('img', { name: 'Creative Commons cc-by-4.0' })).toHaveAttribute(
            'src',
            '/images/creative-commons/88x31/by.svg',
        );
    });

    it('applies a custom className to the badge image', () => {
        render(<CreativeCommonsIcon spdxId="CC-BY-4.0" className="h-6 w-auto" />);

        const badge = screen.getByRole('img', { name: 'Creative Commons CC-BY-4.0' });
        expect(badge).toHaveClass('h-6', 'w-auto', 'shrink-0');
    });

    it('returns null for non-CC licenses', () => {
        const { container } = render(<CreativeCommonsIcon spdxId="MIT" />);

        expect(container).toBeEmptyDOMElement();
        expect(getCreativeCommonsBadgePath('MIT')).toBeNull();
    });
});

describe('isCreativeCommonsLicense', () => {
    it('returns true for Creative Commons SPDX identifiers', () => {
        expect(isCreativeCommonsLicense('CC-BY-4.0')).toBe(true);
        expect(isCreativeCommonsLicense('CC-BY-SA-4.0')).toBe(true);
        expect(isCreativeCommonsLicense('cc0-1.0')).toBe(true);
    });

    it('returns false for non-CC licenses', () => {
        expect(isCreativeCommonsLicense('MIT')).toBe(false);
        expect(isCreativeCommonsLicense('Apache-2.0')).toBe(false);
        expect(isCreativeCommonsLicense('GPL-3.0')).toBe(false);
    });
});