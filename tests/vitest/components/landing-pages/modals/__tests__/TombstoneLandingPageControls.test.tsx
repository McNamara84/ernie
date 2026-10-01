import '@testing-library/jest-dom/vitest';

import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axios from 'axios';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { openLandingPagePreviewPlaceholder } from '@/components/landing-pages/landing-page-preview-window';
import TombstoneLandingPageControls, { type TombstoneState } from '@/components/landing-pages/modals/TombstoneLandingPageControls';

vi.mock('axios', () => ({
    isAxiosError: vi.fn(() => false),
    default: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn(), isAxiosError: vi.fn(() => false) },
}));
const previewWindow = vi.hoisted(() => ({ location: { href: '' }, close: vi.fn() }));
vi.mock('@/components/landing-pages/landing-page-preview-window', () => ({ openLandingPagePreviewPlaceholder: vi.fn(() => previewWindow) }));

const initial: TombstoneState = {
    can_manage: true,
    can_activate: true,
    is_tombstone: false,
    revision: 0,
    reason: null,
    statement: null,
    reasons: [
        { value: 'data_lost', label: 'Data lost' },
        { value: 'retracted', label: 'Resource retracted' },
    ],
    sync: null,
    restore: null,
};
const active: TombstoneState = {
    ...initial,
    is_tombstone: true,
    revision: 1,
    reason: 'data_lost',
    statement: 'The original files were permanently lost.',
    sync: { status: 'pending', attempts: 0, last_error: null },
    restore: { has_configuration: true, template: 'external', is_published: true, datacite_state: 'findable' },
};

function setup(state: TombstoneState = initial) {
    vi.mocked(axios.get).mockResolvedValue({ data: { tombstone: state } });
    const callbacks = { onSaved: vi.fn(), onDirtyChange: vi.fn(), onBusyChange: vi.fn() };
    render(<TombstoneLandingPageControls resourceId={42} revision={state.revision} {...callbacks} />);
    return callbacks;
}

beforeEach(() => {
    vi.clearAllMocks();
    sessionStorage.clear();
    previewWindow.location.href = '';
});

describe('Tombstone controls', () => {
    it('requires a public statement and confirmation before activation', async () => {
        const callbacks = setup();
        const activate = await screen.findByRole('button', { name: 'Activate tombstone page' });
        expect(activate).toBeDisabled();
        fireEvent.change(screen.getByLabelText('Public explanation'), { target: { value: 'The data were lost.' } });
        expect(activate).toBeDisabled();
        fireEvent.click(screen.getByRole('checkbox', { name: /I confirm that this resource is unavailable/ }));
        expect(activate).toBeEnabled();
        vi.mocked(axios.post).mockResolvedValue({ data: { tombstone: active, landing_page: { id: 9, is_tombstone: true } } });
        fireEvent.click(activate);
        await waitFor(() =>
            expect(axios.post).toHaveBeenCalledWith(
                '/resources/42/landing-page/tombstone',
                expect.objectContaining({ reason: 'data_lost', statement: 'The data were lost.', revision: 0, confirmed: true }),
            ),
        );
        await waitFor(() => expect(callbacks.onSaved).toHaveBeenCalledWith({ id: 9, is_tombstone: true }));
        expect(callbacks.onBusyChange).toHaveBeenLastCalledWith(false);
    });

    it('makes tombstone settings read-only for users without permission', async () => {
        setup({ ...active, can_manage: false });
        expect(await screen.findByLabelText('Public explanation')).toBeDisabled();
        expect(screen.getByRole('combobox', { name: 'Reason' })).toBeDisabled();
        expect(screen.queryByRole('button', { name: 'Restore landing page' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Retry DataCite sync' })).not.toBeInTheDocument();
    });

    it('retains unsaved explanation drafts for the same server revision', async () => {
        sessionStorage.setItem(
            'setup-landing-page-modal:tombstone:42:0',
            JSON.stringify({ reason: 'retracted', statement: 'Unsaved public explanation.' }),
        );
        const callbacks = setup();
        expect(await screen.findByLabelText('Public explanation')).toHaveValue('Unsaved public explanation.');
        await waitFor(() => expect(callbacks.onDirtyChange).toHaveBeenCalledWith(true));
    });

    it('ignores a draft from an older lifecycle revision', async () => {
        sessionStorage.setItem('setup-landing-page-modal:tombstone:42:0', JSON.stringify({ statement: 'Stale explanation.' }));
        setup(active);
        expect(await screen.findByLabelText('Public explanation')).toHaveValue(active.statement);
    });

    it('shows remote failures separately and retries the saved revision', async () => {
        setup({ ...active, sync: { status: 'failed', attempts: 5, last_error: 'DataCite request failed (HTTP 503).' } });
        vi.mocked(axios.post).mockResolvedValue({ data: { tombstone: active } });
        fireEvent.click(await screen.findByRole('button', { name: 'Retry DataCite sync' }));
        await waitFor(() =>
            expect(axios.post).toHaveBeenCalledWith('/resources/42/landing-page/tombstone/retry-sync', expect.objectContaining({ revision: 1 })),
        );
        expect(screen.getByText(/This resource is Dead/)).toBeInTheDocument();
    });

    it('restores only after confirmation and displays the prior template', async () => {
        setup(active);
        const restore = await screen.findByRole('button', { name: 'Restore landing page' });
        expect(restore).toBeDisabled();
        expect(screen.getByText(/previous external configuration/)).toBeInTheDocument();
        fireEvent.click(screen.getByRole('checkbox', { name: /I confirm that this resource is available again/ }));
        vi.mocked(axios.delete).mockResolvedValue({ data: { tombstone: initial, landing_page: { id: 9, is_tombstone: false } } });
        fireEvent.click(restore);
        await waitFor(() =>
            expect(axios.delete).toHaveBeenCalledWith('/resources/42/landing-page/tombstone', {
                data: expect.objectContaining({ revision: 1, confirmed: true }),
            }),
        );
    });

    it('reports save conflicts without claiming success or discarding the explanation', async () => {
        const callbacks = setup(active);
        fireEvent.change(await screen.findByLabelText('Public explanation'), { target: { value: 'A more detailed explanation.' } });
        vi.mocked(axios.patch).mockRejectedValue(new Error('Conflict'));
        fireEvent.click(screen.getByRole('button', { name: 'Save tombstone explanation' }));
        expect(await screen.findByRole('alert')).toHaveTextContent('could not be saved');
        expect(screen.getByLabelText('Public explanation')).toHaveValue('A more detailed explanation.');
        expect(callbacks.onSaved).not.toHaveBeenCalled();
    });

    it('opens an unsaved tombstone preview without activating the resource', async () => {
        setup();
        fireEvent.change(await screen.findByLabelText('Public explanation'), { target: { value: 'Preview explanation.' } });
        vi.mocked(axios.post).mockResolvedValue({ data: { preview_url: '/resources/42/landing-page/preview' } });
        fireEvent.click(screen.getByRole('button', { name: 'Preview tombstone page' }));
        await waitFor(() => expect(previewWindow.location.href).toBe('/resources/42/landing-page/preview'));
        expect(axios.post).toHaveBeenCalledWith(
            '/resources/42/landing-page/preview',
            expect.objectContaining({ is_tombstone: true, tombstone_statement: 'Preview explanation.' }),
        );
    });

    it('does not allow activation without an existing DOI', async () => {
        setup({ ...initial, can_activate: false });
        expect(await screen.findByRole('button', { name: 'Activate tombstone page' })).toBeDisabled();
        expect(screen.getByText('An existing resource DOI is required.')).toBeInTheDocument();
    });
});

describe('Tombstone recovery and polling', () => {
    it('keeps unsaved reason and explanation drafts when retrying the saved synchronization', async () => {
        const user = userEvent.setup();
        const callbacks = setup({ ...active, sync: { status: 'failed', attempts: 5, last_error: 'HTTP 503' } });
        await user.click(await screen.findByRole('combobox', { name: 'Reason' }));
        await user.click(screen.getByRole('option', { name: 'Resource retracted' }));
        fireEvent.change(screen.getByLabelText('Public explanation'), { target: { value: 'Unsaved correction.' } });
        const key = 'setup-landing-page-modal:tombstone:42:1';
        const draft = sessionStorage.getItem(key);
        vi.mocked(axios.post).mockResolvedValue({ data: { tombstone: active } });
        fireEvent.click(screen.getByRole('button', { name: 'Retry DataCite sync' }));
        await waitFor(() => expect(screen.getByRole('status')).toHaveTextContent('DataCite sync: pending.'));
        expect(screen.getByRole('combobox', { name: 'Reason' })).toHaveTextContent('Resource retracted');
        expect(screen.getByLabelText('Public explanation')).toHaveValue('Unsaved correction.');
        expect(sessionStorage.getItem(key)).toBe(draft);
        expect(callbacks.onDirtyChange).toHaveBeenLastCalledWith(true);
        expect(callbacks.onSaved).not.toHaveBeenCalled();
    });

    it('restores without publishing when that choice is unchecked', async () => {
        setup({ ...active, restore: { has_configuration: false, template: null, is_published: null, datacite_state: 'registered' } });
        const publish = await screen.findByRole('checkbox', { name: 'Publish the restored default landing page' });
        expect(publish).toBeChecked();
        fireEvent.click(publish);
        fireEvent.click(screen.getByRole('checkbox', { name: /I confirm that this resource is available again/ }));
        vi.mocked(axios.delete).mockResolvedValue({ data: { tombstone: initial } });
        fireEvent.click(screen.getByRole('button', { name: 'Restore landing page' }));
        await waitFor(() =>
            expect(axios.delete).toHaveBeenCalledWith('/resources/42/landing-page/tombstone', {
                data: expect.objectContaining({ revision: 1, confirmed: true, restore_published: false }),
            }),
        );
    });

    it('updates completed synchronization without overwriting an unsaved explanation', async () => {
        vi.useFakeTimers();
        try {
            setup(active);
            await act(async () => {
                await Promise.resolve();
            });
            fireEvent.change(screen.getByLabelText('Public explanation'), { target: { value: 'Unsaved correction.' } });
            vi.mocked(axios.get).mockResolvedValue({
                data: { tombstone: { ...active, sync: { status: 'succeeded', attempts: 1, last_error: null } } },
            });
            await act(async () => {
                await vi.advanceTimersByTimeAsync(5000);
            });
            expect(screen.getByRole('status')).toHaveTextContent('DataCite sync: completed.');
            expect(screen.getByLabelText('Public explanation')).toHaveValue('Unsaved correction.');
        } finally {
            vi.useRealTimers();
        }
    });

    it('ignores a draft with invalid field types', async () => {
        sessionStorage.setItem('setup-landing-page-modal:tombstone:42:1', JSON.stringify({ reason: 42, statement: {} }));
        setup(active);
        expect(await screen.findByLabelText('Public explanation')).toHaveValue(active.statement);
        expect(screen.getByRole('combobox', { name: 'Reason' })).toHaveTextContent('Data lost');
    });

    it('keeps a successful server change successful when browser storage is unavailable', async () => {
        const callbacks = setup(active);
        fireEvent.change(await screen.findByLabelText('Public explanation'), { target: { value: 'Saved correction.' } });
        vi.mocked(axios.patch).mockResolvedValue({
            data: { tombstone: { ...active, revision: 2, statement: 'Saved correction.' }, landing_page: { id: 9, tombstone_revision: 2 } },
        });
        const removeItem = vi.spyOn(Storage.prototype, 'removeItem').mockImplementation(() => {
            throw new Error('Storage is unavailable');
        });
        try {
            fireEvent.click(screen.getByRole('button', { name: 'Save tombstone explanation' }));
            await waitFor(() => expect(callbacks.onSaved).toHaveBeenCalledWith({ id: 9, tombstone_revision: 2 }));
            expect(screen.queryByRole('alert')).not.toBeInTheDocument();
        } finally {
            removeItem.mockRestore();
        }
    });

    it('explains a blocked popup without activating or requesting a preview', async () => {
        setup();
        fireEvent.change(await screen.findByLabelText('Public explanation'), { target: { value: 'Preview explanation.' } });
        vi.mocked(openLandingPagePreviewPlaceholder).mockReturnValueOnce(null);
        fireEvent.click(screen.getByRole('button', { name: 'Preview tombstone page' }));
        expect(await screen.findByRole('alert')).toHaveTextContent('Allow popups');
        expect(axios.post).not.toHaveBeenCalled();
    });
});
