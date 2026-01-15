import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { FileJsonIcon, FileXmlIcon } from '@/components/icons/file-icons';

describe('FileJsonIcon', () => {
    it('renders an SVG element', () => {
        render(<FileJsonIcon data-testid="json-icon" />);

        const svg = screen.getByTestId('json-icon');
        expect(svg.tagName.toLowerCase()).toBe('svg');
    });

    it('has correct default dimensions', () => {
        render(<FileJsonIcon data-testid="json-icon" />);

        const svg = screen.getByTestId('json-icon');
        expect(svg).toHaveAttribute('width', '24');
        expect(svg).toHaveAttribute('height', '24');
    });

    it('accepts custom props', () => {
        render(<FileJsonIcon data-testid="json-icon" className="custom-class" />);

        expect(screen.getByTestId('json-icon')).toHaveClass('custom-class');
    });

    it('uses currentColor for fill', () => {
        render(<FileJsonIcon data-testid="json-icon" />);

        expect(screen.getByTestId('json-icon')).toHaveAttribute('fill', 'currentColor');
    });
});

describe('FileXmlIcon', () => {
    it('renders an SVG element', () => {
        render(<FileXmlIcon data-testid="xml-icon" />);

        const svg = screen.getByTestId('xml-icon');
        expect(svg.tagName.toLowerCase()).toBe('svg');
    });

    it('has correct default dimensions', () => {
        render(<FileXmlIcon data-testid="xml-icon" />);

        const svg = screen.getByTestId('xml-icon');
        expect(svg).toHaveAttribute('width', '24');
        expect(svg).toHaveAttribute('height', '24');
    });

    it('accepts custom props', () => {
        render(<FileXmlIcon data-testid="xml-icon" className="custom-class" />);

        expect(screen.getByTestId('xml-icon')).toHaveClass('custom-class');
    });

    it('uses currentColor for fill', () => {
        render(<FileXmlIcon data-testid="xml-icon" />);

        expect(screen.getByTestId('xml-icon')).toHaveAttribute('fill', 'currentColor');
    });
});
