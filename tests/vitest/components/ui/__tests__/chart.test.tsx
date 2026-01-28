import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import {
    type ChartConfig,
    ChartContainer,
    ChartLegend,
    ChartLegendContent,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';

// Simple mock recharts components for testing
const MockBarChart = ({ children }: { children: React.ReactNode }) => <div data-testid="bar-chart">{children}</div>;
const MockBar = () => <div data-testid="bar" />;

describe('ChartContainer', () => {
    const chartConfig: ChartConfig = {
        value: {
            label: 'Value',
            color: 'hsl(var(--primary))',
        },
    };

    it('renders children within a responsive container', () => {
        const { container } = render(
            <ChartContainer config={chartConfig}>
                <MockBarChart>
                    <MockBar />
                </MockBarChart>
            </ChartContainer>
        );

        // ChartContainer wraps content in a responsive container
        expect(container.querySelector('.recharts-responsive-container')).toBeInTheDocument();
    });

    it('applies custom className', () => {
        const { container } = render(
            <ChartContainer config={chartConfig} className="custom-chart">
                <MockBarChart>
                    <MockBar />
                </MockBarChart>
            </ChartContainer>
        );

        const chartDiv = container.querySelector('[data-chart]');
        expect(chartDiv).toHaveClass('custom-chart');
    });

    it('generates unique chart ID', () => {
        const { container } = render(
            <ChartContainer config={chartConfig}>
                <MockBarChart>
                    <MockBar />
                </MockBarChart>
            </ChartContainer>
        );

        const chartDiv = container.querySelector('[data-chart]');
        expect(chartDiv).toHaveAttribute('data-chart');
        expect(chartDiv?.getAttribute('data-chart')).toMatch(/^chart-/);
    });

    it('accepts custom id prop', () => {
        const { container } = render(
            <ChartContainer config={chartConfig} id="my-chart">
                <MockBarChart>
                    <MockBar />
                </MockBarChart>
            </ChartContainer>
        );

        const chartDiv = container.querySelector('[data-chart]');
        expect(chartDiv).toHaveAttribute('data-chart', 'chart-my-chart');
    });
});

describe('ChartConfig', () => {
    it('supports color configuration', () => {
        const config: ChartConfig = {
            sales: {
                label: 'Sales',
                color: '#ff0000',
            },
        };

        expect(config.sales.label).toBe('Sales');
        expect(config.sales.color).toBe('#ff0000');
    });

    it('supports theme-based colors', () => {
        const config: ChartConfig = {
            revenue: {
                label: 'Revenue',
                theme: {
                    light: '#000000',
                    dark: '#ffffff',
                },
            },
        };

        expect(config.revenue.theme?.light).toBe('#000000');
        expect(config.revenue.theme?.dark).toBe('#ffffff');
    });
});

describe('ChartTooltip', () => {
    it('is exported and can be used', () => {
        // ChartTooltip is a re-export of recharts Tooltip
        expect(ChartTooltip).toBeDefined();
    });
});

describe('ChartTooltipContent', () => {
    it('is exported and can be used', () => {
        expect(ChartTooltipContent).toBeDefined();
    });
});

describe('ChartLegend', () => {
    it('is exported and can be used', () => {
        expect(ChartLegend).toBeDefined();
    });
});

describe('ChartLegendContent', () => {
    it('is exported and can be used', () => {
        expect(ChartLegendContent).toBeDefined();
    });
});
