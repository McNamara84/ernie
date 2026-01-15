/**
 * @vitest-environment jsdom
 */
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { PlaceholderSection } from '@/pages/LandingPages/components/PlaceholderSection';

describe('PlaceholderSection', () => {
    it('renders placeholder text', () => {
        render(<PlaceholderSection />);
        
        expect(screen.getByText('PLACEHOLDER')).toBeInTheDocument();
    });

    it('renders title when provided', () => {
        render(<PlaceholderSection title="Coming Soon" />);
        
        expect(screen.getByRole('heading', { level: 3, name: 'Coming Soon' })).toBeInTheDocument();
    });

    it('does not render title heading when not provided', () => {
        render(<PlaceholderSection />);
        
        expect(screen.queryByRole('heading')).not.toBeInTheDocument();
    });

    it('applies custom className when provided', () => {
        const { container } = render(<PlaceholderSection className="my-custom-class" />);
        
        const section = container.querySelector('.my-custom-class');
        expect(section).toBeInTheDocument();
    });

    it('has default styling classes', () => {
        const { container } = render(<PlaceholderSection />);
        
        const section = container.firstChild;
        expect(section).toHaveClass('rounded-lg', 'border', 'border-gray-200', 'bg-white', 'p-6', 'shadow-sm');
    });
});
