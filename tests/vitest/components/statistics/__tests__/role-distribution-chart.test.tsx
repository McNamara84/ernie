import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import RoleDistributionChart from '@/components/statistics/role-distribution-chart';

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

describe('RoleDistributionChart', () => {
    const mockData = [
        { role: 'ContactPerson', count: 450 },
        { role: 'DataCollector', count: 220 },
        { role: 'ProjectLeader', count: 150 },
        { role: 'Researcher', count: 100 },
    ];

    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders the chart container', () => {
        render(<RoleDistributionChart data={mockData} />);

        expect(screen.getByTestId('responsive-container')).toBeInTheDocument();
    });

    it('renders recharts PieChart wrapper', () => {
        const { container } = render(<RoleDistributionChart data={mockData} />);

        const chartWrapper = container.querySelector('.recharts-wrapper');
        expect(chartWrapper).toBeInTheDocument();
    });

    it('handles empty data gracefully', () => {
        render(<RoleDistributionChart data={[]} />);

        expect(screen.getByTestId('responsive-container')).toBeInTheDocument();
    });

    it('renders the summary table with roles', () => {
        render(<RoleDistributionChart data={mockData} />);

        expect(screen.getByText('ContactPerson')).toBeInTheDocument();
        expect(screen.getByText('DataCollector')).toBeInTheDocument();
        expect(screen.getByText('ProjectLeader')).toBeInTheDocument();
        expect(screen.getByText('Researcher')).toBeInTheDocument();
    });

    it('displays formatted counts in the table', () => {
        render(<RoleDistributionChart data={mockData} />);

        expect(screen.getByText('450')).toBeInTheDocument();
        expect(screen.getByText('220')).toBeInTheDocument();
        expect(screen.getByText('150')).toBeInTheDocument();
    });

    it('renders table headers', () => {
        render(<RoleDistributionChart data={mockData} />);

        expect(screen.getByText('Role')).toBeInTheDocument();
        expect(screen.getByText('Count')).toBeInTheDocument();
    });

    it('renders color indicators for each role', () => {
        const { container } = render(<RoleDistributionChart data={mockData} />);

        const colorBoxes = container.querySelectorAll('.h-3.w-3.rounded-sm');
        expect(colorBoxes).toHaveLength(4);
    });

    it('handles many roles with color cycling', () => {
        const manyRoles = Array.from({ length: 25 }, (_, i) => ({
            role: `Role ${i + 1}`,
            count: 100 - i * 4,
        }));

        const { container } = render(<RoleDistributionChart data={manyRoles} />);

        expect(container.querySelector('.recharts-wrapper')).toBeInTheDocument();
        
        // Check that all roles appear in the table
        const colorBoxes = container.querySelectorAll('.h-3.w-3.rounded-sm');
        expect(colorBoxes).toHaveLength(25);
    });
});
