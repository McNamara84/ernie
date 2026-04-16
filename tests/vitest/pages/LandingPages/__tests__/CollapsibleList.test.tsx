/**
 * @vitest-environment jsdom
 */
import { fireEvent, render, screen } from '@tests/vitest/utils/render';
import { describe, expect, it, vi } from 'vitest';

import { CollapsibleList } from '@/pages/LandingPages/components/CollapsibleList';

vi.mock('@/hooks/use-reduced-motion', () => ({
    useReducedMotion: vi.fn(() => false),
}));

function makeItems(count: number) {
    return Array.from({ length: count }, (_, i) => `Item ${i + 1}`);
}

describe('CollapsibleList', () => {
    it('renders all items directly when under threshold', () => {
        render(
            <CollapsibleList
                items={makeItems(5)}
                renderItem={(item) => <li key={item}>{item}</li>}
                itemLabel="items"
                wrapper={(children) => <ul data-testid="list">{children}</ul>}
            />,
        );
        expect(screen.getByTestId('list')).toBeInTheDocument();
        expect(screen.getAllByRole('listitem')).toHaveLength(5);
        expect(screen.queryByRole('button')).not.toBeInTheDocument();
    });

    it('hides overflow items with CSS when above threshold and collapsed', () => {
        render(
            <CollapsibleList
                items={makeItems(15)}
                renderItem={(item) => <li key={item}>{item}</li>}
                itemLabel="contributors"
                wrapper={(children) => <ul>{children}</ul>}
            />,
        );
        expect(screen.getByRole('button')).toHaveTextContent('Show all 15 contributors');
        const allItems = screen.getAllByRole('listitem');
        expect(allItems).toHaveLength(15);
        const visibleItems = allItems.filter((el) => !el.classList.contains('hidden'));
        expect(visibleItems).toHaveLength(10);
    });

    it('toggles expanded state on button click', () => {
        render(
            <CollapsibleList
                items={makeItems(15)}
                renderItem={(item) => <li key={item}>{item}</li>}
                itemLabel="items"
                wrapper={(children) => <ul>{children}</ul>}
            />,
        );
        const button = screen.getByRole('button');
        expect(button).toHaveTextContent('Show all 15 items');
        expect(button).toHaveAttribute('aria-expanded', 'false');
        const allItems = screen.getAllByRole('listitem');
        expect(allItems).toHaveLength(15);
        expect(allItems.filter((el) => !el.classList.contains('hidden'))).toHaveLength(10);

        fireEvent.click(button);
        expect(button).toHaveTextContent('Show fewer items');
        expect(button).toHaveAttribute('aria-expanded', 'true');
        expect(screen.getAllByRole('listitem')).toHaveLength(15);
        expect(screen.getAllByRole('listitem').filter((el) => el.classList.contains('hidden'))).toHaveLength(0);

        fireEvent.click(button);
        expect(button).toHaveTextContent('Show all 15 items');
        expect(button).toHaveAttribute('aria-expanded', 'false');
        expect(screen.getAllByRole('listitem').filter((el) => !el.classList.contains('hidden'))).toHaveLength(10);
    });

    it('uses aria-controls to reference the region', () => {
        render(
            <CollapsibleList
                items={makeItems(12)}
                renderItem={(item) => <li key={item}>{item}</li>}
                itemLabel="funders"
                wrapper={(children) => <ul>{children}</ul>}
            />,
        );
        const button = screen.getByRole('button');
        const regionId = button.getAttribute('aria-controls');
        expect(regionId).toBeTruthy();
        expect(screen.getByRole('region')).toHaveAttribute('id', regionId);
    });

    it('respects custom threshold', () => {
        render(
            <CollapsibleList
                items={makeItems(8)}
                renderItem={(item) => <li key={item}>{item}</li>}
                threshold={5}
                itemLabel="items"
                wrapper={(children) => <ul>{children}</ul>}
            />,
        );
        expect(screen.getByRole('button')).toHaveTextContent('Show all 8 items');
        const allItems = screen.getAllByRole('listitem');
        expect(allItems).toHaveLength(8);
        expect(allItems.filter((el) => !el.classList.contains('hidden'))).toHaveLength(5);
    });

    it('does not show button when itemCount equals threshold', () => {
        render(
            <CollapsibleList
                items={makeItems(10)}
                renderItem={(item) => <li key={item}>{item}</li>}
                threshold={10}
                itemLabel="items"
                wrapper={(children) => <ul>{children}</ul>}
            />,
        );
        expect(screen.queryByRole('button')).not.toBeInTheDocument();
        expect(screen.getAllByRole('listitem')).toHaveLength(10);
    });

    it('renders without wrapper when none provided', () => {
        render(<CollapsibleList items={makeItems(3)} renderItem={(item) => <span key={item}>{item}</span>} itemLabel="items" />);
        expect(screen.getByText('Item 1')).toBeInTheDocument();
        expect(screen.getByText('Item 3')).toBeInTheDocument();
    });
});
