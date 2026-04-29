import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { MetadataList, type MetadataRow } from '@/pages/LandingPages/components/MetadataList';

describe('MetadataList', () => {
    it('returns null when all rows are empty', () => {
        const rows: MetadataRow[] = [
            { label: 'A', value: null },
            { label: 'B', value: undefined },
            { label: 'C', value: '' },
            { label: 'D', value: '   ' },
            { label: 'E', value: [] },
        ];

        const { container } = render(<>{MetadataList({ rows })}</>);

        expect(container.firstChild).toBeNull();
    });

    it('renders only non-empty rows', () => {
        const rows: MetadataRow[] = [
            { label: 'Visible', value: 'value' },
            { label: 'Hidden', value: null },
            { label: 'Empty Array', value: [] },
            { label: 'Has Items', value: ['x'] },
            { label: 'Whitespace', value: '   ' },
        ];

        render(<>{MetadataList({ rows })}</>);

        expect(screen.getByText('Visible')).toBeInTheDocument();
        expect(screen.getByText('value')).toBeInTheDocument();
        expect(screen.getByText('Has Items')).toBeInTheDocument();
        expect(screen.queryByText('Hidden')).not.toBeInTheDocument();
        expect(screen.queryByText('Empty Array')).not.toBeInTheDocument();
        expect(screen.queryByText('Whitespace')).not.toBeInTheDocument();
    });

    it('renders ReactNode values such as elements and numbers', () => {
        const rows: MetadataRow[] = [
            { label: 'Element', value: <span data-testid="el">child</span> },
            { label: 'Number', value: 0 },
        ];

        render(<>{MetadataList({ rows })}</>);

        expect(screen.getByTestId('el')).toBeInTheDocument();
        // Number 0 is truthy as ReactNode (not empty), so it should render
        expect(screen.getByText('Element')).toBeInTheDocument();
        expect(screen.getByText('Number')).toBeInTheDocument();
    });

    it('uses a definition-list root with the documented data-slot', () => {
        const rows: MetadataRow[] = [{ label: 'Foo', value: 'bar' }];

        const { container } = render(<>{MetadataList({ rows })}</>);

        const dl = container.querySelector('dl[data-slot="metadata-list"]');
        expect(dl).not.toBeNull();
        expect(dl?.querySelector('dt')?.textContent).toBe('Foo');
        expect(dl?.querySelector('dd')?.textContent).toBe('bar');
    });
});
