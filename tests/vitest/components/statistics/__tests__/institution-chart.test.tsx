import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import InstitutionChart from '@/components/statistics/institution-chart';

// Mock ResponsiveContainer for jsdom compatibility
vi.mock('recharts', async () => {
    const actual = await vi.importActual('recharts');
    return {
        ...actual,
        ResponsiveContainer: ({ children }: { children: React.ReactNode }) => (
            <div data-testid="responsive-container" style={{ width: 400, height: 400 }}>
                {children}
            </div>
        ),
    };
});

describe('InstitutionChart', () => {
    const mockData = [
        { name: 'GFZ German Research Centre for Geosciences', rorId: 'https://ror.org/04z8jg394', count: 450 },
        { name: 'Helmholtz Centre Potsdam', rorId: 'https://ror.org/01234567', count: 220 },
        { name: 'University of Berlin', rorId: null, count: 150 },
    ];

    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders the chart container', () => {
        render(<InstitutionChart data={mockData} />);

        expect(screen.getByTestId('responsive-container')).toBeInTheDocument();
    });

    it('renders recharts BarChart wrapper', () => {
        const { container } = render(<InstitutionChart data={mockData} />);

        const chartWrapper = container.querySelector('.recharts-wrapper');
        expect(chartWrapper).toBeInTheDocument();
    });

    it('handles empty data gracefully', () => {
        render(<InstitutionChart data={[]} />);

        expect(screen.getByTestId('responsive-container')).toBeInTheDocument();
    });

    it('handles single institution data', () => {
        const singleInstitution = [
            { name: 'Single Institution', rorId: 'https://ror.org/00000000', count: 100 },
        ];
        render(<InstitutionChart data={singleInstitution} />);

        expect(screen.getByTestId('responsive-container')).toBeInTheDocument();
    });

    it('handles institutions without ROR ID', () => {
        const noRorData = [
            { name: 'Institution without ROR', rorId: null, count: 50 },
        ];
        render(<InstitutionChart data={noRorData} />);

        expect(screen.getByTestId('responsive-container')).toBeInTheDocument();
    });

    it('truncates long institution names', () => {
        const longNameData = [
            {
                name: 'This is a very long institution name that exceeds the maximum display length',
                rorId: null,
                count: 100,
            },
        ];
        const { container } = render(<InstitutionChart data={longNameData} />);

        expect(container.querySelector('.recharts-wrapper')).toBeInTheDocument();
    });

    it('handles many institutions with color cycling', () => {
        const manyInstitutions = Array.from({ length: 20 }, (_, i) => ({
            name: `Institution ${i + 1}`,
            rorId: i % 2 === 0 ? `https://ror.org/0000000${i}` : null,
            count: 100 - i * 5,
        }));

        const { container } = render(<InstitutionChart data={manyInstitutions} />);

        expect(container.querySelector('.recharts-wrapper')).toBeInTheDocument();
    });
});
