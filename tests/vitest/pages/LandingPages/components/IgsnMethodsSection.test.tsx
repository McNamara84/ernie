import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { IgsnMethodsSection } from '@/pages/LandingPages/components/IgsnMethodsSection';
import type { LandingPageIgsnMetadata } from '@/types/landing-page';

describe('IgsnMethodsSection', () => {
    it('uses the unique scheme/value pair as the metadata row key', () => {
        const consoleError = vi.spyOn(console, 'error').mockImplementation(() => undefined);
        const igsn = {
            methods: [
                { scheme: 'Method', value: 'First value' },
                { scheme: 'Method', value: 'Second value' },
            ],
        } as LandingPageIgsnMetadata;

        try {
            render(<IgsnMethodsSection igsn={igsn} />);

            expect(screen.getAllByText('Method')).toHaveLength(2);
            expect(screen.getByText('First value')).toBeInTheDocument();
            expect(screen.getByText('Second value')).toBeInTheDocument();
            expect(consoleError.mock.calls.some((call) => call.some((argument) => String(argument).includes('same key')))).toBe(false);
        } finally {
            consoleError.mockRestore();
        }
    });
});
