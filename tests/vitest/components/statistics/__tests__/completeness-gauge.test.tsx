import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import CompletenessGauge from '@/components/statistics/completeness-gauge';

describe('CompletenessGauge', () => {
    const defaultData = {
        descriptions: 85.5,
        geographicCoverage: 62.0,
        temporalCoverage: 78.25,
        funding: 45.0,
        orcid: 92.5,
        rorIds: 38.75,
        relatedWorks: 55.0,
    };

    it('renders all metric labels', () => {
        render(<CompletenessGauge data={defaultData} />);

        expect(screen.getByText('Descriptions')).toBeInTheDocument();
        expect(screen.getByText('Geographic Coverage')).toBeInTheDocument();
        expect(screen.getByText('Temporal Coverage')).toBeInTheDocument();
        expect(screen.getByText('Funding References')).toBeInTheDocument();
        expect(screen.getByText('ORCID for Authors')).toBeInTheDocument();
        expect(screen.getByText('ROR IDs for Affiliations')).toBeInTheDocument();
        expect(screen.getByText('Related Works')).toBeInTheDocument();
    });

    it('displays percentage values with two decimal places', () => {
        render(<CompletenessGauge data={defaultData} />);

        expect(screen.getByText('85.50%')).toBeInTheDocument();
        expect(screen.getByText('62.00%')).toBeInTheDocument();
        expect(screen.getByText('78.25%')).toBeInTheDocument();
        expect(screen.getByText('45.00%')).toBeInTheDocument();
        expect(screen.getByText('92.50%')).toBeInTheDocument();
        expect(screen.getByText('38.75%')).toBeInTheDocument();
        expect(screen.getByText('55.00%')).toBeInTheDocument();
    });

    it('renders progress bars for each metric', () => {
        const { container } = render(<CompletenessGauge data={defaultData} />);

        const progressBars = container.querySelectorAll('.h-full.transition-all');
        expect(progressBars).toHaveLength(7);
    });

    it('applies correct width based on percentage', () => {
        const simpleData = {
            descriptions: 50,
            geographicCoverage: 0,
            temporalCoverage: 0,
            funding: 0,
            orcid: 0,
            rorIds: 0,
            relatedWorks: 0,
        };

        const { container } = render(<CompletenessGauge data={simpleData} />);

        // Find the bar with 50% width
        const progressBars = container.querySelectorAll('.h-full.transition-all');
        const hasBar50 = Array.from(progressBars).some((bar) => (bar as HTMLElement).style.width === '50%');
        expect(hasBar50).toBe(true);
    });

    it('sorts metrics by value in descending order', () => {
        const sortedData = {
            descriptions: 10,
            geographicCoverage: 20,
            temporalCoverage: 30,
            funding: 40,
            orcid: 100,
            rorIds: 60,
            relatedWorks: 50,
        };

        const { container } = render(<CompletenessGauge data={sortedData} />);

        // Get all metric labels in order
        const labels = container.querySelectorAll('.text-sm.font-medium');
        const labelTexts = Array.from(labels).map((el) => el.textContent);

        // First should be ORCID (100), last should be Descriptions (10)
        expect(labelTexts[0]).toBe('ORCID for Authors');
        expect(labelTexts[labelTexts.length - 1]).toBe('Descriptions');
    });

    it('handles zero values', () => {
        const zeroData = {
            descriptions: 0,
            geographicCoverage: 0,
            temporalCoverage: 0,
            funding: 0,
            orcid: 0,
            rorIds: 0,
            relatedWorks: 0,
        };

        render(<CompletenessGauge data={zeroData} />);

        const zeroPercentages = screen.getAllByText('0.00%');
        expect(zeroPercentages).toHaveLength(7);
    });

    it('handles 100% values', () => {
        const fullData = {
            descriptions: 100,
            geographicCoverage: 100,
            temporalCoverage: 100,
            funding: 100,
            orcid: 100,
            rorIds: 100,
            relatedWorks: 100,
        };

        render(<CompletenessGauge data={fullData} />);

        const fullPercentages = screen.getAllByText('100.00%');
        expect(fullPercentages).toHaveLength(7);
    });

    it('applies different colors to each metric bar', () => {
        const { container } = render(<CompletenessGauge data={defaultData} />);

        const progressBars = container.querySelectorAll('.h-full.transition-all');
        const colors = new Set(Array.from(progressBars).map((bar) => (bar as HTMLElement).style.backgroundColor));

        // All 7 metrics should have unique colors
        expect(colors.size).toBe(7);
    });
});
