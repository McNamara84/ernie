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

    it('matches abstract case-insensitively', () => {
        const descriptions = [{ id: 1, value: 'Lowercase abstract.', description_type: 'abstract' }];
        render(<DescriptionSection descriptions={descriptions} sectionKey="abstract" />);
        expect(screen.getByTestId('abstract-text')).toBeInTheDocument();
    });
});
