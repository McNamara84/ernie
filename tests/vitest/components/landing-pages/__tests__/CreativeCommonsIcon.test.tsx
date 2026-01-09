/**
 * @vitest-environment jsdom
 */
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { CreativeCommonsIcon, isCreativeCommonsLicense } from '@/pages/LandingPages/components/CreativeCommonsIcon';

describe('CreativeCommonsIcon', () => {
    describe('CC-BY licenses', () => {
        it('renders CC and BY icons for CC-BY-4.0', () => {
            render(<CreativeCommonsIcon spdxId="CC-BY-4.0" />);

            const container = screen.getByLabelText(/Creative Commons CC-BY-4.0/i);
            expect(container).toBeInTheDocument();

            // Should have CC logo and BY icon (2 SVGs)
            const svgs = container.querySelectorAll('svg');
            expect(svgs).toHaveLength(2);
        });

        it('renders CC, BY, and SA icons for CC-BY-SA-4.0', () => {
            render(<CreativeCommonsIcon spdxId="CC-BY-SA-4.0" />);

            const container = screen.getByLabelText(/Creative Commons CC-BY-SA-4.0/i);
            const svgs = container.querySelectorAll('svg');
            expect(svgs).toHaveLength(3);
        });

        it('renders CC, BY, and NC icons for CC-BY-NC-4.0', () => {
            render(<CreativeCommonsIcon spdxId="CC-BY-NC-4.0" />);

            const container = screen.getByLabelText(/Creative Commons CC-BY-NC-4.0/i);
            const svgs = container.querySelectorAll('svg');
            expect(svgs).toHaveLength(3);
        });

        it('renders CC, BY, and ND icons for CC-BY-ND-4.0', () => {
            render(<CreativeCommonsIcon spdxId="CC-BY-ND-4.0" />);

            const container = screen.getByLabelText(/Creative Commons CC-BY-ND-4.0/i);
            const svgs = container.querySelectorAll('svg');
            expect(svgs).toHaveLength(3);
        });

        it('renders CC, BY, NC, and SA icons for CC-BY-NC-SA-4.0', () => {
            render(<CreativeCommonsIcon spdxId="CC-BY-NC-SA-4.0" />);

            const container = screen.getByLabelText(/Creative Commons CC-BY-NC-SA-4.0/i);
            const svgs = container.querySelectorAll('svg');
            expect(svgs).toHaveLength(4);
        });

        it('renders CC, BY, NC, and ND icons for CC-BY-NC-ND-4.0', () => {
            render(<CreativeCommonsIcon spdxId="CC-BY-NC-ND-4.0" />);

            const container = screen.getByLabelText(/Creative Commons CC-BY-NC-ND-4.0/i);
            const svgs = container.querySelectorAll('svg');
            expect(svgs).toHaveLength(4);
        });
    });

    describe('CC0 (Public Domain)', () => {
        it('renders CC and Zero icons for CC0-1.0', () => {
            render(<CreativeCommonsIcon spdxId="CC0-1.0" />);

            const container = screen.getByLabelText(/Creative Commons CC0-1.0/i);
            const svgs = container.querySelectorAll('svg');
            expect(svgs).toHaveLength(2);
        });
    });

    describe('non-CC licenses', () => {
        it('returns null for MIT license', () => {
            const { container } = render(<CreativeCommonsIcon spdxId="MIT" />);
            expect(container).toBeEmptyDOMElement();
        });

        it('returns null for Apache-2.0 license', () => {
            const { container } = render(<CreativeCommonsIcon spdxId="Apache-2.0" />);
            expect(container).toBeEmptyDOMElement();
        });

        it('returns null for GPL-3.0 license', () => {
            const { container } = render(<CreativeCommonsIcon spdxId="GPL-3.0" />);
            expect(container).toBeEmptyDOMElement();
        });
    });

    describe('case insensitivity', () => {
        it('handles lowercase spdx id', () => {
            render(<CreativeCommonsIcon spdxId="cc-by-4.0" />);

            const container = screen.getByLabelText(/Creative Commons cc-by-4.0/i);
            expect(container).toBeInTheDocument();
        });

        it('handles mixed case spdx id', () => {
            render(<CreativeCommonsIcon spdxId="Cc-By-Sa-4.0" />);

            const container = screen.getByLabelText(/Creative Commons Cc-By-Sa-4.0/i);
            expect(container).toBeInTheDocument();
        });
    });

    describe('custom className', () => {
        it('applies custom className to icons', () => {
            render(<CreativeCommonsIcon spdxId="CC-BY-4.0" className="h-6 w-6" />);

            const container = screen.getByLabelText(/Creative Commons CC-BY-4.0/i);
            const svgs = container.querySelectorAll('svg');

            svgs.forEach((svg) => {
                expect(svg).toHaveClass('h-6', 'w-6');
            });
        });
    });
});

describe('isCreativeCommonsLicense', () => {
    it('returns true for CC-BY licenses', () => {
        expect(isCreativeCommonsLicense('CC-BY-4.0')).toBe(true);
        expect(isCreativeCommonsLicense('CC-BY-SA-4.0')).toBe(true);
        expect(isCreativeCommonsLicense('CC-BY-NC-4.0')).toBe(true);
    });

    it('returns true for CC0', () => {
        expect(isCreativeCommonsLicense('CC0-1.0')).toBe(true);
    });

    it('returns true for lowercase cc licenses', () => {
        expect(isCreativeCommonsLicense('cc-by-4.0')).toBe(true);
        expect(isCreativeCommonsLicense('cc0-1.0')).toBe(true);
    });

    it('returns false for MIT', () => {
        expect(isCreativeCommonsLicense('MIT')).toBe(false);
    });

    it('returns false for Apache', () => {
        expect(isCreativeCommonsLicense('Apache-2.0')).toBe(false);
    });

    it('returns false for GPL', () => {
        expect(isCreativeCommonsLicense('GPL-3.0')).toBe(false);
    });
});
