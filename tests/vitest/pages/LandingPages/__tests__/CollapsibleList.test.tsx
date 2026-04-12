/**
 * @vitest-environment jsdom
 */
import { fireEvent, render, screen } from '@tests/vitest/utils/render';
import { describe, expect, it, vi } from 'vitest';

import { CollapsibleList } from '@/pages/LandingPages/components/CollapsibleList';

vi.mock('@/hooks/use-reduced-motion', () => ({
    useReducedMotion: vi.fn(() => false),
}));

describe('CollapsibleList', () => {
    it('renders children directly when under threshold', () => {
        render(
            <CollapsibleList itemCount={5} itemLabel="items">
                <ul data-testid="list"><li>Item</li></ul>
            </CollapsibleList>,
        );
        expect(screen.getByTestId('list')).toBeInTheDocument();
        expect(screen.queryByRole('button')).not.toBeInTheDocument();
    });

    it('renders expand button when above threshold', () => {
        render(
            <CollapsibleList itemCount={15} itemLabel="contributors">
                <ul data-testid="list"><li>Item</li></ul>
            </CollapsibleList>,
        );
        expect(screen.getByRole('button')).toHaveTextContent('Show all 15 contributors');
    });

    it('toggles expanded state on button click', () => {
        render(
            <CollapsibleList itemCount={15} itemLabel="items">
                <ul><li>Item</li></ul>
            </CollapsibleList>,
        );
        const button = screen.getByRole('button');
        expect(button).toHaveTextContent('Show all 15 items');
        expect(button).toHaveAttribute('aria-expanded', 'false');

        fireEvent.click(button);
        expect(button).toHaveTextContent('Show fewer items');
        expect(button).toHaveAttribute('aria-expanded', 'true');

        fireEvent.click(button);
        expect(button).toHaveTextContent('Show all 15 items');
        expect(button).toHaveAttribute('aria-expanded', 'false');
    });

    it('uses aria-controls to reference the region', () => {
        render(
            <CollapsibleList itemCount={12} itemLabel="funders">
                <ul><li>Item</li></ul>
            </CollapsibleList>,
        );
        const button = screen.getByRole('button');
        const regionId = button.getAttribute('aria-controls');
        expect(regionId).toBeTruthy();
        expect(screen.getByRole('region')).toHaveAttribute('id', regionId);
    });

    it('respects custom threshold', () => {
        render(
            <CollapsibleList itemCount={8} threshold={5} itemLabel="items">
                <ul><li>Item</li></ul>
            </CollapsibleList>,
        );
        expect(screen.getByRole('button')).toHaveTextContent('Show all 8 items');
    });

    it('does not show button when itemCount equals threshold', () => {
        render(
            <CollapsibleList itemCount={10} threshold={10} itemLabel="items">
                <ul><li>Item</li></ul>
            </CollapsibleList>,
        );
        expect(screen.queryByRole('button')).not.toBeInTheDocument();
    });
});
