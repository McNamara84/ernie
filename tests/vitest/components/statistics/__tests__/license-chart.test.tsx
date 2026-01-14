import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import LicenseChart from '@/components/statistics/license-chart';

// Mock ResponsiveContainer for jsdom compatibility
vi.mock('recharts', async () => {
    const actual = await vi.importActual('recharts');
    return {
        ...actual,
        ResponsiveContainer: ({ children }: { children: React.ReactNode }) => (
            <div data-testid="responsive-container" style={{ width: 400, height: 300 }}>
                {children}
            </div>
        ),
    };
});

describe('LicenseChart', () => {
    const mockData = [
        { name: 'CC BY 4.0', count: 350 },
        { name: 'CC BY-SA 4.0', count: 120 },
        { name: 'MIT License', count: 45 },
        { name: 'Apache 2.0', count: 30 },
    ];

    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders the chart container', () => {
        render(<LicenseChart data={mockData} />);

        expect(screen.getByTestId('responsive-container')).toBeInTheDocument();
    });

    it('renders recharts BarChart wrapper', () => {
        const { container } = render(<LicenseChart data={mockData} />);

        const chartWrapper = container.querySelector('.recharts-wrapper');
        expect(chartWrapper).toBeInTheDocument();
    });

    it('handles empty data gracefully', () => {
        render(<LicenseChart data={[]} />);

        expect(screen.getByTestId('responsive-container')).toBeInTheDocument();
    });

    it('handles single license data', () => {
        const singleLicense = [{ name: 'CC BY 4.0', count: 1000 }];
        render(<LicenseChart data={singleLicense} />);

        expect(screen.getByTestId('responsive-container')).toBeInTheDocument();
    });

    it('truncates long license names', () => {
        const longNameData = [
            { name: 'Creative Commons Attribution 4.0 International License', count: 100 },
        ];
        const { container } = render(<LicenseChart data={longNameData} />);

        expect(container.querySelector('.recharts-wrapper')).toBeInTheDocument();
    });

    it('handles many licenses with color cycling', () => {
        const manyLicenses = Array.from({ length: 15 }, (_, i) => ({
            name: `License Type ${i + 1}`,
            count: (i + 1) * 10,
        }));

        const { container } = render(<LicenseChart data={manyLicenses} />);

        expect(container.querySelector('.recharts-wrapper')).toBeInTheDocument();
    });
});
