import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty';

describe('Empty', () => {
    it('renders the composable shadcn empty-state slots', () => {
        const { container } = render(
            <Empty>
                <EmptyHeader>
                    <EmptyMedia>Icon</EmptyMedia>
                    <EmptyTitle>No results</EmptyTitle>
                    <EmptyDescription>Try another search.</EmptyDescription>
                </EmptyHeader>
                <EmptyContent>Action</EmptyContent>
            </Empty>,
        );

        expect(container.querySelector('[data-slot="empty"]')).toBeInTheDocument();
        expect(container.querySelector('[data-slot="empty-header"]')).toBeInTheDocument();
        expect(container.querySelector('[data-slot="empty-icon"]')).toBeInTheDocument();
        expect(screen.getByText('No results')).toHaveAttribute('data-slot', 'empty-title');
        expect(screen.getByText('Try another search.').tagName).toBe('P');
        expect(container.querySelector('[data-slot="empty-content"]')).toBeInTheDocument();
    });
});
