/**
 * @vitest-environment jsdom
 */
import { render } from '@tests/vitest/utils/render';
import { describe, expect, it } from 'vitest';

import { CrossrefFunderIcon, OrcidIcon, RorIcon } from '@/pages/LandingPages/components/PidIcons';

describe('PidIcons', () => {
    describe('OrcidIcon', () => {
        it('renders an SVG element', () => {
            const { container } = render(<OrcidIcon />);
            expect(container.querySelector('svg')).toBeInTheDocument();
        });

        it('has correct data-slot', () => {
            const { container } = render(<OrcidIcon />);
            expect(container.querySelector('[data-slot="orcid-icon"]')).toBeInTheDocument();
        });

        it('is aria-hidden', () => {
            const { container } = render(<OrcidIcon />);
            expect(container.querySelector('svg')).toHaveAttribute('aria-hidden', 'true');
        });

        it('accepts custom className', () => {
            const { container } = render(<OrcidIcon className="h-6 w-6" />);
            expect(container.querySelector('svg')).toHaveClass('h-6', 'w-6');
        });
    });

    describe('RorIcon', () => {
        it('renders an SVG element', () => {
            const { container } = render(<RorIcon />);
            expect(container.querySelector('svg')).toBeInTheDocument();
        });

        it('has correct data-slot', () => {
            const { container } = render(<RorIcon />);
            expect(container.querySelector('[data-slot="ror-icon"]')).toBeInTheDocument();
        });

        it('is aria-hidden', () => {
            const { container } = render(<RorIcon />);
            expect(container.querySelector('svg')).toHaveAttribute('aria-hidden', 'true');
        });
    });

    describe('CrossrefFunderIcon', () => {
        it('renders an SVG element', () => {
            const { container } = render(<CrossrefFunderIcon />);
            expect(container.querySelector('svg')).toBeInTheDocument();
        });

        it('has correct data-slot', () => {
            const { container } = render(<CrossrefFunderIcon />);
            expect(container.querySelector('[data-slot="crossref-funder-icon"]')).toBeInTheDocument();
        });

        it('is aria-hidden', () => {
            const { container } = render(<CrossrefFunderIcon />);
            expect(container.querySelector('svg')).toHaveAttribute('aria-hidden', 'true');
        });
    });
});
