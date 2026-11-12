import '@testing-library/jest-dom/vitest';

import userEvent from '@testing-library/user-event';
import { render, screen, waitFor } from '@tests/vitest/utils/render';
import axios from 'axios';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { EditorDataCiteConfirmationDialog } from '@/components/curation/modals/editor-datacite-confirmation-dialog';

vi.mock('axios');

const defaultProps = {
    open: true,
    action: 'register' as const,
    doi: '',
    hasLandingPage: true,
    isSubmitting: false,
    submittingAction: null,
    error: null,
    orcidBlockers: [],
    orcidWarnings: [],
    onClose: vi.fn(),
    onConfirm: vi.fn(),
};

describe('EditorDataCiteConfirmationDialog', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.mocked(axios.get).mockResolvedValue({
            data: {
                test: ['10.83279'],
                production: ['10.5880'],
                test_mode: true,
            },
        });
    });

    it('requires an explicit confirmation and passes the configured prefix', async () => {
        const user = userEvent.setup();
        const onConfirm = vi.fn();

        render(<EditorDataCiteConfirmationDialog {...defaultProps} onConfirm={onConfirm} />);

        expect(
            screen.getByText(/publish the landing page, then publish the DOI at DataCite/i),
        ).toBeInTheDocument();
        expect(onConfirm).not.toHaveBeenCalled();

        const confirmButton = await screen.findByTestId('confirm-editor-datacite-action');
        await waitFor(() => expect(confirmButton).toBeEnabled());
        await user.click(confirmButton);

        expect(onConfirm).toHaveBeenCalledWith({ prefix: '10.83279', force: false, submittingAction: 'submit' });
    });

    it('explains that an update changes the existing DOI and a local save may remain on failure', () => {
        render(<EditorDataCiteConfirmationDialog {...defaultProps} action="update" doi="10.5880/existing" />);
        expect(screen.getByText(/Visibility stays unchanged. Local changes remain saved if the transfer fails/i)).toBeInTheDocument();
    });

    it('closes without confirming and explains the landing-page continuation', async () => {
        const user = userEvent.setup();
        const onClose = vi.fn();
        const onConfirm = vi.fn();

        render(<EditorDataCiteConfirmationDialog {...defaultProps} hasLandingPage={false} onClose={onClose} onConfirm={onConfirm} />);

        expect(screen.getByText('Landing page setup follows')).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Cancel' }));

        expect(onClose).toHaveBeenCalledOnce();
        expect(onConfirm).not.toHaveBeenCalled();
    });

    it('blocks an existing DOI update when its environment cannot be loaded', async () => {
        const user = userEvent.setup();
        const onConfirm = vi.fn();
        vi.mocked(axios.get).mockRejectedValueOnce(new Error('Configuration unavailable'));

        render(<EditorDataCiteConfirmationDialog {...defaultProps} action="update" doi="10.5880/existing" onConfirm={onConfirm} />);

        expect(await screen.findByText('DOI prefix configuration unavailable')).toBeInTheDocument();
        expect(screen.getByText(/The environment must be verified/)).toBeInTheDocument();

        const confirmButton = screen.getByTestId('confirm-editor-datacite-action');
        await waitFor(() => expect(confirmButton).toBeDisabled());
        await user.click(confirmButton);

        expect(onConfirm).not.toHaveBeenCalled();
    });

    it('keeps a prefix configuration failure blocking for a new DOI registration', async () => {
        vi.mocked(axios.get).mockRejectedValueOnce(new Error('Configuration unavailable'));

        render(<EditorDataCiteConfirmationDialog {...defaultProps} />);

        expect(await screen.findByText('DataCite action unavailable')).toBeInTheDocument();
        expect(screen.getByTestId('confirm-editor-datacite-action')).toBeDisabled();
    });

    it('offers retry and override actions for transient ORCID warnings', async () => {
        const user = userEvent.setup();
        const onConfirm = vi.fn();
        const warning = {
            severity: 'warning' as const,
            reason: 'timeout' as const,
            role: 'creator' as const,
            position: 1,
            orcid: '0000-0002-1825-0097',
            displayName: 'Ada Lovelace',
        };

        render(<EditorDataCiteConfirmationDialog {...defaultProps} onConfirm={onConfirm} orcidWarnings={[warning]} />);

        await waitFor(() => expect(screen.getByTestId('editor-orcid-preflight-retry')).toBeEnabled());
        await user.click(screen.getByTestId('editor-orcid-preflight-retry'));
        await user.click(screen.getByTestId('editor-orcid-preflight-override'));

        expect(onConfirm).toHaveBeenNthCalledWith(1, { prefix: '10.83279', force: false, submittingAction: 'retry' });
        expect(onConfirm).toHaveBeenNthCalledWith(2, { prefix: '10.83279', force: true, submittingAction: 'override' });
    });
    it.each(['missing', 'draft'])('blocks ordinary updates for a remote %s even with a local DOI', async (state) => {
        vi.mocked(axios.get).mockImplementation((url) => Promise.resolve({ data: url.endsWith('/datacite-status')
            ? { state, mode: 'test', can_update: false, can_publish: true, publication: null }
            : { test: ['10.83279'], production: ['10.5880'], test_mode: true } }));
        render(<EditorDataCiteConfirmationDialog {...defaultProps} action="update" resourceId={42} doi="10.83279/preassigned" />);
        expect(await screen.findByText(/Metadata updates require a registered DOI/)).toBeVisible();
        expect(screen.getByTestId('confirm-editor-datacite-action')).toBeDisabled();
        expect(defaultProps.onConfirm).not.toHaveBeenCalled();
    });

    it('does not load remote state until an explicit action opens', () => {
        render(<EditorDataCiteConfirmationDialog {...defaultProps} open={false} resourceId={42} doi="10.83279/preassigned" />);
        expect(axios.get).not.toHaveBeenCalled();
    });

});
