/**
 * @vitest-environment jsdom
 */
import { render, screen } from '@tests/vitest/utils/render';
import { describe, expect, it } from 'vitest';

import { DarkModeImage } from '@/pages/LandingPages/components/DarkModeImage';

describe('DarkModeImage', () => {
    it('renders a picture element', () => {
        const { container } = render(<DarkModeImage lightSrc="/light.png" darkSrc="/dark.png" alt="Test" />);
        expect(container.querySelector('picture')).toBeInTheDocument();
    });

    it('has correct data-slot', () => {
        const { container } = render(<DarkModeImage lightSrc="/light.png" darkSrc="/dark.png" alt="Test" />);
        expect(container.querySelector('[data-slot="dark-mode-image"]')).toBeInTheDocument();
    });

    it('renders an img with lightSrc as default src', () => {
        render(<DarkModeImage lightSrc="/light.png" darkSrc="/dark.png" alt="Test Logo" />);
        const img = screen.getByAltText('Test Logo');
        expect(img).toHaveAttribute('src', '/light.png');
    });

    it('renders a source element with dark media query', () => {
        const { container } = render(<DarkModeImage lightSrc="/light.png" darkSrc="/dark.png" alt="Test" />);
        const source = container.querySelector('source');
        expect(source).toHaveAttribute('srcset', '/dark.png');
        expect(source).toHaveAttribute('media', '(prefers-color-scheme: dark)');
    });

    it('applies className to the img element', () => {
        render(<DarkModeImage lightSrc="/light.png" darkSrc="/dark.png" alt="Test" className="h-12" />);
        const img = screen.getByAltText('Test');
        expect(img).toHaveClass('h-12');
    });
});
