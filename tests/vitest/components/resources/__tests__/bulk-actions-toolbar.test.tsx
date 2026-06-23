import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { type ResourcesActionKey, type ResourcesActionState, ResourcesBulkActionsToolbar } from '@/components/resources/bulk-actions-toolbar';

const makeActions = (
    overrides: Partial<Record<ResourcesActionKey, ResourcesActionState>> = {},
): Record<ResourcesActionKey, ResourcesActionState> => ({
    edit: { available: false, reason: 'Select one or more resources first.' },
    'setup-landing-page': { available: false, reason: 'Select exactly one resource.' },
    'manage-related-items': { available: false, reason: 'Select exactly one resource.' },
    'export-datacite-json': { available: false, reason: 'Select one or more resources first.' },
    'export-datacite-xml': { available: false, reason: 'Select one or more resources first.' },
    'export-jsonld': { available: false, reason: 'Select one or more resources first.' },
    'register-doi': { available: false, reason: 'Select exactly one resource.' },
    'update-metadata': { available: false, reason: 'Select one or more resources first.' },
    delete: { available: false, reason: 'Select one or more resources first.' },
    ...overrides,
});

const openActionsMenu = async () => {
    await userEvent.click(screen.getByTestId('resources-actions-menu-trigger'));
};

const clickAction = async (testId: string) => {
    await openActionsMenu();
    await userEvent.click(screen.getByTestId(testId));
};

describe('ResourcesBulkActionsToolbar', () => {
    const baseProps = {
        selectedCount: 0,
        actions: makeActions(),
        onAction: vi.fn(),
        onUnavailableAction: vi.fn(),
    };

    beforeEach(() => {
        baseProps.onAction = vi.fn();
        baseProps.onUnavailableAction = vi.fn();
        baseProps.actions = makeActions();
    });

    it('renders an instructional message and disables the action menu when no rows are selected', async () => {
        render(<ResourcesBulkActionsToolbar {...baseProps} />);

        const trigger = screen.getByTestId('resources-actions-menu-trigger');
        expect(screen.getByText(/select rows to enable resource actions/i)).toBeInTheDocument();
        expect(trigger).toBeDisabled();
        expect(trigger).toHaveAttribute('title', 'Select rows to enable resource actions');

        await userEvent.click(trigger);

        expect(screen.queryByRole('menu')).not.toBeInTheDocument();
    });

    it('renders singular and plural selection copy', () => {
        const { rerender } = render(<ResourcesBulkActionsToolbar {...baseProps} selectedCount={1} />);
        expect(screen.getByText('1 resource selected')).toBeInTheDocument();

        rerender(<ResourcesBulkActionsToolbar {...baseProps} selectedCount={4} />);
        expect(screen.getByText('4 resources selected')).toBeInTheDocument();
    });

    it('renders the complete action surface in the action menu when actions are visible', async () => {
        render(<ResourcesBulkActionsToolbar {...baseProps} selectedCount={1} actions={makeActions()} />);

        await openActionsMenu();

        expect(screen.getByTestId('resources-action-edit')).toBeInTheDocument();
        expect(screen.getByTestId('resources-action-setup-landing-page')).toBeInTheDocument();
        expect(screen.getByTestId('resources-action-manage-related-items')).toBeInTheDocument();
        expect(screen.getByTestId('resources-action-export-datacite-json')).toBeInTheDocument();
        expect(screen.getByTestId('resources-action-export-datacite-xml')).toBeInTheDocument();
        expect(screen.getByTestId('resources-action-export-jsonld')).toBeInTheDocument();
        expect(screen.getByTestId('resources-action-register-doi')).toBeInTheDocument();
        expect(screen.getByTestId('resources-action-update-metadata')).toBeInTheDocument();
        expect(screen.getByTestId('resources-action-delete')).toBeInTheDocument();
    });

    it('hides actions marked as not visible', async () => {
        render(
            <ResourcesBulkActionsToolbar
                {...baseProps}
                selectedCount={1}
                actions={makeActions({
                    'register-doi': { visible: false, available: false },
                    'update-metadata': { visible: false, available: false },
                    delete: { visible: false, available: false },
                })}
            />,
        );

        await openActionsMenu();

        expect(screen.queryByTestId('resources-action-register-doi')).not.toBeInTheDocument();
        expect(screen.queryByTestId('resources-action-update-metadata')).not.toBeInTheDocument();
        expect(screen.queryByTestId('resources-action-delete')).not.toBeInTheDocument();
    });

    it('invokes onAction for an available action', async () => {
        const onAction = vi.fn();

        render(
            <ResourcesBulkActionsToolbar {...baseProps} selectedCount={1} actions={makeActions({ edit: { available: true } })} onAction={onAction} />,
        );

        await clickAction('resources-action-edit');

        expect(onAction).toHaveBeenCalledTimes(1);
        expect(onAction).toHaveBeenCalledWith('edit');
    });

    it('uses the action label as the title for available actions', async () => {
        render(<ResourcesBulkActionsToolbar {...baseProps} selectedCount={1} actions={makeActions({ edit: { available: true } })} />);

        await openActionsMenu();

        const item = screen.getByTestId('resources-action-edit');
        expect(item).toHaveAttribute('title', 'Edit');
        expect(item).not.toHaveAttribute('aria-disabled');
    });

    it('keeps unavailable actions selectable and reports their reason', async () => {
        const onUnavailableAction = vi.fn();

        render(
            <ResourcesBulkActionsToolbar
                {...baseProps}
                selectedCount={2}
                actions={makeActions({
                    'setup-landing-page': {
                        available: false,
                        reason: 'This action can only be performed on a single record.',
                    },
                })}
                onUnavailableAction={onUnavailableAction}
            />,
        );

        await openActionsMenu();

        const item = screen.getByTestId('resources-action-setup-landing-page');
        expect(item).toHaveAttribute('aria-disabled', 'true');

        await userEvent.click(item);

        expect(onUnavailableAction).toHaveBeenCalledWith('This action can only be performed on a single record.');
    });

    it('uses a fallback message and title for unavailable actions without an explicit reason', async () => {
        const onUnavailableAction = vi.fn();

        render(
            <ResourcesBulkActionsToolbar
                {...baseProps}
                selectedCount={1}
                actions={makeActions({ 'export-jsonld': { available: false } })}
                onUnavailableAction={onUnavailableAction}
            />,
        );

        await openActionsMenu();

        const item = screen.getByTestId('resources-action-export-jsonld');
        expect(item).toHaveAttribute('title', 'This action is not available for the current selection.');

        await userEvent.click(item);

        expect(onUnavailableAction).toHaveBeenCalledWith('This action is not available for the current selection.');
    });

    it('disables an action while it is loading', async () => {
        const onAction = vi.fn();
        const onUnavailableAction = vi.fn();

        render(
            <ResourcesBulkActionsToolbar
                {...baseProps}
                selectedCount={2}
                actions={makeActions({ 'export-datacite-xml': { available: true, loading: true } })}
                onAction={onAction}
                onUnavailableAction={onUnavailableAction}
            />,
        );

        await openActionsMenu();

        const item = screen.getByTestId('resources-action-export-datacite-xml');
        expect(item).toHaveAttribute('aria-disabled', 'true');
        expect(item).toHaveTextContent('Working...');

        await userEvent.click(item);

        expect(onAction).not.toHaveBeenCalled();
        expect(onUnavailableAction).not.toHaveBeenCalled();
    });
});
