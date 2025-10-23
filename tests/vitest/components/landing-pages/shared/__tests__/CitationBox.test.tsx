import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import CitationBox from '@/components/landing-pages/shared/CitationBox';

// Mock toast
vi.mock('sonner', () => ({
    toast: {
        success: vi.fn(),
        error: vi.fn(),
    },
}));

describe('CitationBox', () => {
    const mockResourceComplete = {
        id: 1,
        doi: '10.5880/GFZ.TEST.001',
        year: 2024,
        version: '1.0',
        titles: [{ title: 'Test Dataset for Landing Page' }],
        authors: [
            {
                id: 1,
                authorable_type: 'App\\Models\\Person',
                authorable: {
                    given_name: 'John',
                    family_name: 'Doe',
                },
            },
            {
                id: 2,
                authorable_type: 'App\\Models\\Person',
                authorable: {
                    given_name: 'Jane',
                    family_name: 'Smith',
                },
            },
        ],
        publisher: { name: 'GFZ Data Services' },
        resource_type: { resource_type_general: 'Dataset' },
        language: { code: 'en', name: 'English' },
    };

    const mockResourceMinimal = {
        id: 2,
        year: 2023,
        titles: [{ title: 'Minimal Dataset' }],
        authors: [
            {
                id: 1,
                authorable_type: 'App\\Models\\Person',
                authorable: {
                    family_name: 'TestAuthor',
                },
            },
        ],
    };

    describe('Rendering', () => {
        it('should render citation box with heading', () => {
            render(<CitationBox resource={mockResourceComplete} />);

            expect(screen.getByText('Cite as')).toBeInTheDocument();
        });

        it('should render complete citation', () => {
            render(<CitationBox resource={mockResourceComplete} />);

            expect(screen.getByText(/Doe, John; Smith, Jane/)).toBeInTheDocument();
            expect(screen.getByText(/2024/)).toBeInTheDocument();
            expect(screen.getByText(/Test Dataset for Landing Page/)).toBeInTheDocument();
            expect(screen.getByText(/GFZ Data Services/)).toBeInTheDocument();
            expect(screen.getByText(/Dataset/)).toBeInTheDocument();
            expect(screen.getByText(/Version 1\.0/)).toBeInTheDocument();
        });

        it('should render DOI as clickable link', () => {
            render(<CitationBox resource={mockResourceComplete} />);

            const doiLink = screen.getByRole('link', {
                name: /10\.5880\/GFZ\.TEST\.001/,
            });
            expect(doiLink).toHaveAttribute('href', 'https://doi.org/10.5880/GFZ.TEST.001');
            expect(doiLink).toHaveAttribute('target', '_blank');
            expect(doiLink).toHaveAttribute('rel', 'noopener noreferrer');
        });

        it('should show language information', () => {
            render(<CitationBox resource={mockResourceComplete} />);

            expect(screen.getByText(/Language: English/)).toBeInTheDocument();
        });

        it('should show pending DOI message when no DOI', () => {
            render(<CitationBox resource={mockResourceMinimal} />);

            expect(screen.getByText(/DOI registration pending/i)).toBeInTheDocument();
        });
    });

    describe('Author Formatting', () => {
        it('should format person authors correctly', () => {
            render(<CitationBox resource={mockResourceComplete} />);

            expect(screen.getByText(/Doe, John/)).toBeInTheDocument();
            expect(screen.getByText(/Smith, Jane/)).toBeInTheDocument();
        });

        it('should format institution authors correctly', () => {
            const resourceWithInstitution = {
                ...mockResourceMinimal,
                authors: [
                    {
                        id: 1,
                        authorable_type: 'App\\Models\\Institution',
                        authorable: {
                            name: 'GFZ German Research Centre for Geosciences',
                        },
                    },
                ],
            };

            render(<CitationBox resource={resourceWithInstitution} />);

            expect(
                screen.getByText(/GFZ German Research Centre for Geosciences/),
            ).toBeInTheDocument();
        });

        it('should handle missing author data gracefully', () => {
            const resourceWithMissingAuthor = {
                ...mockResourceMinimal,
                authors: [
                    {
                        id: 1,
                        authorable_type: 'App\\Models\\Person',
                        authorable: {
                            family_name: 'Doe',
                        },
                    },
                ],
            };

            render(<CitationBox resource={resourceWithMissingAuthor} />);

            expect(screen.getByText(/Doe \(/)).toBeInTheDocument();
        });

        it('should show "Unknown Author" when no authors', () => {
            const resourceWithoutAuthors = {
                ...mockResourceMinimal,
                authors: [],
            };

            render(<CitationBox resource={resourceWithoutAuthors} />);

            expect(screen.getByText(/Unknown Author/)).toBeInTheDocument();
        });

        it('should add "et al." when more than 10 authors', () => {
            const manyAuthors = Array.from({ length: 12 }, (_, i) => ({
                id: i + 1,
                authorable_type: 'App\\Models\\Person',
                authorable: {
                    given_name: `Author${i + 1}`,
                    family_name: `Last${i + 1}`,
                },
            }));

            const resourceWithManyAuthors = {
                ...mockResourceMinimal,
                authors: manyAuthors,
            };

            render(<CitationBox resource={resourceWithManyAuthors} />);

            expect(screen.getByText(/et al\./)).toBeInTheDocument();
        });
    });

    describe('Copy to Clipboard', () => {
        it('should render copy button', () => {
            render(<CitationBox resource={mockResourceComplete} />);

            const copyButton = screen.getByRole('button', {
                name: /copy citation to clipboard/i,
            });
            expect(copyButton).toBeInTheDocument();
        });

        it('should copy citation to clipboard on button click', async () => {
            const user = userEvent.setup();
            const writeTextMock = vi.fn().mockResolvedValue(undefined);

            Object.defineProperty(navigator, 'clipboard', {
                value: { writeText: writeTextMock },
                writable: true,
                configurable: true,
            });

            render(<CitationBox resource={mockResourceComplete} />);

            const copyButton = screen.getByRole('button', {
                name: /copy citation to clipboard/i,
            });
            await user.click(copyButton);

            await waitFor(() => {
                expect(writeTextMock).toHaveBeenCalledWith(expect.stringContaining('Doe, John'));
                expect(writeTextMock).toHaveBeenCalledWith(
                    expect.stringContaining('Test Dataset for Landing Page'),
                );
                expect(writeTextMock).toHaveBeenCalledWith(
                    expect.stringContaining('10.5880/GFZ.TEST.001'),
                );
            });
        });

        it('should show success feedback after copying', async () => {
            const user = userEvent.setup();
            const writeTextMock = vi.fn().mockResolvedValue(undefined);

            Object.defineProperty(navigator, 'clipboard', {
                value: { writeText: writeTextMock },
                writable: true,
                configurable: true,
            });

            render(<CitationBox resource={mockResourceComplete} />);

            const copyButton = screen.getByRole('button', {
                name: /copy citation to clipboard/i,
            });
            await user.click(copyButton);

            await waitFor(() => {
                expect(screen.getByText('Copied')).toBeInTheDocument();
            });
        });

        it('should reset copied state after timeout', async () => {
            vi.useFakeTimers();
            const user = userEvent.setup({ delay: null });
            const writeTextMock = vi.fn().mockResolvedValue(undefined);

            Object.defineProperty(navigator, 'clipboard', {
                value: { writeText: writeTextMock },
                writable: true,
                configurable: true,
            });

            render(<CitationBox resource={mockResourceComplete} />);

            const copyButton = screen.getByRole('button', {
                name: /copy citation to clipboard/i,
            });
            await user.click(copyButton);

            await waitFor(() => {
                expect(screen.getByText('Copied')).toBeInTheDocument();
            });

            // Fast-forward time by 2 seconds
            vi.advanceTimersByTime(2000);

            await waitFor(() => {
                expect(screen.getByText('Copy')).toBeInTheDocument();
            });

            vi.useRealTimers();
        });
    });

    describe('Citation Format', () => {
        it('should include all required DataCite elements', () => {
            render(<CitationBox resource={mockResourceComplete} />);

            // Authors
            expect(screen.getByText(/Doe, John; Smith, Jane/)).toBeInTheDocument();
            // Year
            expect(screen.getByText(/\(2024\)/)).toBeInTheDocument();
            // Title
            expect(screen.getByText(/Test Dataset for Landing Page/)).toBeInTheDocument();
            // Publisher
            expect(screen.getByText(/GFZ Data Services/)).toBeInTheDocument();
            // Resource Type
            expect(screen.getByText(/Dataset/)).toBeInTheDocument();
            // Version
            expect(screen.getByText(/Version 1\.0/)).toBeInTheDocument();
            // DOI
            expect(screen.getByText(/10\.5880\/GFZ\.TEST\.001/)).toBeInTheDocument();
        });

        it('should use fallback values when data missing', () => {
            const minimalResource = {
                id: 1,
            };

            render(<CitationBox resource={minimalResource} />);

            expect(screen.getByText(/Unknown Author/)).toBeInTheDocument();
            expect(screen.getByText(/n\.d\./)).toBeInTheDocument(); // No date
            expect(screen.getByText(/Untitled Dataset/)).toBeInTheDocument();
            expect(screen.getByText(/GFZ Data Services/)).toBeInTheDocument();
            expect(screen.getByText(/Dataset/)).toBeInTheDocument();
        });
    });
});
