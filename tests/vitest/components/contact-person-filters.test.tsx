import '@testing-library/jest-dom/vitest';

import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { IgsnFilters } from '@/components/igsns/igsn-filters';
import { ResourcesFilters } from '@/components/resources-filters';
import { contactSelectionLabel } from '@/types/contact-persons';

const selection = { key: 'a'.repeat(64), name: 'Contact, Ada', email: 'ada@example.test', orcid: '0000-0002-1825-0097' };

describe.each(['resources', 'igsns'])('%s contact filter', (target) => {
    function show(unknown = false) {
        const onFilterChange = vi.fn();
        const shared = {
            contactSelection: unknown ? { ...selection, name: null } : selection,
            filters: { contact: selection.key, datacenter_id: 7 },
            onFilterChange,
            resultCount: 1,
            totalCount: 2,
        };
        render(
            target === 'resources' ? (
                <ResourcesFilters {...shared} filterOptions={null} />
            ) : (
                <IgsnFilters {...shared} filterOptions={null} countStatus="ready" />
            ),
        );
        return onFilterChange;
    }

    it('labels the contact without exposing its hash and removes it independently', () => {
        const change = show();
        expect(screen.getByText(contactSelectionLabel(selection))).toBeVisible();
        expect(screen.queryByText(selection.key)).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: target === 'igsns' ? 'Remove contact filter' : `Remove ${contactSelectionLabel(selection)} filter` }),
        );
        expect(change).toHaveBeenCalledWith({ datacenter_id: 7 });
    });

    it('keeps unavailable selections removable', () => {
        show(true);
        expect(screen.getByText('Contact selection no longer available')).toBeVisible();
    });
});

it('labels missing addresses without displaying null', () => {
    expect(contactSelectionLabel({ ...selection, email: null, orcid: null })).toBe('Contact: Contact, Ada · No email recorded');
});
