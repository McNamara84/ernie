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

        expect(screen.getByText(/Are you sure you want to register this dataset at DataCite/i)).toBeInTheDocument();
        expect(onConfirm).not.toHaveBeenCalled();

        const confirmButton = await screen.findByTestId('confirm-editor-datacite-action');
        await waitFor(() => expect(confirmButton).toBeEnabled());
        await user.click(confirmButton);

        expect(onConfirm).toHaveBeenCalledWith('10.83279', false, 'submit');
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

        expect(onConfirm).toHaveBeenNthCalledWith(1, '10.83279', false, 'retry');
        expect(onConfirm).toHaveBeenNthCalledWith(2, '10.83279', true, 'override');
    });
});
