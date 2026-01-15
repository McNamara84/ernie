import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import LanguageChart from '@/components/statistics/language-chart';

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

describe('LanguageChart', () => {
    const mockData = [
        { language: 'English', count: 450 },
        { language: 'German', count: 120 },
        { language: 'French', count: 45 },
        { language: 'Spanish', count: 30 },
    ];

    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders the chart container', () => {
        render(<LanguageChart data={mockData} />);

        expect(screen.getByTestId('responsive-container')).toBeInTheDocument();
    });

    it('renders recharts BarChart wrapper', () => {
        const { container } = render(<LanguageChart data={mockData} />);

        const chartWrapper = container.querySelector('.recharts-wrapper');
        expect(chartWrapper).toBeInTheDocument();
    });

    it('handles empty data gracefully', () => {
        render(<LanguageChart data={[]} />);

        expect(screen.getByTestId('responsive-container')).toBeInTheDocument();
    });

    it('handles single language data', () => {
        const singleLanguage = [{ language: 'English', count: 1000 }];
        render(<LanguageChart data={singleLanguage} />);

        expect(screen.getByTestId('responsive-container')).toBeInTheDocument();
    });

    it('handles many languages with color cycling', () => {
        const manyLanguages = Array.from({ length: 15 }, (_, i) => ({
            language: `Language ${i + 1}`,
            count: (i + 1) * 10,
        }));

        const { container } = render(<LanguageChart data={manyLanguages} />);

        expect(container.querySelector('.recharts-wrapper')).toBeInTheDocument();
    });
});
