/**
 * @vitest-environment jsdom
 */
import { render, screen } from '@tests/vitest/utils/render';
import { describe, expect, it } from 'vitest';

import { DescriptionSection } from '@/pages/LandingPages/components/DescriptionSection';

describe('DescriptionSection', () => {
    it('renders abstract text', () => {
        const descriptions = [{ id: 1, value: 'This is the abstract text.', description_type: 'Abstract' }];
        render(<DescriptionSection descriptions={descriptions} />);
        expect(screen.getByTestId('abstract-text')).toHaveTextContent('This is the abstract text.');
    });

    it('returns null when no abstract exists', () => {
        const descriptions = [{ id: 1, value: 'Some methods.', description_type: 'Methods' }];
        const { container } = render(<DescriptionSection descriptions={descriptions} />);
        expect(container.innerHTML).toBe('');
    });

    it('renders methods section when available', () => {
        const descriptions = [
            { id: 1, value: 'Abstract text.', description_type: 'Abstract' },
            { id: 2, value: 'Methods description.', description_type: 'Methods' },
        ];
        render(<DescriptionSection descriptions={descriptions} />);
        expect(screen.getByTestId('methods-text')).toHaveTextContent('Methods description.');
    });

    it('does not render methods when not present', () => {
        const descriptions = [{ id: 1, value: 'Abstract text.', description_type: 'Abstract' }];
        render(<DescriptionSection descriptions={descriptions} />);
        expect(screen.queryByTestId('methods-section')).not.toBeInTheDocument();
    });

    it('matches abstract case-insensitively', () => {
        const descriptions = [{ id: 1, value: 'Lowercase abstract.', description_type: 'abstract' }];
        render(<DescriptionSection descriptions={descriptions} />);
        expect(screen.getByTestId('abstract-text')).toBeInTheDocument();
    });
});
