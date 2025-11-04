import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { DataCiteIcon } from '@/components/icons/datacite-icon';

describe('DataCiteIcon', () => {
    it('renders SVG element', () => {
        const { container } = render(<DataCiteIcon />);
        const svg = container.querySelector('svg');
        expect(svg).toBeInTheDocument();
    });

    it('applies custom className', () => {
        const { container } = render(<DataCiteIcon className="custom-class" />);
        const svg = container.querySelector('svg');
        expect(svg).toHaveClass('custom-class');
    });

    it('applies size classes correctly', () => {
        const { container } = render(<DataCiteIcon className="size-8" />);
        const svg = container.querySelector('svg');
        expect(svg).toHaveClass('size-8');
    });

    it('has correct viewBox for proper scaling', () => {
        const { container } = render(<DataCiteIcon />);
        const svg = container.querySelector('svg');
        expect(svg).toHaveAttribute('viewBox');
    });

    it('contains path elements', () => {
        const { container } = render(<DataCiteIcon />);
        const paths = container.querySelectorAll('path');
        expect(paths.length).toBeGreaterThan(0);
    });

    it('is accessible with default attributes', () => {
        const { container } = render(<DataCiteIcon />);
        const svg = container.querySelector('svg');
        
        // SVG should have aria-hidden by default or role="img"
        const isAccessible = 
            svg?.hasAttribute('aria-hidden') || 
            svg?.getAttribute('role') === 'img';
        
        expect(isAccessible).toBeTruthy();
    });

    it('spreads additional props to SVG element', () => {
        const { container } = render(<DataCiteIcon data-testid="datacite-logo" />);
        const svg = container.querySelector('svg');
        expect(svg).toHaveAttribute('data-testid', 'datacite-logo');
    });
});
