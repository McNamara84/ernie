import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { ResourcesBulkActionsToolbar } from '@/components/resources/bulk-actions-toolbar';

describe('ResourcesBulkActionsToolbar', () => {
    const baseProps = {
        selectedCount: 0,
        onRegister: vi.fn(),
        onExport: vi.fn(),
        canRegister: true,
        isRegistering: false,
        isExporting: false,
    };

    beforeEach(() => {
        baseProps.onRegister = vi.fn();
        baseProps.onExport = vi.fn();
    });

    describe('rendering', () => {
        it('renders an instructional message when no rows are selected', () => {
            render(<ResourcesBulkActionsToolbar {...baseProps} />);
            expect(screen.getByText(/Select rows to enable bulk actions/i)).toBeInTheDocument();
        });

        it('renders singular "resource" for one selection', () => {
            render(<ResourcesBulkActionsToolbar {...baseProps} selectedCount={1} />);
            expect(screen.getByText('1 resource selected')).toBeInTheDocument();
        });

        it('renders plural "resources" for multiple selections', () => {
            render(<ResourcesBulkActionsToolbar {...baseProps} selectedCount={4} />);
            expect(screen.getByText('4 resources selected')).toBeInTheDocument();
        });
    });

    describe('register button', () => {
        it('is hidden when canRegister is false', () => {
            render(<ResourcesBulkActionsToolbar {...baseProps} canRegister={false} selectedCount={2} />);
            expect(screen.queryByTestId('bulk-register-button')).not.toBeInTheDocument();
        });

        it('is disabled when nothing is selected', () => {
            render(<ResourcesBulkActionsToolbar {...baseProps} />);
            expect(screen.getByTestId('bulk-register-button')).toBeDisabled();
        });

        it('is enabled when at least one row is selected', () => {
            render(<ResourcesBulkActionsToolbar {...baseProps} selectedCount={2} />);
            expect(screen.getByTestId('bulk-register-button')).toBeEnabled();
        });

        it('invokes onRegister on click', async () => {
            const onRegister = vi.fn();
            render(
                <ResourcesBulkActionsToolbar
                    {...baseProps}
                    selectedCount={2}
                    onRegister={onRegister}
                />,
            );

            await userEvent.click(screen.getByTestId('bulk-register-button'));
            expect(onRegister).toHaveBeenCalledTimes(1);
        });

        it('shows a loading indicator while registering and disables both action buttons', () => {
            render(
                <ResourcesBulkActionsToolbar
                    {...baseProps}
                    selectedCount={2}
                    isRegistering
                />,
            );

            expect(screen.getByText(/Registering/i)).toBeInTheDocument();
            expect(screen.getByTestId('bulk-register-button')).toBeDisabled();
            expect(screen.getByTestId('bulk-export-button')).toBeDisabled();
        });
    });

    describe('export dropdown', () => {
        it('is disabled when nothing is selected', () => {
            render(<ResourcesBulkActionsToolbar {...baseProps} />);
            expect(screen.getByTestId('bulk-export-button')).toBeDisabled();
        });

        it('is enabled when rows are selected', () => {
            render(<ResourcesBulkActionsToolbar {...baseProps} selectedCount={1} />);
            expect(screen.getByTestId('bulk-export-button')).toBeEnabled();
        });

        it('invokes onExport with the chosen format', async () => {
            const onExport = vi.fn();
            render(
                <ResourcesBulkActionsToolbar
                    {...baseProps}
                    selectedCount={2}
                    onExport={onExport}
                />,
            );

            await userEvent.click(screen.getByTestId('bulk-export-button'));
            await userEvent.click(await screen.findByRole('menuitem', { name: /DataCite XML/i }));

            expect(onExport).toHaveBeenCalledTimes(1);
            expect(onExport).toHaveBeenCalledWith('datacite-xml');
        });

        it('invokes onExport with datacite-json when the JSON option is chosen', async () => {
            const onExport = vi.fn();
            render(
                <ResourcesBulkActionsToolbar
                    {...baseProps}
                    selectedCount={1}
                    onExport={onExport}
                />,
            );

            await userEvent.click(screen.getByTestId('bulk-export-button'));
            await userEvent.click(await screen.findByRole('menuitem', { name: /DataCite JSON$/i }));

            expect(onExport).toHaveBeenCalledTimes(1);
            expect(onExport).toHaveBeenCalledWith('datacite-json');
        });

        it('invokes onExport with jsonld when the JSON-LD option is chosen', async () => {
            const onExport = vi.fn();
            render(
                <ResourcesBulkActionsToolbar
                    {...baseProps}
                    selectedCount={1}
                    onExport={onExport}
                />,
            );

            await userEvent.click(screen.getByTestId('bulk-export-button'));
            await userEvent.click(await screen.findByRole('menuitem', { name: /DataCite JSON-LD/i }));

            expect(onExport).toHaveBeenCalledTimes(1);
            expect(onExport).toHaveBeenCalledWith('jsonld');
        });

        it('exposes all three export formats', async () => {
            render(<ResourcesBulkActionsToolbar {...baseProps} selectedCount={1} />);
            await userEvent.click(screen.getByTestId('bulk-export-button'));

            expect(await screen.findByRole('menuitem', { name: /DataCite JSON$/i })).toBeInTheDocument();
            expect(screen.getByRole('menuitem', { name: /DataCite XML/i })).toBeInTheDocument();
            expect(screen.getByRole('menuitem', { name: /DataCite JSON-LD/i })).toBeInTheDocument();
        });

        it('shows a loading indicator while exporting and disables both action buttons', () => {
            render(
                <ResourcesBulkActionsToolbar
                    {...baseProps}
                    selectedCount={2}
                    isExporting
                />,
            );

            expect(screen.getByText(/Exporting/i)).toBeInTheDocument();
            expect(screen.getByTestId('bulk-export-button')).toBeDisabled();
            expect(screen.getByTestId('bulk-register-button')).toBeDisabled();
        });
    });

    describe('register disabled reason', () => {
        it('renders an inline hint and disables the register button when a reason is provided', () => {
            render(
                <ResourcesBulkActionsToolbar
                    {...baseProps}
                    selectedCount={3}
                    registerDisabledReason="2 resources have no DOI yet."
                />,
            );

            expect(screen.getByTestId('bulk-register-blocked-hint')).toHaveTextContent(
                '2 resources have no DOI yet.',
            );
            expect(screen.getByTestId('bulk-register-button')).toBeDisabled();
        });

        it('wires aria-describedby so assistive tech can announce the reason', () => {
            render(
                <ResourcesBulkActionsToolbar
                    {...baseProps}
                    selectedCount={1}
                    registerDisabledReason="Selection contains a DOI-less resource."
                />,
            );

            const hint = screen.getByTestId('bulk-register-blocked-hint');
            // The tooltip trigger wraps the button; aria-describedby must point to the hint id.
            const wrapper = screen.getByTestId('bulk-register-button').parentElement;
            expect(wrapper).toHaveAttribute('aria-describedby', hint.id);
        });

        it('does not render the hint when no reason is supplied', () => {
            render(<ResourcesBulkActionsToolbar {...baseProps} selectedCount={1} />);
            expect(screen.queryByTestId('bulk-register-blocked-hint')).not.toBeInTheDocument();
        });
    });
});
