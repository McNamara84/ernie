/**
 * @vitest-environment jsdom
 */
import { render, screen } from '@tests/vitest/utils/render';
import { describe, expect, it } from 'vitest';

import { KeywordsSection } from '@/pages/LandingPages/components/KeywordsSection';
import type { LandingPageSubject } from '@/types/landing-page';

const freeKeyword = (id: number, subject: string): LandingPageSubject => ({
    id,
    subject,
    subject_scheme: null,
    scheme_uri: null,
    value_uri: null,
    classification_code: null,
});

const gcmdKeyword = (id: number, subject: string): LandingPageSubject => ({
    id,
    subject,
    subject_scheme: 'Science Keywords',
    scheme_uri: 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords',
    value_uri: null,
    classification_code: null,
});

describe('KeywordsSection', () => {
    it('returns null when no subjects', () => {
        const { container } = render(<KeywordsSection subjects={[]} />);
        expect(container.innerHTML).toBe('');
    });

    it('renders free keywords', () => {
        render(<KeywordsSection subjects={[freeKeyword(1, 'seismology')]} />);
        expect(screen.getByTestId('subjects-section')).toBeInTheDocument();
        expect(screen.getByText('seismology')).toBeInTheDocument();
    });

    it('renders thesauri keywords with badge styling', () => {
        render(<KeywordsSection subjects={[gcmdKeyword(1, 'Earth Science')]} />);
        expect(screen.getByTestId('thesauri-keywords-list')).toBeInTheDocument();
        expect(screen.getByText('Earth Science')).toBeInTheDocument();
    });

    it('renders separator between thesauri and free keywords', () => {
        const subjects = [
            gcmdKeyword(1, 'Earth Science'),
            freeKeyword(2, 'seismology'),
        ];
        render(<KeywordsSection subjects={subjects} />);
        expect(screen.getByTestId('thesauri-keywords-list')).toBeInTheDocument();
        expect(screen.getByTestId('keywords-list')).toBeInTheDocument();
    });

    it('keyword badges link to portal', () => {
        render(<KeywordsSection subjects={[freeKeyword(1, 'geology')]} />);
        const link = screen.getByText('geology').closest('a');
        expect(link).toHaveAttribute('href', '/portal?keywords[]=geology');
        expect(link).toHaveAttribute('target', '_blank');
    });

    it('does not show expand button when under threshold', () => {
        const subjects = Array.from({ length: 5 }, (_, i) =>
            freeKeyword(i + 1, `keyword-${i + 1}`),
        );
        render(<KeywordsSection subjects={subjects} />);
        expect(screen.queryByText(/Show all/)).not.toBeInTheDocument();
    });

    it('shows expand button when above threshold', () => {
        const subjects = Array.from({ length: 12 }, (_, i) =>
            freeKeyword(i + 1, `keyword-${i + 1}`),
        );
        render(<KeywordsSection subjects={subjects} />);
        expect(screen.getByText('Show all 12 keywords')).toBeInTheDocument();
    });
});
