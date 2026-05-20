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
    breadcrumb_path: null,
});

const gcmdKeyword = (id: number, subject: string, overrides: Partial<LandingPageSubject> = {}): LandingPageSubject => ({
    id,
    subject,
    subject_scheme: 'Science Keywords',
    scheme_uri: 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords',
    value_uri: 'https://gcmd.earthdata.nasa.gov/kms/concept/science-seismology',
    classification_code: null,
    breadcrumb_path: null,
    ...overrides,
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
        const subjects = [gcmdKeyword(1, 'Earth Science'), freeKeyword(2, 'seismology')];
        render(<KeywordsSection subjects={subjects} />);
        expect(screen.getByTestId('thesauri-keywords-list')).toBeInTheDocument();
        expect(screen.getByTestId('keywords-list')).toBeInTheDocument();
    });

    it('keyword badges link to portal', () => {
        render(<KeywordsSection subjects={[freeKeyword(1, 'geology')]} />);
        const link = screen.getByRole('link', { name: 'geology' });
        const searchLink = screen.getByRole('link', { name: /Search for geology in the portal/i });

        expect(link).toHaveAttribute('href', '/portal?free_keywords%5B%5D=geology');
        expect(link).not.toHaveAttribute('target');
        expect(searchLink).toHaveAttribute('href', '/portal?free_keywords%5B%5D=geology');
    });

    it('links controlled keywords through the portal thesaurus filter', () => {
        render(<KeywordsSection subjects={[gcmdKeyword(1, 'SEISMOLOGY')]} />);

        const link = screen.getByRole('link', { name: /^SEISMOLOGY$/i });

        expect(link).toHaveAttribute(
            'href',
            '/portal?thesaurus_keywords%5B%5D=https%3A%2F%2Fgcmd.earthdata.nasa.gov%2Fkms%2Fconcept%2Fscience-seismology',
        );
    });

    it('renders known thesaurus aliases under their canonical landing page group', () => {
        render(
            <KeywordsSection
                subjects={[
                    gcmdKeyword(1, 'SEISMOLOGY', {
                        subject_scheme: 'NASA/GCMD Earth Science Keywords',
                    }),
                ]}
            />,
        );

        expect(screen.getByTestId('thesauri-keywords-list')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /^SEISMOLOGY$/i })).toHaveAttribute(
            'href',
            '/portal?thesaurus_keywords%5B%5D=https%3A%2F%2Fgcmd.earthdata.nasa.gov%2Fkms%2Fconcept%2Fscience-seismology',
        );
    });

    it('links controlled keywords through a scheme-scoped classification code when value_uri is missing', () => {
        render(
            <KeywordsSection
                subjects={[
                    gcmdKeyword(1, 'SEISMOLOGY', {
                        value_uri: null,
                        classification_code: '310607',
                    }),
                ]}
            />,
        );

        const link = screen.getByRole('link', { name: /^SEISMOLOGY$/i });

        expect(link).toHaveAttribute(
            'href',
            '/portal?thesaurus_keywords%5B%5D=Science+Keywords%3A%3A310607',
        );
    });

    it('falls back to the legacy keyword filter when a controlled keyword has no stable identifier', () => {
        render(
            <KeywordsSection
                subjects={[
                    gcmdKeyword(1, 'SEISMOLOGY', {
                        value_uri: null,
                        classification_code: null,
                    }),
                ]}
            />,
        );

        const link = screen.getByRole('link', { name: /^SEISMOLOGY$/i });
        const searchLink = screen.getByRole('link', { name: /Search for SEISMOLOGY in the portal/i });

        expect(link).toHaveAttribute('href', '/portal?keywords%5B%5D=SEISMOLOGY');
        expect(searchLink).toHaveAttribute('href', '/portal?keywords%5B%5D=SEISMOLOGY');
    });

    it('shows a search prompt on the magnifying-glass action', () => {
        render(<KeywordsSection subjects={[gcmdKeyword(1, 'SEISMOLOGY')]} />);

        const searchLink = screen.getByRole('link', { name: /Search for SEISMOLOGY in the portal/i });

        expect(searchLink).toHaveAttribute('title', 'Search for SEISMOLOGY in the portal');
    });

    it('renders full breadcrumb paths with three segments without omission', () => {
        render(
            <KeywordsSection
                subjects={[
                    gcmdKeyword(1, 'SEISMOLOGY', {
                        breadcrumb_path: 'EARTH SCIENCE > SOLID EARTH > SEISMOLOGY',
                    }),
                ]}
            />,
        );

        expect(screen.getByText('EARTH SCIENCE > SOLID EARTH > SEISMOLOGY')).toBeInTheDocument();
    });

    it('renders compact breadcrumb labels with an omission marker for deeper paths', () => {
        render(
            <KeywordsSection
                subjects={[
                    gcmdKeyword(1, 'ROCK GLACIERS', {
                        breadcrumb_path: 'EARTH SCIENCE > CRYOSPHERE > FROZEN GROUND > ROCK GLACIERS',
                    }),
                ]}
            />,
        );

        expect(screen.getByText('EARTH SCIENCE > ... > FROZEN GROUND > ROCK GLACIERS')).toBeInTheDocument();
    });

    it('stores the full breadcrumb path on hover via the title attribute', () => {
        render(
            <KeywordsSection
                subjects={[
                    gcmdKeyword(1, 'SEISMOLOGY', {
                        breadcrumb_path: 'EARTH SCIENCE > SOLID EARTH > SEISMOLOGY',
                    }),
                ]}
            />,
        );

        const link = screen.getByRole('link', { name: /^EARTH SCIENCE > SOLID EARTH > SEISMOLOGY$/i });

        expect(link).toHaveAttribute('title', 'EARTH SCIENCE > SOLID EARTH > SEISMOLOGY');
    });

    it('does not show expand button when under threshold', () => {
        const subjects = Array.from({ length: 5 }, (_, i) => freeKeyword(i + 1, `keyword-${i + 1}`));
        render(<KeywordsSection subjects={subjects} />);
        expect(screen.queryByText(/Show all/)).not.toBeInTheDocument();
    });

    it('shows expand button when above threshold', () => {
        const subjects = Array.from({ length: 12 }, (_, i) => freeKeyword(i + 1, `keyword-${i + 1}`));
        render(<KeywordsSection subjects={subjects} />);
        expect(screen.getByText('Show all 12 keywords')).toBeInTheDocument();
    });
});
