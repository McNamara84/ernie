import '@testing-library/jest-dom/vitest';

import { render, screen } from '@tests/vitest/utils/render';
import { describe, expect, it, vi } from 'vitest';

import { pluralizedCount, SettingsSectionSummary } from '@/components/settings/settings-section-summary';

describe('SettingsSectionSummary', () => {
    it('formats regular and irregular singular/plural counts', () => {
        expect(pluralizedCount(1, 'license')).toBe('1 license');
        expect(pluralizedCount(2, 'license')).toBe('2 licenses');
        expect(pluralizedCount(1, 'registry', 'registries')).toBe('1 registry');
        expect(pluralizedCount(3, 'registry', 'registries')).toBe('3 registries');
    });

    it('provides one concise accessible summary while hiding decorative badges', () => {
        render(<SettingsSectionSummary items={['3 licenses', '2 ERNIE', '1 ELMO']} />);

        expect(screen.getByLabelText('3 licenses, 2 ERNIE, 1 ELMO')).toBeInTheDocument();
        expect(screen.getByText('3 licenses')).toHaveAttribute('aria-hidden', 'true');
        expect(screen.getByText('2 ERNIE')).toHaveAttribute('aria-hidden', 'true');
        expect(screen.getByText('1 ELMO')).toHaveAttribute('aria-hidden', 'true');
    });

    it('renders duplicate labels without React key collisions', () => {
        const consoleError = vi.spyOn(console, 'error').mockImplementation(() => undefined);

        try {
            render(<SettingsSectionSummary items={['1 active', '1 active']} />);

            expect(screen.getAllByText('1 active')).toHaveLength(2);
            expect(consoleError).not.toHaveBeenCalled();
        } finally {
            consoleError.mockRestore();
        }
    });
});
