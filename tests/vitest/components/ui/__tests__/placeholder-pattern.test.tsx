import '@testing-library/jest-dom/vitest';
import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { PlaceholderPattern } from '@/components/ui/placeholder-pattern';

describe('PlaceholderPattern', () => {
    it('renders svg with pattern and rect referencing it', () => {
        const { container } = render(<PlaceholderPattern className="test" />);
        const svg = container.querySelector('svg');
        expect(svg).toHaveClass('test');
        const pattern = container.querySelector('pattern');
        const rect = container.querySelector('rect');
        expect(pattern).toBeInTheDocument();
        expect(rect).toHaveAttribute('fill', `url(#${pattern!.id})`);
    });

    it('generates unique pattern ids for multiple instances', () => {
        const { container } = render(
            <div>
                <PlaceholderPattern />
                <PlaceholderPattern />
            </div>,
        );
        const patterns = container.querySelectorAll('pattern');
        expect(patterns.length).toBe(2);
        expect(patterns[0].id).not.toBe(patterns[1].id);
    });
});

