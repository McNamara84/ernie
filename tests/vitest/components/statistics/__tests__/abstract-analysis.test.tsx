import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import AbstractAnalysis from '@/components/statistics/abstract-analysis';

describe('AbstractAnalysis', () => {
    const defaultData = {
        longest_abstract: {
            length: 5000,
            preview: 'This is the preview of the longest abstract...',
        },
        shortest_abstract: {
            length: 50,
            preview: 'Short abstract text.',
        },
    };

    describe('longest abstract', () => {
        it('renders longest abstract heading', () => {
            render(<AbstractAnalysis data={defaultData} />);

            expect(screen.getByText('Longest Abstract')).toBeInTheDocument();
        });

        it('displays character count for longest abstract', () => {
            render(<AbstractAnalysis data={defaultData} />);

            // Locale may use '.' or ',' as thousands separator
            expect(screen.getByText(/5[.,]000/)).toBeInTheDocument();
        });

        it('displays the preview text', () => {
            render(<AbstractAnalysis data={defaultData} />);

            expect(screen.getByText(/This is the preview of the longest abstract/)).toBeInTheDocument();
        });

        it('adds ellipsis for long abstracts over 200 characters', () => {
            const longData = {
                ...defaultData,
                longest_abstract: {
                    length: 250,
                    preview: 'A'.repeat(50),
                },
            };

            render(<AbstractAnalysis data={longData} />);

            // Should have ellipsis because length > 200
            expect(screen.getByText(/A+\.\.\./)).toBeInTheDocument();
        });

        it('does not add ellipsis for short abstracts', () => {
            const shortData = {
                ...defaultData,
                longest_abstract: {
                    length: 150,
                    preview: 'Short preview text',
                },
            };

            const { container } = render(<AbstractAnalysis data={shortData} />);

            // Find the preview text element and verify no ellipsis
            const previewElements = container.querySelectorAll('p');
            const longestPreview = Array.from(previewElements).find((el) => el.textContent?.includes('Short preview text'));
            expect(longestPreview?.textContent).toBe('Short preview text');
        });

        it('does not render when longest_abstract is null', () => {
            const nullData = {
                longest_abstract: null,
                shortest_abstract: defaultData.shortest_abstract,
            };

            render(<AbstractAnalysis data={nullData} />);

            expect(screen.queryByText('Longest Abstract')).not.toBeInTheDocument();
        });
    });

    describe('shortest abstract', () => {
        it('renders shortest abstract heading', () => {
            render(<AbstractAnalysis data={defaultData} />);

            expect(screen.getByText('Shortest Abstract')).toBeInTheDocument();
        });

        it('displays character count for shortest abstract', () => {
            render(<AbstractAnalysis data={defaultData} />);

            expect(screen.getByText(/50/)).toBeInTheDocument();
        });

        it('displays the preview text', () => {
            render(<AbstractAnalysis data={defaultData} />);

            expect(screen.getByText('Short abstract text.')).toBeInTheDocument();
        });

        it('does not render when shortest_abstract is null', () => {
            const nullData = {
                longest_abstract: defaultData.longest_abstract,
                shortest_abstract: null,
            };

            render(<AbstractAnalysis data={nullData} />);

            expect(screen.queryByText('Shortest Abstract')).not.toBeInTheDocument();
        });
    });

    describe('edge cases', () => {
        it('renders nothing when both abstracts are null', () => {
            const emptyData = {
                longest_abstract: null,
                shortest_abstract: null,
            };

            const { container } = render(<AbstractAnalysis data={emptyData} />);

            // Only the wrapper div should exist
            expect(container.querySelector('.space-y-4')).toBeInTheDocument();
            expect(container.querySelectorAll('.rounded-lg')).toHaveLength(0);
        });

        it('renders both abstracts when both are present', () => {
            render(<AbstractAnalysis data={defaultData} />);

            expect(screen.getByText('Longest Abstract')).toBeInTheDocument();
            expect(screen.getByText('Shortest Abstract')).toBeInTheDocument();
        });
    });
});
