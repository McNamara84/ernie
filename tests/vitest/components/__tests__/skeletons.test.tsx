import '@testing-library/jest-dom/vitest';

import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { TableSkeleton } from '@/components/ui/skeletons/table-skeleton';
import { CardSkeleton } from '@/components/ui/skeletons/card-skeleton';
import { FormSkeleton } from '@/components/ui/skeletons/form-skeleton';
import { StatSkeleton } from '@/components/ui/skeletons/stat-skeleton';
import { FilterSkeleton } from '@/components/ui/skeletons/filter-skeleton';

describe('TableSkeleton', () => {
    it('renders the default 5 rows and 4 columns', () => {
        const { container } = render(<TableSkeleton />);
        const slot = container.querySelector('[data-slot="table-skeleton"]');
        expect(slot).toBeInTheDocument();
        // 1 header row + 5 data rows = 6 flex rows
        const rows = slot!.querySelectorAll(':scope > div');
        expect(rows).toHaveLength(6);
    });

    it('renders custom rows and columns', () => {
        const { container } = render(<TableSkeleton rows={3} columns={6} />);
        const slot = container.querySelector('[data-slot="table-skeleton"]');
        // 1 header + 3 data rows
        const rows = slot!.querySelectorAll(':scope > div');
        expect(rows).toHaveLength(4);
        // Header row should have 6 skeletons
        const headerSkeletons = rows[0].querySelectorAll('[data-slot="skeleton"]');
        expect(headerSkeletons).toHaveLength(6);
    });
});

describe('CardSkeleton', () => {
    it('renders the default single card', () => {
        const { container } = render(<CardSkeleton />);
        const slot = container.querySelector('[data-slot="card-skeleton"]');
        expect(slot).toBeInTheDocument();
        const cards = slot!.querySelectorAll(':scope > div');
        expect(cards).toHaveLength(1);
    });

    it('renders multiple cards', () => {
        const { container } = render(<CardSkeleton count={3} />);
        const slot = container.querySelector('[data-slot="card-skeleton"]');
        const cards = slot!.querySelectorAll(':scope > div');
        expect(cards).toHaveLength(3);
    });

    it('hides header when showHeader is false', () => {
        const { container } = render(<CardSkeleton showHeader={false} />);
        const slot = container.querySelector('[data-slot="card-skeleton"]');
        const card = slot!.querySelector(':scope > div');
        // Without header, the card should only have the body space-y-3 div
        const children = card!.children;
        expect(children).toHaveLength(1);
    });
});

describe('FormSkeleton', () => {
    it('renders default 4 fields plus submit button', () => {
        const { container } = render(<FormSkeleton />);
        const slot = container.querySelector('[data-slot="form-skeleton"]');
        expect(slot).toBeInTheDocument();
        // 4 field groups + 1 submit button skeleton = 5 children
        expect(slot!.children).toHaveLength(5);
    });

    it('renders custom number of fields', () => {
        const { container } = render(<FormSkeleton fields={2} />);
        const slot = container.querySelector('[data-slot="form-skeleton"]');
        // 2 field groups + 1 submit = 3
        expect(slot!.children).toHaveLength(3);
    });
});

describe('StatSkeleton', () => {
    it('renders default 4 stat cards', () => {
        const { container } = render(<StatSkeleton />);
        const slot = container.querySelector('[data-slot="stat-skeleton"]');
        expect(slot).toBeInTheDocument();
        const cards = slot!.querySelectorAll(':scope > div');
        expect(cards).toHaveLength(4);
    });

    it('renders custom count', () => {
        const { container } = render(<StatSkeleton count={2} />);
        const slot = container.querySelector('[data-slot="stat-skeleton"]');
        const cards = slot!.querySelectorAll(':scope > div');
        expect(cards).toHaveLength(2);
    });
});

describe('FilterSkeleton', () => {
    it('renders default 3 filter skeletons plus action button', () => {
        const { container } = render(<FilterSkeleton />);
        const slot = container.querySelector('[data-slot="filter-skeleton"]');
        expect(slot).toBeInTheDocument();
        // 3 filters + 1 action button = 4 skeletons
        const skeletons = slot!.querySelectorAll('[data-slot="skeleton"]');
        expect(skeletons).toHaveLength(4);
    });

    it('renders custom number of filters', () => {
        const { container } = render(<FilterSkeleton filters={5} />);
        const slot = container.querySelector('[data-slot="filter-skeleton"]');
        // 5 filters + 1 action button = 6
        const skeletons = slot!.querySelectorAll('[data-slot="skeleton"]');
        expect(skeletons).toHaveLength(6);
    });
});
