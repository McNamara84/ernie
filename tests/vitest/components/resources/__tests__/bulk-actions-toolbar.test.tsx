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

    it('renders an instructional message when no rows are selected', () => {
        render(<ResourcesBulkActionsToolbar {...baseProps} />);

        expect(screen.getByText(/select rows to enable resource actions/i)).toBeInTheDocument();
    });

    it('renders singular and plural selection copy', () => {
        const { rerender } = render(<ResourcesBulkActionsToolbar {...baseProps} selectedCount={1} />);
        expect(screen.getByText('1 resource selected')).toBeInTheDocument();

        rerender(<ResourcesBulkActionsToolbar {...baseProps} selectedCount={4} />);
        expect(screen.getByText('4 resources selected')).toBeInTheDocument();
    });

    it('renders the complete action surface when actions are visible', () => {
        render(<ResourcesBulkActionsToolbar {...baseProps} actions={makeActions()} />);

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

    it('hides actions marked as not visible', () => {
        render(
            <ResourcesBulkActionsToolbar
                {...baseProps}
                actions={makeActions({
                    'register-doi': { visible: false, available: false },
                    'update-metadata': { visible: false, available: false },
                    delete: { visible: false, available: false },
                })}
            />,
        );

        expect(screen.queryByTestId('resources-action-register-doi')).not.toBeInTheDocument();
        expect(screen.queryByTestId('resources-action-update-metadata')).not.toBeInTheDocument();
        expect(screen.queryByTestId('resources-action-delete')).not.toBeInTheDocument();
    });

    it('invokes onAction for an available action', async () => {
        const onAction = vi.fn();

        render(
            <ResourcesBulkActionsToolbar {...baseProps} selectedCount={1} actions={makeActions({ edit: { available: true } })} onAction={onAction} />,
        );

        await userEvent.click(screen.getByTestId('resources-action-edit'));

        expect(onAction).toHaveBeenCalledTimes(1);
        expect(onAction).toHaveBeenCalledWith('edit');
    });

    it('uses the action label as the title for available actions', () => {
        render(<ResourcesBulkActionsToolbar {...baseProps} selectedCount={1} actions={makeActions({ edit: { available: true } })} />);

        const button = screen.getByTestId('resources-action-edit');
        expect(button).toHaveAttribute('title', 'Edit');
        expect(button).not.toHaveAttribute('aria-disabled');
    });

    it('keeps unavailable actions clickable and reports their reason', async () => {
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

        const button = screen.getByTestId('resources-action-setup-landing-page');
        expect(button).toHaveAttribute('aria-disabled', 'true');
        expect(button).not.toBeDisabled();

        await userEvent.click(button);

        expect(onUnavailableAction).toHaveBeenCalledWith('This action can only be performed on a single record.');
    });

    it('uses a fallback message for unavailable actions without an explicit reason', async () => {
        const onUnavailableAction = vi.fn();

        render(
            <ResourcesBulkActionsToolbar
                {...baseProps}
                actions={makeActions({ 'export-jsonld': { available: false } })}
                onUnavailableAction={onUnavailableAction}
            />,
        );

        await userEvent.click(screen.getByTestId('resources-action-export-jsonld'));

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

        const button = screen.getByTestId('resources-action-export-datacite-xml');
        expect(button).toBeDisabled();
        expect(button).toHaveTextContent('Working...');

        await userEvent.click(button);

        expect(onAction).not.toHaveBeenCalled();
        expect(onUnavailableAction).not.toHaveBeenCalled();
    });
});
