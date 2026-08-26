/**
 * @vitest-environment jsdom
 */
import { render, screen } from '@tests/vitest/utils/render';
import { describe, expect, it } from 'vitest';

import { DescriptionSection } from '@/pages/LandingPages/components/DescriptionSection';

describe('DescriptionSection', () => {
    it('renders abstract text', () => {
        const descriptions = [{ id: 1, value: 'This is the abstract text.', description_type: 'Abstract' }];
        render(<DescriptionSection descriptions={descriptions} sectionKey="abstract" />);
        expect(screen.getByTestId('abstract-text')).toHaveTextContent('This is the abstract text.');
    });

    it('renders normalized comparison symbols as text', () => {
        const descriptions = [{ id: 1, value: 'Concentrations >500 and <0.5.', description_type: 'Abstract' }];

        render(<DescriptionSection descriptions={descriptions} sectionKey="abstract" />);

        expect(screen.getByTestId('abstract-text')).toHaveTextContent('Concentrations >500 and <0.5.');
        expect(screen.getByTestId('abstract-text')).not.toHaveTextContent('&gt;500');
    });

    it('returns null when no matching description type exists', () => {
        const descriptions = [{ id: 1, value: 'Some methods.', description_type: 'Methods' }];
        const { container } = render(<DescriptionSection descriptions={descriptions} sectionKey="abstract" />);
        expect(container.innerHTML).toBe('');
    });

    it('renders methods section when available', () => {
        const descriptions = [
            { id: 1, value: 'Abstract text.', description_type: 'Abstract' },
            { id: 2, value: 'Methods description.', description_type: 'Methods' },
        ];
        render(<DescriptionSection descriptions={descriptions} sectionKey="methods" />);
        expect(screen.getByTestId('methods-text')).toHaveTextContent('Methods description.');
    });

    it('renders DataCite Other descriptions as Additional Information', () => {
        const descriptions = [{ id: 1, value: 'Supplementary context.', description_type: 'Other' }];

        render(<DescriptionSection descriptions={descriptions} sectionKey="other" />);

        expect(screen.getByRole('heading', { level: 3, name: 'Additional Information' })).toBeInTheDocument();
        expect(screen.getByTestId('other-text')).toHaveTextContent('Supplementary context.');
    });

    it('linkifies HTTPS URLs in plain text but leaves HTTP URLs as text', () => {
        const descriptions = [
            {
                id: 1,
                value: 'Current: https://example.org/data?q=1, legacy: http://legacy.example.org/data.',
                description_type: 'Abstract',
            },
        ];

        render(<DescriptionSection descriptions={descriptions} sectionKey="abstract" />);

        const link = screen.getByRole('link', { name: 'https://example.org/data?q=1' });
        expect(link).toHaveAttribute('href', 'https://example.org/data?q=1');
        expect(link).toHaveAttribute('target', '_blank');
        expect(link).toHaveAttribute('rel', 'noopener noreferrer');
        expect(screen.getByTestId('abstract-text')).toHaveTextContent(
            'Current: https://example.org/data?q=1, legacy: http://legacy.example.org/data.',
        );
        expect(screen.queryByRole('link', { name: 'http://legacy.example.org/data' })).not.toBeInTheDocument();
    });

    it('renders HTML-looking plain text safely', () => {
        const descriptions = [{ id: 1, value: '<script>alert("unsafe")</script> https://example.org', description_type: 'Abstract' }];

        render(<DescriptionSection descriptions={descriptions} sectionKey="abstract" />);

        const block = screen.getByTestId('abstract-text');
        expect(block).toHaveTextContent('<script>alert("unsafe")</script> https://example.org');
        expect(block.querySelector('script')).toBeNull();
        expect(screen.getByRole('link', { name: 'https://example.org' })).toBeInTheDocument();
    });

    it('renders multiple descriptions of the same type in data order', () => {
        const descriptions = [
            { id: 1, value: 'First technical block.', description_type: 'TechnicalInfo' },
            { id: 2, value: 'Second technical block.', description_type: 'Technical Info' },
        ];

        render(<DescriptionSection descriptions={descriptions} sectionKey="technical_info" />);

        const paragraphs = screen.getAllByText(/technical block\./i);
        expect(paragraphs).toHaveLength(2);
        expect(paragraphs[0]).toHaveTextContent('First technical block.');
        expect(paragraphs[1]).toHaveTextContent('Second technical block.');
    });

    it('labels each explicit language and sets the HTML lang attribute', () => {
        const descriptions = [
            { id: 1, value: 'Deutscher Abstract.', description_type: 'Abstract', language: 'de' },
            { id: 2, value: 'English abstract.', description_type: 'Abstract', language: 'en' },
        ];

        render(<DescriptionSection descriptions={descriptions} sectionKey="abstract" />);

        expect(screen.getByText('German (de)')).toBeInTheDocument();
        expect(screen.getByText('English (en)')).toBeInTheDocument();
        expect(screen.getByTestId('abstract-text').closest('article')).toHaveAttribute('lang', 'de');
        expect(screen.getByTestId('abstract-text-2').closest('article')).toHaveAttribute('lang', 'en');
    });

    it('does not claim a language when none is stored', () => {
        const descriptions = [{ id: 1, value: 'Unspecified abstract.', description_type: 'Abstract', language: null }];

        render(<DescriptionSection descriptions={descriptions} sectionKey="abstract" />);

        expect(screen.getByTestId('abstract-text').closest('article')).not.toHaveAttribute('lang');
        expect(screen.queryByText(/\((de|en)\)/)).not.toBeInTheDocument();
    });

    it('matches abstract case-insensitively', () => {
        const descriptions = [{ id: 1, value: 'Lowercase abstract.', description_type: 'abstract' }];
        render(<DescriptionSection descriptions={descriptions} sectionKey="abstract" />);
        expect(screen.getByTestId('abstract-text')).toBeInTheDocument();
    });

    it('renders stored landing page html instead of plain text fallback', () => {
        const descriptions = [
            {
                id: 1,
                value: 'Plain text fallback',
                landing_page_html: '<p>Formatted <strong>abstract</strong> with <a href="https://example.org">link</a>.</p>',
                description_type: 'Abstract',
            },
        ];

        render(<DescriptionSection descriptions={descriptions} sectionKey="abstract" />);

        const block = screen.getByTestId('abstract-text');
        expect(block.querySelector('strong')).toHaveTextContent('abstract');
        expect(block.querySelector('a')).toHaveAttribute('href', 'https://example.org');
        expect(block.querySelectorAll('a')).toHaveLength(1);
        expect(block).toHaveTextContent('Formatted abstract with link.');
        expect(block).not.toHaveTextContent('Plain text fallback');
    });
});
