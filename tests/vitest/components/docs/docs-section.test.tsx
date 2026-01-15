import { render, screen } from '@testing-library/react';
import { FileText } from 'lucide-react';
import { describe, expect, it } from 'vitest';

import { DocsSection } from '@/components/docs/docs-section';

describe('DocsSection', () => {
    it('renders the section with title and icon', () => {
        render(
            <DocsSection id="test-section" title="Test Section" icon={FileText}>
                <p>Test content</p>
            </DocsSection>,
        );

        expect(screen.getByText('Test Section')).toBeInTheDocument();
    });

    it('renders the children content', () => {
        render(
            <DocsSection id="test-section" title="Test Section" icon={FileText}>
                <p>This is the section content</p>
            </DocsSection>,
        );

        expect(screen.getByText('This is the section content')).toBeInTheDocument();
    });

    it('sets the correct id on the section', () => {
        render(
            <DocsSection id="my-custom-id" title="Test Section" icon={FileText}>
                <p>Content</p>
            </DocsSection>,
        );

        const section = document.getElementById('my-custom-id');
        expect(section).toBeInTheDocument();
    });

    it('renders the icon in a styled container', () => {
        const { container } = render(
            <DocsSection id="test-section" title="Test Section" icon={FileText}>
                <p>Content</p>
            </DocsSection>,
        );

        const iconContainer = container.querySelector('.bg-primary\\/10');
        expect(iconContainer).toBeInTheDocument();
    });

    it('applies scroll-mt-20 class for scroll margin', () => {
        const { container } = render(
            <DocsSection id="test-section" title="Test Section" icon={FileText}>
                <p>Content</p>
            </DocsSection>,
        );

        const section = container.querySelector('section');
        expect(section).toHaveClass('scroll-mt-20');
    });
});
