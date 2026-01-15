/**
 * @vitest-environment jsdom
 */
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import StatsCard from '@/components/statistics/stats-card';

describe('StatsCard', () => {
    it('renders with title and value', () => {
        render(<StatsCard title="Total Datasets" value="42" />);

        expect(screen.getByText('Total Datasets')).toBeInTheDocument();
        expect(screen.getByText('42')).toBeInTheDocument();
    });

    it('renders description when provided', () => {
        render(
            <StatsCard
                title="Downloads"
                value="1,234"
                description="+20% from last month"
            />
        );

        expect(screen.getByText('+20% from last month')).toBeInTheDocument();
    });

    it('does not render description when not provided', () => {
        render(<StatsCard title="Users" value="100" />);

        // CardDescription should not be rendered
        const description = screen.queryByText((_, element) =>
            element?.classList.contains('text-xs') ?? false
        );
        expect(description).not.toBeInTheDocument();
    });

    it('renders icon when provided', () => {
        const TestIcon = () => <svg data-testid="test-icon" />;
        
        render(
            <StatsCard
                title="Active Users"
                value="25"
                icon={<TestIcon />}
            />
        );

        expect(screen.getByTestId('test-icon')).toBeInTheDocument();
    });

    it('does not render icon container content when icon not provided', () => {
        render(<StatsCard title="Count" value="0" />);

        expect(screen.queryByTestId('test-icon')).not.toBeInTheDocument();
    });

    it('displays large values correctly', () => {
        render(<StatsCard title="Total" value="1,234,567" />);

        expect(screen.getByText('1,234,567')).toBeInTheDocument();
    });

    it('displays value with proper styling', () => {
        render(<StatsCard title="Metric" value="99" />);

        const valueElement = screen.getByText('99');
        expect(valueElement).toHaveClass('text-2xl', 'font-bold');
    });

    it('displays title with proper styling', () => {
        render(<StatsCard title="Datasets Published" value="50" />);

        const titleElement = screen.getByText('Datasets Published');
        expect(titleElement).toHaveClass('text-sm', 'font-medium');
    });
});
