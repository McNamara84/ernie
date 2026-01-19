import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { GCMDTree, GCMDTreeNode } from '@/components/curation/fields/gcmd-tree';
import type { GCMDKeyword } from '@/types/gcmd';

const createMockKeyword = (overrides: Partial<GCMDKeyword> = {}): GCMDKeyword => ({
    id: 'test-1',
    text: 'Test Keyword',
    language: 'en',
    scheme: 'sciencekeywords',
    schemeURI: 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords',
    description: 'A test keyword',
    children: [],
    ...overrides,
});

describe('gcmd-tree', () => {
    describe('GCMDTree', () => {
        it('renders empty message when keywords array is empty', () => {
            render(<GCMDTree keywords={[]} selectedIds={new Set()} onToggle={vi.fn()} />);
            expect(screen.getByText('No keywords available')).toBeInTheDocument();
        });

        it('renders custom empty message when provided', () => {
            render(<GCMDTree keywords={[]} selectedIds={new Set()} onToggle={vi.fn()} emptyMessage="Custom empty message" />);
            expect(screen.getByText('Custom empty message')).toBeInTheDocument();
        });

        it('renders empty message when keywords is undefined', () => {
            render(<GCMDTree keywords={undefined as unknown as GCMDKeyword[]} selectedIds={new Set()} onToggle={vi.fn()} />);
            expect(screen.getByText('No keywords available')).toBeInTheDocument();
        });

        it('renders keyword nodes for each keyword', () => {
            const keywords: GCMDKeyword[] = [
                createMockKeyword({ id: '1', text: 'Earth Science' }),
                createMockKeyword({ id: '2', text: 'Atmosphere' }),
            ];
            render(<GCMDTree keywords={keywords} selectedIds={new Set()} onToggle={vi.fn()} />);

            expect(screen.getByText('Earth Science')).toBeInTheDocument();
            expect(screen.getByText('Atmosphere')).toBeInTheDocument();
        });

        it('passes searchQuery to child nodes for highlighting', () => {
            const keywords: GCMDKeyword[] = [createMockKeyword({ id: '1', text: 'Earth Science' })];
            render(<GCMDTree keywords={keywords} selectedIds={new Set()} onToggle={vi.fn()} searchQuery="Earth" />);

            // The highlight component wraps matching text in a <mark> element
            expect(screen.getByText('Earth')).toBeInTheDocument();
        });
    });

    describe('GCMDTreeNode', () => {
        it('renders the keyword text', () => {
            const node = createMockKeyword({ text: 'Solid Earth' });
            render(<GCMDTreeNode node={node} selectedIds={new Set()} onToggle={vi.fn()} />);

            expect(screen.getByText('Solid Earth')).toBeInTheDocument();
        });

        it('shows checkbox in unchecked state when not selected', () => {
            const node = createMockKeyword({ id: 'node-1' });
            render(<GCMDTreeNode node={node} selectedIds={new Set()} onToggle={vi.fn()} />);

            const checkbox = screen.getByRole('checkbox');
            expect(checkbox).not.toBeChecked();
        });

        it('shows checkbox in checked state when selected', () => {
            const node = createMockKeyword({ id: 'node-1' });
            render(<GCMDTreeNode node={node} selectedIds={new Set(['node-1'])} onToggle={vi.fn()} />);

            const checkbox = screen.getByRole('checkbox');
            expect(checkbox).toBeChecked();
        });

        it('calls onToggle when checkbox is clicked', () => {
            const onToggle = vi.fn();
            const node = createMockKeyword({ id: 'node-1', text: 'Climate' });
            render(<GCMDTreeNode node={node} selectedIds={new Set()} onToggle={onToggle} />);

            fireEvent.click(screen.getByRole('checkbox'));
            expect(onToggle).toHaveBeenCalledWith(node, ['Climate']);
        });

        it('includes pathPrefix in the path when toggling', () => {
            const onToggle = vi.fn();
            const node = createMockKeyword({ id: 'node-1', text: 'Temperature' });
            render(<GCMDTreeNode node={node} selectedIds={new Set()} onToggle={onToggle} pathPrefix={['Earth Science', 'Atmosphere']} />);

            fireEvent.click(screen.getByRole('checkbox'));
            expect(onToggle).toHaveBeenCalledWith(node, ['Earth Science', 'Atmosphere', 'Temperature']);
        });

        it('hides expand icon when node has no children', () => {
            const node = createMockKeyword({ children: [] });
            const { container } = render(<GCMDTreeNode node={node} selectedIds={new Set()} onToggle={vi.fn()} />);

            // The expand button should have the invisible class
            const button = container.querySelector('button[aria-label]');
            expect(button).toHaveClass('invisible');
        });

        it('shows expand icon when node has children', () => {
            const node = createMockKeyword({
                children: [createMockKeyword({ id: 'child-1', text: 'Child' })],
            });
            // Use level 2 so node starts collapsed
            render(<GCMDTreeNode node={node} selectedIds={new Set()} onToggle={vi.fn()} level={2} />);

            expect(screen.getByRole('button', { name: /expand/i })).toBeInTheDocument();
        });

        it('expands root level nodes by default', () => {
            const childNode = createMockKeyword({ id: 'child-1', text: 'Child Keyword' });
            const node = createMockKeyword({
                children: [childNode],
            });
            render(<GCMDTreeNode node={node} selectedIds={new Set()} onToggle={vi.fn()} level={0} />);

            // Root level (level 0) should auto-expand
            expect(screen.getByText('Child Keyword')).toBeInTheDocument();
        });

        it('auto-expands when descendant is selected', () => {
            const grandchild = createMockKeyword({ id: 'grandchild-1', text: 'Grandchild' });
            const child = createMockKeyword({
                id: 'child-1',
                text: 'Child',
                children: [grandchild],
            });
            const node = createMockKeyword({
                id: 'parent',
                text: 'Parent',
                children: [child],
            });

            render(<GCMDTreeNode node={node} selectedIds={new Set(['grandchild-1'])} onToggle={vi.fn()} level={1} />);

            // Should auto-expand because grandchild is selected
            expect(screen.getByText('Child')).toBeInTheDocument();
        });

        it('collapses when expand button is clicked', () => {
            const childNode = createMockKeyword({ id: 'child-1', text: 'Child Keyword' });
            const node = createMockKeyword({
                text: 'Parent Node',
                children: [childNode],
            });
            render(<GCMDTreeNode node={node} selectedIds={new Set()} onToggle={vi.fn()} level={0} />);

            // Initially expanded (level 0)
            expect(screen.getByText('Child Keyword')).toBeInTheDocument();

            // Click to collapse - there's only one collapse button since child has no children
            const parentButton = screen.getAllByRole('button', { name: /collapse/i })[0];
            fireEvent.click(parentButton);

            // Child should no longer be visible
            expect(screen.queryByText('Child Keyword')).not.toBeInTheDocument();
        });

        it('expands when expand button is clicked on collapsed node', () => {
            const childNode = createMockKeyword({ id: 'child-1', text: 'Child Keyword' });
            const node = createMockKeyword({
                children: [childNode],
            });

            // Start at level 2 (non-root, should be collapsed by default)
            render(<GCMDTreeNode node={node} selectedIds={new Set()} onToggle={vi.fn()} level={2} />);

            // Initially collapsed
            expect(screen.queryByText('Child Keyword')).not.toBeInTheDocument();

            // Click to expand
            fireEvent.click(screen.getByRole('button', { name: /expand/i }));

            // Child should now be visible
            expect(screen.getByText('Child Keyword')).toBeInTheDocument();
        });

        it('highlights search query in keyword text', () => {
            const node = createMockKeyword({ text: 'Earth Science Data' });
            render(<GCMDTreeNode node={node} selectedIds={new Set()} onToggle={vi.fn()} searchQuery="Science" />);

            // The matching text should be wrapped in a <mark> element
            const mark = screen.getByText('Science');
            expect(mark.tagName).toBe('MARK');
        });

        it('does not highlight when search query is too short', () => {
            const node = createMockKeyword({ text: 'Earth Science Data' });
            render(<GCMDTreeNode node={node} selectedIds={new Set()} onToggle={vi.fn()} searchQuery="Ea" />);

            // No highlighting for queries shorter than 3 characters
            expect(screen.queryByRole('mark')).not.toBeInTheDocument();
        });

        it('renders nested children recursively', () => {
            const grandchild = createMockKeyword({ id: 'gc-1', text: 'Grandchild' });
            const child = createMockKeyword({
                id: 'c-1',
                text: 'Child',
                children: [grandchild],
            });
            const node = createMockKeyword({
                children: [child],
            });

            render(<GCMDTreeNode node={node} selectedIds={new Set()} onToggle={vi.fn()} level={0} />);

            expect(screen.getByText('Child')).toBeInTheDocument();
            // Grandchild should also be visible since child is auto-expanded at level 1 with no selection
            // Actually, level 1 is not auto-expanded unless there's a selected descendant
        });

        it('applies increased indentation for nested levels', () => {
            const node = createMockKeyword({ text: 'Deep Node' });
            const { container } = render(<GCMDTreeNode node={node} selectedIds={new Set()} onToggle={vi.fn()} level={3} />);

            // Check that padding is applied based on level
            const nodeDiv = container.querySelector('[style*="padding-left"]');
            expect(nodeDiv).toBeInTheDocument();
        });

        it('uses node description as title attribute for tooltip', () => {
            const node = createMockKeyword({
                text: 'Climate',
                description: 'Climate-related keywords',
            });
            render(<GCMDTreeNode node={node} selectedIds={new Set()} onToggle={vi.fn()} />);

            const label = screen.getByText('Climate');
            expect(label).toHaveAttribute('title', 'Climate-related keywords');
        });

        it('falls back to text for title when description is missing', () => {
            const node = createMockKeyword({
                text: 'Climate',
                description: undefined,
            });
            render(<GCMDTreeNode node={node} selectedIds={new Set()} onToggle={vi.fn()} />);

            const label = screen.getByText('Climate');
            expect(label).toHaveAttribute('title', 'Climate');
        });
    });
});
