/**
 * @vitest-environment jsdom
 */
import { render } from '@tests/vitest/utils/render';
import { describe, expect, it } from 'vitest';

import { CrossrefFunderIcon, OrcidIcon, RorIcon } from '@/pages/LandingPages/components/PidIcons';

/**
 * Helper to select the light-mode variant (visible by default, hidden in dark mode).
 */
function getLightVariant(container: HTMLElement, slot: string): Element {
    const candidates = container.querySelectorAll(`[data-slot="${slot}"]`);
    const light = Array.from(candidates).find((el) => {
        const cls = el.getAttribute('class') ?? '';
        return cls.includes('dark:hidden') && !cls.split(' ').includes('hidden');
    });
    if (!light) throw new Error(`No light-mode variant found for data-slot="${slot}"`);
    return light;
}

/**
 * Helper to select the dark-mode variant (hidden by default, shown in dark mode).
 */
function getDarkVariant(container: HTMLElement, slot: string): Element {
    const candidates = container.querySelectorAll(`[data-slot="${slot}"]`);
    const dark = Array.from(candidates).find((el) => {
        const cls = el.getAttribute('class') ?? '';
        return cls.split(' ').includes('hidden') && cls.includes('dark:block');
    });
    if (!dark) throw new Error(`No dark-mode variant found for data-slot="${slot}"`);
    return dark;
}

describe('PidIcons', () => {
    describe('OrcidIcon', () => {
        it('renders two SVG elements for light and dark mode', () => {
            const { container } = render(<OrcidIcon />);
            const icons = container.querySelectorAll('[data-slot="orcid-icon"]');
            expect(icons).toHaveLength(2);
        });

        it('has correct data-slot on both SVGs', () => {
            const { container } = render(<OrcidIcon />);
            const icons = container.querySelectorAll('[data-slot="orcid-icon"]');
            icons.forEach((icon) => {
                expect(icon.tagName.toLowerCase()).toBe('svg');
            });
        });

        it('both SVGs are aria-hidden', () => {
            const { container } = render(<OrcidIcon />);
            const light = getLightVariant(container, 'orcid-icon');
            const dark = getDarkVariant(container, 'orcid-icon');
            expect(light).toHaveAttribute('aria-hidden', 'true');
            expect(dark).toHaveAttribute('aria-hidden', 'true');
        });

        it('light mode SVG is visible by default, dark mode SVG is hidden', () => {
            const { container } = render(<OrcidIcon />);
            const light = getLightVariant(container, 'orcid-icon');
            const dark = getDarkVariant(container, 'orcid-icon');
            expect(light).toHaveClass('dark:hidden');
            expect(light).not.toHaveClass('hidden');
            expect(dark).toHaveClass('hidden', 'dark:block');
        });

        it('light mode SVG contains green circle (#A6CE39)', () => {
            const { container } = render(<OrcidIcon />);
            const light = getLightVariant(container, 'orcid-icon');
            expect(light.querySelector('path[fill="#A6CE39"]')).toBeInTheDocument();
        });

        it('dark mode SVG contains white fill only (#fff)', () => {
            const { container } = render(<OrcidIcon />);
            const dark = getDarkVariant(container, 'orcid-icon');
            const whitePaths = dark.querySelectorAll('path[fill="#fff"]');
            expect(whitePaths.length).toBeGreaterThan(0);
            expect(dark.querySelector('path[fill="#A6CE39"]')).not.toBeInTheDocument();
        });

        it('accepts custom className on both SVGs', () => {
            const { container } = render(<OrcidIcon className="h-6 w-6" />);
            const light = getLightVariant(container, 'orcid-icon');
            const dark = getDarkVariant(container, 'orcid-icon');
            expect(light).toHaveClass('h-6', 'w-6');
            expect(dark).toHaveClass('h-6', 'w-6');
        });

        it('uses viewBox 0 0 32 32 for official icon dimensions', () => {
            const { container } = render(<OrcidIcon />);
            const light = getLightVariant(container, 'orcid-icon');
            const dark = getDarkVariant(container, 'orcid-icon');
            expect(light).toHaveAttribute('viewBox', '0 0 32 32');
            expect(dark).toHaveAttribute('viewBox', '0 0 32 32');
        });
    });

    describe('RorIcon', () => {
        it('renders two SVG elements for light and dark mode', () => {
            const { container } = render(<RorIcon />);
            const icons = container.querySelectorAll('[data-slot="ror-icon"]');
            expect(icons).toHaveLength(2);
        });

        it('has correct data-slot on both SVGs', () => {
            const { container } = render(<RorIcon />);
            const icons = container.querySelectorAll('[data-slot="ror-icon"]');
            icons.forEach((icon) => {
                expect(icon.tagName.toLowerCase()).toBe('svg');
            });
        });

        it('both SVGs are aria-hidden', () => {
            const { container } = render(<RorIcon />);
            const light = getLightVariant(container, 'ror-icon');
            const dark = getDarkVariant(container, 'ror-icon');
            expect(light).toHaveAttribute('aria-hidden', 'true');
            expect(dark).toHaveAttribute('aria-hidden', 'true');
        });

        it('light mode SVG is visible by default, dark mode SVG is hidden', () => {
            const { container } = render(<RorIcon />);
            const light = getLightVariant(container, 'ror-icon');
            const dark = getDarkVariant(container, 'ror-icon');
            expect(light).toHaveClass('dark:hidden');
            expect(light).not.toHaveClass('hidden');
            expect(dark).toHaveClass('hidden', 'dark:block');
        });

        it('enforces w-auto for wordmark aspect ratio', () => {
            const { container } = render(<RorIcon />);
            const light = getLightVariant(container, 'ror-icon');
            const dark = getDarkVariant(container, 'ror-icon');
            expect(light).toHaveClass('w-auto');
            expect(dark).toHaveClass('w-auto');
        });

        it('preserves w-auto even when className contains a width override', () => {
            const { container } = render(<RorIcon className="w-6" />);
            const light = getLightVariant(container, 'ror-icon');
            const dark = getDarkVariant(container, 'ror-icon');
            expect(light).toHaveClass('w-auto');
            expect(dark).toHaveClass('w-auto');
        });

        it('light mode SVG contains teal (#53BAA1) and dark (#202826) fills', () => {
            const { container } = render(<RorIcon />);
            const light = getLightVariant(container, 'ror-icon');
            expect(light.querySelector('path[fill="#53BAA1"]')).toBeInTheDocument();
            expect(light.querySelector('path[fill="#202826"]')).toBeInTheDocument();
        });

        it('dark mode SVG contains only white (#fff) fills', () => {
            const { container } = render(<RorIcon />);
            const dark = getDarkVariant(container, 'ror-icon');
            const whitePaths = dark.querySelectorAll('path[fill="#fff"]');
            expect(whitePaths.length).toBeGreaterThan(0);
            expect(dark.querySelector('path[fill="#53BAA1"]')).not.toBeInTheDocument();
            expect(dark.querySelector('path[fill="#202826"]')).not.toBeInTheDocument();
        });

        it('accepts custom className on both SVGs', () => {
            const { container } = render(<RorIcon className="h-8" />);
            const light = getLightVariant(container, 'ror-icon');
            const dark = getDarkVariant(container, 'ror-icon');
            expect(light).toHaveClass('h-8');
            expect(dark).toHaveClass('h-8');
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

        it('preserves w-auto even when className contains a width override', () => {
            const { container } = render(<CrossrefFunderIcon className="w-6" />);
            const img = container.querySelector('img');
            expect(img).toHaveClass('w-auto');
            expect(img).not.toHaveClass('w-6');
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
