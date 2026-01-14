/**
 * Tests for DataQualityIndicators Component
 *
 * Tests the data quality indicators for related work statistics:
 * - High quality (>= 99% complete)
 * - Lower quality alerts
 * - Placeholder patterns table
 * - No placeholders message
 */

import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import DataQualityIndicators from '@/components/statistics/data-quality-indicators';

describe('DataQualityIndicators', () => {
    const defaultPlaceholders = {
        totalPlaceholders: 0,
        datasetsWithPlaceholders: 0,
        patterns: [],
    };

    const defaultQuality = {
        completeData: 100,
        incompleteOrPlaceholder: 0,
        percentageComplete: 100,
    };

    describe('High Quality State', () => {
        it('shows excellent data quality alert when >= 99% complete', () => {
            render(
                <DataQualityIndicators
                    placeholders={defaultPlaceholders}
                    quality={{ ...defaultQuality, percentageComplete: 99 }}
                />
            );

            expect(screen.getByText('Excellent Data Quality')).toBeInTheDocument();
            expect(screen.getByText(/99% of related work entries have complete, valid data/)).toBeInTheDocument();
        });

        it('shows 100% as excellent quality', () => {
            render(
                <DataQualityIndicators
                    placeholders={defaultPlaceholders}
                    quality={defaultQuality}
                />
            );

            expect(screen.getByText('Excellent Data Quality')).toBeInTheDocument();
        });

        it('displays complete data count', () => {
            render(
                <DataQualityIndicators
                    placeholders={defaultPlaceholders}
                    quality={{ ...defaultQuality, completeData: 1500 }}
                />
            );

            // toLocaleString format depends on system locale (could be 1,500 or 1.500)
            expect(screen.getByText(1500..toLocaleString())).toBeInTheDocument();
            expect(screen.getByText('Valid related work entries')).toBeInTheDocument();
        });

        it('displays completion rate', () => {
            render(
                <DataQualityIndicators
                    placeholders={defaultPlaceholders}
                    quality={defaultQuality}
                />
            );

            expect(screen.getByText('100%')).toBeInTheDocument();
            expect(screen.getByText('Overall data quality')).toBeInTheDocument();
        });
    });

    describe('Lower Quality State', () => {
        const lowerQuality = {
            completeData: 80,
            incompleteOrPlaceholder: 20,
            percentageComplete: 80,
        };

        it('shows needs attention alert when < 99% complete', () => {
            render(
                <DataQualityIndicators
                    placeholders={defaultPlaceholders}
                    quality={lowerQuality}
                />
            );

            expect(screen.getByText('Data Quality Needs Attention')).toBeInTheDocument();
        });

        it('shows percentage and incomplete count in alert', () => {
            render(
                <DataQualityIndicators
                    placeholders={defaultPlaceholders}
                    quality={lowerQuality}
                />
            );

            expect(screen.getByText(/80% complete/)).toBeInTheDocument();
            expect(screen.getByText(/20 entries contain placeholder values/)).toBeInTheDocument();
        });

        it('displays placeholders count', () => {
            render(
                <DataQualityIndicators
                    placeholders={{ totalPlaceholders: 5, datasetsWithPlaceholders: 3, patterns: [] }}
                    quality={lowerQuality}
                />
            );

            expect(screen.getByText('20')).toBeInTheDocument();
            expect(screen.getByText('Entries with placeholder values')).toBeInTheDocument();
        });
    });

    describe('Placeholder Patterns', () => {
        const placeholdersWithPatterns = {
            totalPlaceholders: 15,
            datasetsWithPlaceholders: 5,
            patterns: [
                { pattern: 'TBD', count: 8 },
                { pattern: 'N/A', count: 5 },
                { pattern: '???', count: 2 },
            ],
        };

        const qualityWithPlaceholders = {
            completeData: 85,
            incompleteOrPlaceholder: 15,
            percentageComplete: 85,
        };

        it('shows placeholder patterns table when placeholders exist', () => {
            render(
                <DataQualityIndicators
                    placeholders={placeholdersWithPatterns}
                    quality={qualityWithPlaceholders}
                />
            );

            expect(screen.getByText('Placeholder Patterns Detected')).toBeInTheDocument();
        });

        it('displays pattern table with headers', () => {
            render(
                <DataQualityIndicators
                    placeholders={placeholdersWithPatterns}
                    quality={qualityWithPlaceholders}
                />
            );

            expect(screen.getByText('Pattern')).toBeInTheDocument();
            expect(screen.getByText('Occurrences')).toBeInTheDocument();
        });

        it('shows each pattern with count', () => {
            render(
                <DataQualityIndicators
                    placeholders={placeholdersWithPatterns}
                    quality={qualityWithPlaceholders}
                />
            );

            expect(screen.getByText('"TBD"')).toBeInTheDocument();
            expect(screen.getByText('8')).toBeInTheDocument();
            expect(screen.getByText('"N/A"')).toBeInTheDocument();
            expect(screen.getByText('5')).toBeInTheDocument();
            expect(screen.getByText('"???"')).toBeInTheDocument();
            expect(screen.getByText('2')).toBeInTheDocument();
        });

        it('shows datasets affected count (plural)', () => {
            render(
                <DataQualityIndicators
                    placeholders={placeholdersWithPatterns}
                    quality={qualityWithPlaceholders}
                />
            );

            expect(screen.getByText(/5 datasets affected by 15 placeholder entries/)).toBeInTheDocument();
        });

        it('shows datasets affected count (singular)', () => {
            render(
                <DataQualityIndicators
                    placeholders={{
                        totalPlaceholders: 1,
                        datasetsWithPlaceholders: 1,
                        patterns: [{ pattern: 'TBD', count: 1 }],
                    }}
                    quality={qualityWithPlaceholders}
                />
            );

            expect(screen.getByText(/1 dataset affected by 1 placeholder entry/)).toBeInTheDocument();
        });
    });

    describe('No Placeholders', () => {
        it('shows no placeholders message when none detected', () => {
            render(
                <DataQualityIndicators
                    placeholders={defaultPlaceholders}
                    quality={defaultQuality}
                />
            );

            expect(screen.getByText('No Placeholder Values Found')).toBeInTheDocument();
            expect(screen.getByText('All related work entries contain valid, complete data.')).toBeInTheDocument();
        });

        it('does not show pattern table when no placeholders', () => {
            render(
                <DataQualityIndicators
                    placeholders={defaultPlaceholders}
                    quality={defaultQuality}
                />
            );

            expect(screen.queryByText('Placeholder Patterns Detected')).not.toBeInTheDocument();
        });
    });

    describe('Metric Cards', () => {
        it('renders all three metric cards', () => {
            render(
                <DataQualityIndicators
                    placeholders={defaultPlaceholders}
                    quality={defaultQuality}
                />
            );

            expect(screen.getByText('Complete Data')).toBeInTheDocument();
            expect(screen.getByText('Placeholders')).toBeInTheDocument();
            expect(screen.getByText('Completion Rate')).toBeInTheDocument();
        });

        it('formats large numbers with locale', () => {
            render(
                <DataQualityIndicators
                    placeholders={{ ...defaultPlaceholders }}
                    quality={{ completeData: 12345, incompleteOrPlaceholder: 678, percentageComplete: 95 }}
                />
            );

            // toLocaleString format depends on system locale
            expect(screen.getByText(12345..toLocaleString())).toBeInTheDocument();
            expect(screen.getByText(678..toLocaleString())).toBeInTheDocument();
        });
    });
});
