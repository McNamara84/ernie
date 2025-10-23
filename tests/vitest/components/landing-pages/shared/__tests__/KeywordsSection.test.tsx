import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import KeywordsSection from '@/components/landing-pages/shared/KeywordsSection';

describe('KeywordsSection', () => {
    const mockResourceWithBothKeywords = {
        keywords: [
            { id: 1, keyword: 'Seismology' },
            { id: 2, keyword: 'Earthquake' },
            { id: 3, keyword: 'Ground Motion' },
        ],
        controlled_keywords: [
            {
                id: 1,
                keyword_id: 101,
                text: 'Seismic Waves',
                path: 'Earth Science>Solid Earth>Seismology>Seismic Waves',
                scheme: 'gcmd:sciencekeywords',
                scheme_uri: 'https://gcmd.earthdata.nasa.gov/',
            },
            {
                id: 2,
                keyword_id: 102,
                text: 'GPS',
                path: 'Earth Science>Solid Earth>Geodesy>GPS',
                scheme: 'gcmd:sciencekeywords',
            },
            {
                id: 3,
                keyword_id: 201,
                text: 'GRACE',
                scheme: 'gcmd:platforms',
            },
            {
                id: 4,
                text: 'Steel Alloy',
                scheme: 'msl',
            },
        ],
    };

    const mockResourceFreeOnly = {
        keywords: [
            { keyword: 'Climate Change' },
            { keyword: 'Temperature' },
        ],
    };

    const mockResourceControlledOnly = {
        controlled_keywords: [
            {
                text: 'Atmospheric Temperature',
                scheme: 'gcmd:sciencekeywords',
                path: 'Atmosphere>Atmospheric Temperature',
            },
        ],
    };

    const mockResourceNoKeywords = {
        keywords: [],
        controlled_keywords: [],
    };

    const mockResourceMissingKeywords = {};

    describe('Rendering', () => {
        it('should render keywords section with heading', () => {
            render(<KeywordsSection resource={mockResourceWithBothKeywords} />);

            expect(screen.getByRole('heading', { name: /^keywords$/i })).toBeInTheDocument();
        });

        it('should render custom heading', () => {
            render(
                <KeywordsSection resource={mockResourceWithBothKeywords} heading="Subject Terms" />,
            );

            expect(screen.getByRole('heading', { name: /subject terms/i })).toBeInTheDocument();
        });

        it('should not render when no keywords', () => {
            const { container } = render(<KeywordsSection resource={mockResourceNoKeywords} />);

            expect(container).toBeEmptyDOMElement();
        });

        it('should not render when keywords property missing', () => {
            const { container } = render(<KeywordsSection resource={mockResourceMissingKeywords} />);

            expect(container).toBeEmptyDOMElement();
        });

        it('should show tag icon', () => {
            render(<KeywordsSection resource={mockResourceWithBothKeywords} />);

            const icons = document.querySelectorAll('[aria-hidden="true"]');
            expect(icons.length).toBeGreaterThan(0);
        });
    });

    describe('Free Keywords Display', () => {
        it('should display all free keywords', () => {
            render(<KeywordsSection resource={mockResourceWithBothKeywords} />);

            expect(screen.getByText('Seismology')).toBeInTheDocument();
            expect(screen.getByText('Earthquake')).toBeInTheDocument();
            expect(screen.getByText('Ground Motion')).toBeInTheDocument();
        });

        it('should show "Free Keywords" subheading', () => {
            render(<KeywordsSection resource={mockResourceWithBothKeywords} />);

            expect(screen.getByRole('heading', { name: /free keywords/i })).toBeInTheDocument();
        });

        it('should render free keywords only when no controlled keywords', () => {
            render(<KeywordsSection resource={mockResourceFreeOnly} />);

            expect(screen.getByText('Climate Change')).toBeInTheDocument();
            expect(screen.getByText('Temperature')).toBeInTheDocument();
            expect(
                screen.queryByRole('heading', { name: /controlled keywords/i }),
            ).not.toBeInTheDocument();
        });

        it('should not show free keywords section when empty', () => {
            render(<KeywordsSection resource={mockResourceControlledOnly} />);

            expect(
                screen.queryByRole('heading', { name: /free keywords/i }),
            ).not.toBeInTheDocument();
        });
    });

    describe('Controlled Keywords Display', () => {
        it('should display all controlled keywords', () => {
            render(<KeywordsSection resource={mockResourceWithBothKeywords} />);

            expect(screen.getByText('Seismic Waves')).toBeInTheDocument();
            expect(screen.getByText('GPS')).toBeInTheDocument();
            expect(screen.getByText('GRACE')).toBeInTheDocument();
            expect(screen.getByText('Steel Alloy')).toBeInTheDocument();
        });

        it('should show "Controlled Keywords" subheading', () => {
            render(<KeywordsSection resource={mockResourceWithBothKeywords} />);

            expect(
                screen.getByRole('heading', { name: /controlled keywords/i }),
            ).toBeInTheDocument();
        });

        it('should render controlled keywords only when no free keywords', () => {
            render(<KeywordsSection resource={mockResourceControlledOnly} />);

            expect(screen.getByText('Atmospheric Temperature')).toBeInTheDocument();
            expect(
                screen.queryByRole('heading', { name: /free keywords/i }),
            ).not.toBeInTheDocument();
        });

        it('should not show controlled keywords section when empty', () => {
            render(<KeywordsSection resource={mockResourceFreeOnly} />);

            expect(
                screen.queryByRole('heading', { name: /controlled keywords/i }),
            ).not.toBeInTheDocument();
        });
    });

    describe('Scheme Labels and Colors', () => {
        it('should display GCMD Science scheme label', () => {
            render(<KeywordsSection resource={mockResourceWithBothKeywords} />);

            const labels = screen.getAllByText('GCMD Science');
            expect(labels.length).toBe(2); // Two science keywords
        });

        it('should display GCMD Platform scheme label', () => {
            render(<KeywordsSection resource={mockResourceWithBothKeywords} />);

            expect(screen.getByText('GCMD Platform')).toBeInTheDocument();
        });

        it('should display MSL scheme label', () => {
            render(<KeywordsSection resource={mockResourceWithBothKeywords} />);

            expect(screen.getByText('MSL')).toBeInTheDocument();
        });

        it('should apply blue color to GCMD keywords', () => {
            const { container } = render(<KeywordsSection resource={mockResourceWithBothKeywords} />);

            const gcmdKeywords = container.querySelectorAll('.bg-blue-100');
            expect(gcmdKeywords.length).toBeGreaterThan(0);
        });

        it('should apply purple color to MSL keywords', () => {
            const { container } = render(<KeywordsSection resource={mockResourceWithBothKeywords} />);

            const mslKeywords = container.querySelectorAll('.bg-purple-100');
            expect(mslKeywords.length).toBe(1);
        });

        it('should handle unknown scheme gracefully', () => {
            const resourceWithUnknownScheme = {
                controlled_keywords: [
                    {
                        text: 'Unknown Keyword',
                        scheme: 'unknown:custom',
                    },
                ],
            };

            render(<KeywordsSection resource={resourceWithUnknownScheme} />);

            expect(screen.getByText('UNKNOWN:CUSTOM')).toBeInTheDocument();
        });
    });

    describe('Hierarchical Paths', () => {
        it('should display hierarchical paths by default', () => {
            render(<KeywordsSection resource={mockResourceWithBothKeywords} />);

            expect(
                screen.getByText(/Earth Science › Solid Earth › Seismology › Seismic Waves/),
            ).toBeInTheDocument();
        });

        it('should format paths with arrow separators', () => {
            render(<KeywordsSection resource={mockResourceWithBothKeywords} />);

            const pathElement = screen.getByText(/Earth Science › Solid Earth › Geodesy › GPS/);
            expect(pathElement).toBeInTheDocument();
            expect(pathElement.textContent).toContain('›');
        });

        it('should hide paths when showPaths=false', () => {
            render(<KeywordsSection resource={mockResourceWithBothKeywords} showPaths={false} />);

            expect(
                screen.queryByText(/Earth Science › Solid Earth › Seismology › Seismic Waves/),
            ).not.toBeInTheDocument();
        });

        it('should not show path when path is missing', () => {
            render(<KeywordsSection resource={mockResourceWithBothKeywords} />);

            // GRACE platform has no path
            const graceKeyword = screen.getByText('GRACE').closest('div');
            const pathInGrace = graceKeyword?.querySelector('.text-xs.text-gray-500');
            expect(pathInGrace).not.toBeInTheDocument();
        });

        it('should handle paths with pipe separators', () => {
            const resourceWithPipePath = {
                controlled_keywords: [
                    {
                        text: 'Test Keyword',
                        scheme: 'gcmd:sciencekeywords',
                        path: 'Category A|Category B|Category C',
                    },
                ],
            };

            render(<KeywordsSection resource={resourceWithPipePath} />);

            expect(screen.getByText(/Category A \| Category B \| Category C/)).toBeInTheDocument();
        });
    });

    describe('Info Box', () => {
        it('should display info box when controlled keywords exist', () => {
            render(<KeywordsSection resource={mockResourceWithBothKeywords} />);

            expect(screen.getByText(/Controlled vocabularies:/)).toBeInTheDocument();
            expect(screen.getByText(/GCMD/)).toBeInTheDocument();
            expect(screen.getByText(/Materials Science and Engineering/)).toBeInTheDocument();
        });

        it('should not display info box when only free keywords', () => {
            render(<KeywordsSection resource={mockResourceFreeOnly} />);

            expect(screen.queryByText(/Controlled vocabularies:/)).not.toBeInTheDocument();
        });
    });

    describe('Multiple Keywords Layout', () => {
        it('should use flex-wrap for keyword badges', () => {
            const { container } = render(<KeywordsSection resource={mockResourceWithBothKeywords} />);

            const freeKeywordContainer = container.querySelector('.flex.flex-wrap');
            expect(freeKeywordContainer).toBeInTheDocument();
        });

        it('should render keywords without IDs using index as key', () => {
            const resourceWithoutIds = {
                keywords: [{ keyword: 'Keyword 1' }, { keyword: 'Keyword 2' }],
                controlled_keywords: [
                    { text: 'Controlled 1', scheme: 'gcmd:sciencekeywords' },
                    { text: 'Controlled 2', scheme: 'msl' },
                ],
            };

            render(<KeywordsSection resource={resourceWithoutIds} />);

            expect(screen.getByText('Keyword 1')).toBeInTheDocument();
            expect(screen.getByText('Keyword 2')).toBeInTheDocument();
            expect(screen.getByText('Controlled 1')).toBeInTheDocument();
            expect(screen.getByText('Controlled 2')).toBeInTheDocument();
        });
    });

    describe('Edge Cases', () => {
        it('should handle very long keyword text', () => {
            const resourceWithLongKeyword = {
                keywords: [
                    {
                        keyword:
                            'Very Long Keyword That Contains Multiple Words And Should Wrap Properly In The UI',
                    },
                ],
            };

            render(<KeywordsSection resource={resourceWithLongKeyword} />);

            expect(
                screen.getByText(
                    /Very Long Keyword That Contains Multiple Words And Should Wrap Properly In The UI/,
                ),
            ).toBeInTheDocument();
        });

        it('should handle very long controlled keyword path', () => {
            const resourceWithLongPath = {
                controlled_keywords: [
                    {
                        text: 'Deep Keyword',
                        scheme: 'gcmd:sciencekeywords',
                        path: 'Level1>Level2>Level3>Level4>Level5>Level6>Level7>Deep Keyword',
                    },
                ],
            };

            render(<KeywordsSection resource={resourceWithLongPath} />);

            expect(screen.getByText('Deep Keyword')).toBeInTheDocument();
            expect(
                screen.getByText(
                    /Level1 › Level2 › Level3 › Level4 › Level5 › Level6 › Level7 › Deep Keyword/,
                ),
            ).toBeInTheDocument();
        });

        it('should handle special characters in keywords', () => {
            const resourceWithSpecialChars = {
                keywords: [{ keyword: 'CO₂' }, { keyword: 'Temperature (°C)' }],
            };

            render(<KeywordsSection resource={resourceWithSpecialChars} />);

            expect(screen.getByText('CO₂')).toBeInTheDocument();
            expect(screen.getByText('Temperature (°C)')).toBeInTheDocument();
        });

        it('should handle empty controlled keyword text', () => {
            const resourceWithEmptyText = {
                controlled_keywords: [
                    {
                        text: '',
                        scheme: 'gcmd:sciencekeywords',
                    },
                ],
            };

            render(<KeywordsSection resource={resourceWithEmptyText} />);

            // Should still render the badge structure
            expect(screen.getByText('GCMD Science')).toBeInTheDocument();
        });

        it('should handle all GCMD scheme types', () => {
            const resourceWithAllGcmd = {
                controlled_keywords: [
                    { text: 'Science Term', scheme: 'gcmd:sciencekeywords' },
                    { text: 'Platform', scheme: 'gcmd:platforms' },
                    { text: 'Instrument', scheme: 'gcmd:instruments' },
                ],
            };

            render(<KeywordsSection resource={resourceWithAllGcmd} />);

            expect(screen.getByText('GCMD Science')).toBeInTheDocument();
            expect(screen.getByText('GCMD Platform')).toBeInTheDocument();
            expect(screen.getByText('GCMD Instrument')).toBeInTheDocument();
        });
    });

    describe('Accessibility', () => {
        it('should have proper aria-label on section', () => {
            const { container } = render(<KeywordsSection resource={mockResourceWithBothKeywords} />);

            const section = container.querySelector('section');
            expect(section).toHaveAttribute('aria-label', 'Keywords');
        });

        it('should have custom aria-label when heading is custom', () => {
            const { container } = render(
                <KeywordsSection resource={mockResourceWithBothKeywords} heading="Subject Terms" />,
            );

            const section = container.querySelector('section');
            expect(section).toHaveAttribute('aria-label', 'Subject Terms');
        });

        it('should have aria-hidden on decorative icons', () => {
            render(<KeywordsSection resource={mockResourceWithBothKeywords} />);

            const icons = document.querySelectorAll('[aria-hidden="true"]');
            expect(icons.length).toBeGreaterThan(0);
        });

        it('should use semantic HTML headings hierarchy', () => {
            render(<KeywordsSection resource={mockResourceWithBothKeywords} />);

            const h2 = screen.getByRole('heading', { name: /^keywords$/i, level: 2 });
            expect(h2).toBeInTheDocument();

            const h3s = screen.getAllByRole('heading', { level: 3 });
            expect(h3s.length).toBeGreaterThanOrEqual(2); // Free + Controlled
        });
    });

    describe('Dark Mode Support', () => {
        it('should have dark mode classes for free keywords', () => {
            const { container } = render(<KeywordsSection resource={mockResourceFreeOnly} />);

            const freeKeyword = container.querySelector('.dark\\:bg-gray-800');
            expect(freeKeyword).toBeInTheDocument();
        });

        it('should have dark mode classes for GCMD keywords', () => {
            const { container } = render(<KeywordsSection resource={mockResourceControlledOnly} />);

            const gcmdKeyword = container.querySelector('.dark\\:bg-blue-900');
            expect(gcmdKeyword).toBeInTheDocument();
        });

        it('should have dark mode classes for info box', () => {
            const { container } = render(<KeywordsSection resource={mockResourceWithBothKeywords} />);

            const infoBox = container.querySelector('.dark\\:bg-blue-950');
            expect(infoBox).toBeInTheDocument();
        });
    });
});
