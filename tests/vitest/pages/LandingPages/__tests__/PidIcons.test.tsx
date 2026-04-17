/**
 * @vitest-environment jsdom
 */
import { render } from '@tests/vitest/utils/render';
import { describe, expect, it } from 'vitest';

import { CrossrefFunderIcon, OrcidIcon, RorIcon } from '@/pages/LandingPages/components/PidIcons';

describe('PidIcons', () => {
    describe('OrcidIcon', () => {
        it('renders two SVG elements for light and dark mode', () => {
            const { container } = render(<OrcidIcon />);
            const svgs = container.querySelectorAll('svg');
            expect(svgs).toHaveLength(2);
        });

        it('has correct data-slot on both SVGs', () => {
            const { container } = render(<OrcidIcon />);
            const icons = container.querySelectorAll('[data-slot="orcid-icon"]');
            expect(icons).toHaveLength(2);
        });

        it('both SVGs are aria-hidden', () => {
            const { container } = render(<OrcidIcon />);
            const svgs = container.querySelectorAll('svg');
            svgs.forEach((svg) => {
                expect(svg).toHaveAttribute('aria-hidden', 'true');
            });
        });

        it('light mode SVG is visible by default, dark mode SVG is hidden', () => {
            const { container } = render(<OrcidIcon />);
            const svgs = container.querySelectorAll('svg');
            expect(svgs[0]).toHaveClass('dark:hidden');
            expect(svgs[0]).not.toHaveClass('hidden');
            expect(svgs[1]).toHaveClass('hidden', 'dark:block');
        });

        it('light mode SVG contains green circle (#A6CE39)', () => {
            const { container } = render(<OrcidIcon />);
            const lightSvg = container.querySelectorAll('svg')[0];
            const greenPath = lightSvg.querySelector('path[fill="#A6CE39"]');
            expect(greenPath).toBeInTheDocument();
        });

        it('dark mode SVG contains white fill only (#fff)', () => {
            const { container } = render(<OrcidIcon />);
            const darkSvg = container.querySelectorAll('svg')[1];
            const whitePaths = darkSvg.querySelectorAll('path[fill="#fff"]');
            expect(whitePaths.length).toBeGreaterThan(0);
            const greenPath = darkSvg.querySelector('path[fill="#A6CE39"]');
            expect(greenPath).not.toBeInTheDocument();
        });

        it('accepts custom className on both SVGs', () => {
            const { container } = render(<OrcidIcon className="h-6 w-6" />);
            const svgs = container.querySelectorAll('svg');
            svgs.forEach((svg) => {
                expect(svg).toHaveClass('h-6', 'w-6');
            });
        });

        it('uses viewBox 0 0 32 32 for official icon dimensions', () => {
            const { container } = render(<OrcidIcon />);
            const svgs = container.querySelectorAll('svg');
            svgs.forEach((svg) => {
                expect(svg).toHaveAttribute('viewBox', '0 0 32 32');
            });
        });
    });

    describe('RorIcon', () => {
        it('renders two SVG elements for light and dark mode', () => {
            const { container } = render(<RorIcon />);
            const svgs = container.querySelectorAll('svg');
            expect(svgs).toHaveLength(2);
        });

        it('has correct data-slot on both SVGs', () => {
            const { container } = render(<RorIcon />);
            const icons = container.querySelectorAll('[data-slot="ror-icon"]');
            expect(icons).toHaveLength(2);
        });

        it('both SVGs are aria-hidden', () => {
            const { container } = render(<RorIcon />);
            const svgs = container.querySelectorAll('svg');
            svgs.forEach((svg) => {
                expect(svg).toHaveAttribute('aria-hidden', 'true');
            });
        });

        it('light mode SVG is visible by default, dark mode SVG is hidden', () => {
            const { container } = render(<RorIcon />);
            const svgs = container.querySelectorAll('svg');
            expect(svgs[0]).toHaveClass('dark:hidden');
            expect(svgs[0]).not.toHaveClass('hidden');
            expect(svgs[1]).toHaveClass('hidden', 'dark:block');
        });

        it('uses w-auto for wordmark aspect ratio', () => {
            const { container } = render(<RorIcon />);
            const svgs = container.querySelectorAll('svg');
            svgs.forEach((svg) => {
                expect(svg).toHaveClass('w-auto');
            });
        });

        it('light mode SVG contains teal (#53BAA1) and dark (#202826) fills', () => {
            const { container } = render(<RorIcon />);
            const lightSvg = container.querySelectorAll('svg')[0];
            expect(lightSvg.querySelector('path[fill="#53BAA1"]')).toBeInTheDocument();
            expect(lightSvg.querySelector('path[fill="#202826"]')).toBeInTheDocument();
        });

        it('dark mode SVG contains only white (#fff) fills', () => {
            const { container } = render(<RorIcon />);
            const darkSvg = container.querySelectorAll('svg')[1];
            const whitePaths = darkSvg.querySelectorAll('path[fill="#fff"]');
            expect(whitePaths.length).toBeGreaterThan(0);
            expect(darkSvg.querySelector('path[fill="#53BAA1"]')).not.toBeInTheDocument();
            expect(darkSvg.querySelector('path[fill="#202826"]')).not.toBeInTheDocument();
        });

        it('accepts custom className on both SVGs', () => {
            const { container } = render(<RorIcon className="h-8" />);
            const svgs = container.querySelectorAll('svg');
            svgs.forEach((svg) => {
                expect(svg).toHaveClass('h-8');
            });
        });
    });

    describe('CrossrefFunderIcon', () => {
        it('renders an img element', () => {
            const { container } = render(<CrossrefFunderIcon />);
            const img = container.querySelector('img');
            expect(img).toBeInTheDocument();
        });

        it('has correct data-slot', () => {
            const { container } = render(<CrossrefFunderIcon />);
            expect(container.querySelector('[data-slot="crossref-funder-icon"]')).toBeInTheDocument();
        });

        it('is aria-hidden', () => {
            const { container } = render(<CrossrefFunderIcon />);
            const img = container.querySelector('img');
            expect(img).toHaveAttribute('aria-hidden', 'true');
        });

        it('has empty alt text for decorative image', () => {
            const { container } = render(<CrossrefFunderIcon />);
            const img = container.querySelector('img');
            expect(img).toHaveAttribute('alt', '');
        });

        it('points to official PNG icon', () => {
            const { container } = render(<CrossrefFunderIcon />);
            const img = container.querySelector('img');
            expect(img).toHaveAttribute('src', '/images/pid-icons/crossref-funder.png');
        });

        it('uses w-auto for proper aspect ratio', () => {
            const { container } = render(<CrossrefFunderIcon />);
            const img = container.querySelector('img');
            expect(img).toHaveClass('w-auto');
        });

        it('accepts custom className', () => {
            const { container } = render(<CrossrefFunderIcon className="h-6" />);
            const img = container.querySelector('img');
            expect(img).toHaveClass('h-6');
        });

        it('does not render any SVG element', () => {
            const { container } = render(<CrossrefFunderIcon />);
            expect(container.querySelector('svg')).not.toBeInTheDocument();
        });
    });
});
