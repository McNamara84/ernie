import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import { PortalClassificationFilter } from '@/components/portal/PortalClassificationFilter';
import { PortalMaterialFilter } from '@/components/portal/PortalMaterialFilter';
import { PortalValueFacetFilter } from '@/components/portal/PortalValueFacetFilter';

describe('IGSN portal facet controls', () => {
    it('expands selected material paths and toggles parents and chips', async () => {
        const user = userEvent.setup();
        const onSelectionChange = vi.fn();

        render(
            <PortalMaterialFilter
                facets={[
                    {
                        value: 'Liquid',
                        label: 'Liquid',
                        count: 5,
                        children: [
                            {
                                value: 'Liquid>aqueous',
                                label: 'aqueous',
                                count: 5,
                                children: [
                                    {
                                        value: 'Liquid>aqueous>porewater',
                                        label: 'porewater',
                                        count: 2,
                                        children: [],
                                    },
                                ],
                            },
                        ],
                    },
                ]}
                selectedValues={['Liquid>aqueous>porewater']}
                onSelectionChange={onSelectionChange}
            />,
        );

        expect(screen.getByRole('checkbox', { name: 'Select porewater' })).toBeChecked();
        expect(screen.getByRole('button', { name: 'Remove porewater' })).toBeInTheDocument();
        expect(screen.getByRole('list', { name: 'Materials' })).toBeInTheDocument();
        expect(screen.queryByRole('tree')).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Liquid' })).toHaveAttribute('data-slot', 'button');

        await user.click(screen.getByRole('checkbox', { name: 'Select Liquid' }));
        expect(onSelectionChange).toHaveBeenLastCalledWith(['Liquid>aqueous>porewater', 'Liquid']);

        await user.click(screen.getByRole('button', { name: 'Remove porewater' }));
        expect(onSelectionChange).toHaveBeenLastCalledWith([]);
    });

    it('searches grouped classifications and preserves AND selections', async () => {
        const user = userEvent.setup();
        const onSelectionChange = vi.fn();

        render(
            <PortalClassificationFilter
                groups={[
                    {
                        type: 'rock',
                        label: 'Rock',
                        options: [
                            { value: 'Igneous', label: 'Igneous', count: 8 },
                            { value: 'Metamorphic', label: 'Metamorphic', count: 3 },
                        ],
                    },
                    {
                        type: 'mineral',
                        label: 'Mineral',
                        options: [{ value: 'Quartz', label: 'Quartz', count: 4 }],
                    },
                ]}
                selectedValues={['Igneous']}
                onSelectionChange={onSelectionChange}
            />,
        );

        expect(screen.getByText('Every selected classification must be present.')).toBeInTheDocument();
        await user.type(screen.getByRole('textbox', { name: 'Search classifications' }), 'meta');

        expect(screen.getByText('Metamorphic')).toBeInTheDocument();
        expect(screen.queryByText('Quartz')).not.toBeInTheDocument();

        await user.click(screen.getByRole('checkbox', { name: 'Select Metamorphic' }));
        expect(onSelectionChange).toHaveBeenCalledWith(['Igneous', 'Metamorphic']);

        await user.click(screen.getByRole('button', { name: 'Remove Igneous' }));
        expect(onSelectionChange).toHaveBeenLastCalledWith([]);
    });

    it('searches geological values, toggles options, and reports empty facets', async () => {
        const user = userEvent.setup();
        const onSelectionChange = vi.fn();
        const { rerender } = render(
            <PortalValueFacetFilter
                options={[
                    { value: 'Jurassic', label: 'Jurassic', count: 10 },
                    { value: 'Cretaceous', label: 'Cretaceous', count: 6 },
                ]}
                selectedValues={[]}
                onSelectionChange={onSelectionChange}
                ariaLabel="Geological ages"
                emptyMessage="No geological ages available."
                helperText="Every selected geological age must be present."
                searchable
                searchPlaceholder="Search geological ages..."
            />,
        );

        await user.type(screen.getByRole('textbox', { name: 'Search geological ages...' }), 'jura');
        expect(screen.getByText('Jurassic')).toBeInTheDocument();
        expect(screen.queryByText('Cretaceous')).not.toBeInTheDocument();

        await user.click(screen.getByRole('checkbox', { name: 'Select Jurassic' }));
        expect(onSelectionChange).toHaveBeenCalledWith(['Jurassic']);

        rerender(
            <PortalValueFacetFilter
                options={[]}
                selectedValues={[]}
                onSelectionChange={onSelectionChange}
                ariaLabel="Geological ages"
                emptyMessage="No geological ages available."
                helperText="Every selected geological age must be present."
            />,
        );
        expect(screen.getByText('No geological ages available.')).toBeInTheDocument();
    });
});
