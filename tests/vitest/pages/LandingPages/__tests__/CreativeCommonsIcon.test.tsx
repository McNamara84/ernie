import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { CreativeCommonsIcon, isCreativeCommonsLicense } from '@/pages/LandingPages/components/CreativeCommonsIcon';

describe('isCreativeCommonsLicense', () => {
    it('returns true for CC-BY-4.0', () => {
        expect(isCreativeCommonsLicense('CC-BY-4.0')).toBe(true);
    });

    it('returns true for CC-BY-SA-4.0', () => {
        expect(isCreativeCommonsLicense('CC-BY-SA-4.0')).toBe(true);
    });

    it('returns true for CC0-1.0', () => {
        expect(isCreativeCommonsLicense('CC0-1.0')).toBe(true);
    });

    it('returns true for lowercase cc-by-4.0', () => {
        expect(isCreativeCommonsLicense('cc-by-4.0')).toBe(true);
    });

    it('returns false for MIT', () => {
        expect(isCreativeCommonsLicense('MIT')).toBe(false);
    });

    it('returns false for Apache-2.0', () => {
        expect(isCreativeCommonsLicense('Apache-2.0')).toBe(false);
    });

    it('returns false for GPL-3.0', () => {
        expect(isCreativeCommonsLicense('GPL-3.0')).toBe(false);
    });
});

describe('CreativeCommonsIcon', () => {
    it('renders CC-BY license icons', () => {
        render(<CreativeCommonsIcon spdxId="CC-BY-4.0" />);

        const container = screen.getByLabelText('Creative Commons CC-BY-4.0');
        expect(container).toBeInTheDocument();

        // Should have CC logo and BY icon
        const svgs = container.querySelectorAll('svg');
        expect(svgs.length).toBe(2);
    });

    it('renders CC-BY-SA license icons', () => {
        render(<CreativeCommonsIcon spdxId="CC-BY-SA-4.0" />);

        const container = screen.getByLabelText('Creative Commons CC-BY-SA-4.0');
        expect(container).toBeInTheDocument();

        // Should have CC logo, BY icon, and SA icon
        const svgs = container.querySelectorAll('svg');
        expect(svgs.length).toBe(3);
    });

    it('renders CC-BY-NC license icons', () => {
        render(<CreativeCommonsIcon spdxId="CC-BY-NC-4.0" />);

        const container = screen.getByLabelText('Creative Commons CC-BY-NC-4.0');
        expect(container).toBeInTheDocument();

        // Should have CC logo, BY icon, and NC icon
        const svgs = container.querySelectorAll('svg');
        expect(svgs.length).toBe(3);
    });

    it('renders CC-BY-ND license icons', () => {
        render(<CreativeCommonsIcon spdxId="CC-BY-ND-4.0" />);

        const container = screen.getByLabelText('Creative Commons CC-BY-ND-4.0');
        expect(container).toBeInTheDocument();

        // Should have CC logo, BY icon, and ND icon
        const svgs = container.querySelectorAll('svg');
        expect(svgs.length).toBe(3);
    });

    it('renders CC-BY-NC-SA license icons', () => {
        render(<CreativeCommonsIcon spdxId="CC-BY-NC-SA-4.0" />);

        const container = screen.getByLabelText('Creative Commons CC-BY-NC-SA-4.0');
        expect(container).toBeInTheDocument();

        // Should have CC logo, BY icon, NC icon, and SA icon
        const svgs = container.querySelectorAll('svg');
        expect(svgs.length).toBe(4);
    });

    it('renders CC-BY-NC-ND license icons', () => {
        render(<CreativeCommonsIcon spdxId="CC-BY-NC-ND-4.0" />);

        const container = screen.getByLabelText('Creative Commons CC-BY-NC-ND-4.0');
        expect(container).toBeInTheDocument();

        // Should have CC logo, BY icon, NC icon, and ND icon
        const svgs = container.querySelectorAll('svg');
        expect(svgs.length).toBe(4);
    });

    it('renders CC0 license icons', () => {
        render(<CreativeCommonsIcon spdxId="CC0-1.0" />);

        const container = screen.getByLabelText('Creative Commons CC0-1.0');
        expect(container).toBeInTheDocument();

        // Should have CC logo and Zero icon
        const svgs = container.querySelectorAll('svg');
        expect(svgs.length).toBe(2);
    });

    it('returns null for non-CC license', () => {
        const { container } = render(<CreativeCommonsIcon spdxId="MIT" />);

        expect(container.querySelector('span')).toBeNull();
    });

    it('handles lowercase SPDX identifiers', () => {
        render(<CreativeCommonsIcon spdxId="cc-by-4.0" />);

        const container = screen.getByLabelText('Creative Commons cc-by-4.0');
        expect(container).toBeInTheDocument();
    });

    it('applies custom className', () => {
        render(<CreativeCommonsIcon spdxId="CC-BY-4.0" className="h-8 w-8" />);

        const container = screen.getByLabelText('Creative Commons CC-BY-4.0');
        const svgs = container.querySelectorAll('svg');

        // All SVGs should have the custom class
        svgs.forEach(svg => {
            expect(svg).toHaveClass('h-8', 'w-8');
        });
    });
});
