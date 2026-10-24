import userEvent from '@testing-library/user-event';
import { act, fireEvent, render, screen, waitFor, within } from '@tests/vitest/utils/render';
import axios from 'axios';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { CurationText, locationHref } from '@/components/curation-workflow/curation-text';
import CurationPanel, { CurationContent } from '@/components/curation-workflow/CurationPanel';
import type { CurationEvent, CurationPanelData } from '@/types/curation';

vi.mock('axios', () => ({ default: { get: vi.fn(), request: vi.fn(), isAxiosError: vi.fn(() => true) } }));

function panel(overrides: Partial<CurationPanelData> = {}): CurationPanelData {
    return {
        resource_id: 1,
        started: true,
        can_write: true,
        state: {
            revision: 3,
            lead_curator_id: 1,
            waiting_for: null,
            waiting_text: null,
            waiting_since: null,
            needs_help_text: null,
            deadline: null,
            deadline_reason: null,
        },
        items: [
            {
                key: 'data_received',
                stage: 'Intake',
                label: 'Data received',
                help: 'Confirm receipt',
                automatic: false,
                allows_na: true,
                state: 'open',
                reason: null,
                revision: 2,
                changed_at: null,
                changed_by: null,
            },
            {
                key: 'doi_assigned',
                stage: 'Publication',
                label: 'DOI assigned',
                help: 'Uses the saved DOI',
                automatic: true,
                allows_na: false,
                state: 'done',
                reason: null,
                revision: 0,
                changed_at: null,
                changed_by: null,
            },
        ],
        curators: [{ id: 1, name: 'Current Curator', active: true, eligible: true }],
        locations: [],
        latest_note: null,
        summary: { progress: 1, manual_open: 8, open: true, waiting_days: null, waiting_overdue: false, deadline_overdue: false, overdue: false },
        ...overrides,
    };
}

let data: CurationPanelData;
let events: CurationEvent[];
beforeEach(() => {
    vi.clearAllMocks();
    data = panel();
    events = [];
    vi.mocked(axios.get).mockImplementation(async (url) => ({
        data: String(url).includes('/events')
            ? { data: events, next_cursor: null }
            : String(url).includes('/curation/users')
              ? [{ id: 2, name: 'Next Curator', email: 'next@example.org' }]
              : data,
    }));
    vi.mocked(axios.request).mockImplementation(async () => ({ data }));
});

describe('Curation panel', () => {
    it('loads only when opened and stays disabled before the first save', async () => {
        const { rerender } = render(<CurationPanel resourceId={null} />);
        expect(screen.getByRole('button', { name: 'Curation' })).toBeDisabled();
        expect(axios.get).not.toHaveBeenCalled();
        rerender(<CurationPanel resourceId={1} />);
        await userEvent.click(screen.getByRole('button', { name: 'Curation' }));
        expect(await screen.findByRole('checkbox', { name: 'Data received' })).toBeEnabled();
        expect(screen.getByRole('checkbox', { name: /DOI assigned/ })).toBeDisabled();
    });

    it('renders a fully read-only view for beginners', async () => {
        data = panel({ can_write: false });
        render(<CurationContent resourceId={1} />);
        expect(await screen.findByText('Read-only access to curation.')).toBeVisible();
        expect(screen.getByRole('checkbox', { name: 'Data received' })).toBeDisabled();
        for (const name of ['Not applicable', 'Edit working state', 'Add data location', 'Add note', 'Hand over']) {
            expect(screen.queryByRole('button', { name })).not.toBeInTheDocument();
        }
        expect(axios.request).not.toHaveBeenCalled();
    });

    it('writes the displayed revision and keeps conflicts actionable', async () => {
        vi.mocked(axios.request).mockRejectedValueOnce({ response: { status: 409 } });
        render(<CurationContent resourceId={1} />);
        await userEvent.click(await screen.findByRole('checkbox', { name: 'Data received' }));
        expect(axios.request).toHaveBeenCalledWith({
            url: '/resources/1/curation/checklist/data_received',
            method: 'patch',
            data: { state: 'done', revision: 2 },
        });
        expect(await screen.findByRole('alert')).toHaveTextContent('changed in another session');
        expect(screen.getByRole('checkbox', { name: 'Data received' })).not.toBeChecked();
        await userEvent.click(screen.getByRole('button', { name: 'Reload curation' }));
        await waitFor(() => expect(screen.queryByRole('alert')).not.toBeInTheDocument());
    });

    it('requires a reason for not-applicable items and preserves it after a save failure', async () => {
        vi.mocked(axios.request).mockRejectedValueOnce(new Error('Network unavailable'));
        render(<CurationContent resourceId={1} />);
        await userEvent.click(await screen.findByRole('button', { name: 'Not applicable' }));
        expect(screen.getByRole('button', { name: 'Save reason' })).toBeDisabled();
        await userEvent.type(screen.getByLabelText('Reason for Data received'), 'Code is already on GitHub');
        await userEvent.click(screen.getByRole('button', { name: 'Save reason' }));
        await screen.findByRole('alert');
        expect(screen.getByLabelText('Reason for Data received')).toHaveValue('Code is already on GitHub');
    });

    it('does not overwrite a mutation with a focus refresh', async () => {
        let resolve!: (value: { data: CurationPanelData }) => void;
        vi.mocked(axios.request).mockImplementationOnce(
            () =>
                new Promise((done) => {
                    resolve = done;
                }),
        );
        render(<CurationContent resourceId={1} />);
        await userEvent.click(await screen.findByRole('checkbox', { name: 'Data received' }));
        const calls = vi.mocked(axios.get).mock.calls.length;
        fireEvent(window, new Event('focus'));
        expect(axios.get).toHaveBeenCalledTimes(calls);
        const saved = panel();
        saved.items[0].state = 'done';
        data = saved;
        await act(async () => resolve({ data: saved }));
        expect(screen.getByRole('checkbox', { name: 'Data received' })).toBeChecked();
    });

    it('keeps a curation save when metadata autosave finishes concurrently', async () => {
        let resolve!: (value: { data: CurationPanelData }) => void;
        vi.mocked(axios.request).mockImplementationOnce(
            () =>
                new Promise((done) => {
                    resolve = done;
                }),
        );
        const { rerender } = render(<CurationContent resourceId={1} />);
        await userEvent.click(await screen.findByRole('checkbox', { name: 'Data received' }));
        rerender(<CurationContent resourceId={1} refreshKey />);
        data = panel();
        data.items[0].state = 'done';
        await act(async () => resolve({ data }));
        await waitFor(() => expect(screen.getByRole('checkbox', { name: 'Data received' })).toBeChecked());
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });

    it('preserves a failed save message when an autosave refresh is pending', async () => {
        let reject!: (error: Error) => void;
        vi.mocked(axios.request).mockImplementationOnce(
            () =>
                new Promise((_resolve, fail) => {
                    reject = fail;
                }),
        );
        const { rerender } = render(<CurationContent resourceId={1} />);
        await userEvent.click(await screen.findByRole('checkbox', { name: 'Data received' }));
        rerender(<CurationContent resourceId={1} refreshKey />);
        await act(async () => reject(new Error('Network unavailable')));
        expect(await screen.findByRole('alert')).toHaveTextContent('Could not save curation. Your input is still available.');
        expect(screen.getByRole('checkbox', { name: 'Data received' })).not.toBeChecked();
    });

    it('discards an old resource response after changing the resource', async () => {
        let resolve!: (value: { data: CurationPanelData }) => void;
        vi.mocked(axios.get).mockImplementationOnce(
            () =>
                new Promise((done) => {
                    resolve = done;
                }),
        );
        const { rerender } = render(<CurationPanel resourceId={1} />);
        await userEvent.click(screen.getByRole('button', { name: 'Curation' }));
        data = panel({ resource_id: 2, curators: [{ id: 1, name: 'New resource curator' }] });
        rerender(<CurationPanel resourceId={2} />);
        expect(await screen.findByText(/Lead: New resource curator/)).toBeVisible();
        await act(async () => resolve({ data: panel() }));
        expect(screen.getByText(/Lead: New resource curator/)).toBeVisible();
    });

    it('requires help details and saves waiting, deadline and help together', async () => {
        render(<CurationContent resourceId={1} />);
        await userEvent.click(await screen.findByRole('button', { name: 'Edit working state' }));
        await userEvent.click(screen.getByRole('checkbox', { name: 'Needs help' }));
        expect(screen.getByRole('button', { name: 'Save working state' })).toBeDisabled();
        await userEvent.type(screen.getByLabelText('Help needed (required)'), 'Check coordinates');
        await userEvent.click(screen.getByRole('combobox', { name: 'Waiting for reply from' }));
        await userEvent.click(screen.getByRole('option', { name: 'Colleague' }));
        await userEvent.click(screen.getByRole('button', { name: 'Save working state' }));
        expect(axios.request).toHaveBeenCalledWith(
            expect.objectContaining({
                data: expect.objectContaining({ revision: 3, waiting_for: 'colleague', needs_help_text: 'Check coordinates' }),
            }),
        );
    });

    it('shows note editing only when authorized and never renders note HTML', async () => {
        events = [
            {
                id: 3,
                type: 'note',
                actor_name: 'Colleague',
                body: '<img src=x onerror=alert(1)> https://example.org',
                created_at: '2026-10-21T12:00:00Z',
                edited_at: null,
                deleted_at: null,
                can_edit: false,
                revision: 0,
                payload: {},
            },
        ];
        render(<CurationContent resourceId={1} />);
        const history = await screen.findByRole('region', { name: 'Notes and activity' });
        expect(await within(history).findByText(/<img src=x/)).toBeVisible();
        expect(within(history).queryByRole('img')).not.toBeInTheDocument();
        expect(within(history).queryByRole('button', { name: 'Edit note' })).not.toBeInTheDocument();
        expect(within(history).getByRole('link', { name: 'https://example.org' })).toHaveAttribute('rel', 'noopener noreferrer');
    });

    it('clears waiting and help through the shared controls', async () => {
        data.state!.waiting_for = 'submitters';
        data.state!.needs_help_text = 'Check coordinates';
        render(<CurationContent resourceId={1} />);
        await userEvent.click(await screen.findByRole('button', { name: 'Edit working state' }));
        await userEvent.click(screen.getByRole('combobox', { name: 'Waiting for reply from' }));
        await userEvent.click(screen.getByRole('option', { name: 'Not waiting' }));
        await userEvent.click(screen.getByRole('checkbox', { name: 'Needs help' }));
        await userEvent.click(screen.getByRole('button', { name: 'Save working state' }));
        expect(axios.request).toHaveBeenCalledWith(
            expect.objectContaining({
                data: expect.objectContaining({ waiting_for: null, needs_help_text: null, revision: 3 }),
            }),
        );
    });

    it('selects lead and additional curators and can clear an invalid selection', async () => {
        render(<CurationContent resourceId={1} />);
        await userEvent.click(await screen.findByRole('button', { name: 'Assign curators' }));
        await userEvent.click(screen.getByRole('combobox', { name: 'New lead curator' }));
        await userEvent.click(screen.getByRole('option', { name: 'Select lead' }));
        expect(screen.getByRole('button', { name: 'Save responsibility' })).toBeDisabled();
        await userEvent.click(screen.getByRole('combobox', { name: 'New lead curator' }));
        await userEvent.click(screen.getByRole('option', { name: /Next Curator/ }));
        const member = screen.getByRole('checkbox', { name: 'Next Curator' });
        await userEvent.click(member);
        expect(member).toBeChecked();
        await userEvent.click(member);
        expect(member).not.toBeChecked();
        await userEvent.click(member);
        await userEvent.click(screen.getByRole('button', { name: 'Save responsibility' }));
        expect(axios.request).toHaveBeenCalledWith({
            url: '/resources/1/curation/curators',
            method: 'put',
            data: { lead: 2, members: [1, 2], note: '', revision: 3 },
        });
    });

    it('saves the selected location type and filters history with the shared controls', async () => {
        render(<CurationContent resourceId={1} />);
        await userEvent.click(await screen.findByRole('button', { name: 'Add data location' }));
        await userEvent.click(screen.getByRole('combobox', { name: 'Location type' }));
        await userEvent.click(screen.getByRole('option', { name: 'sftp' }));
        await userEvent.type(screen.getByLabelText('Path or link'), 'sftp://example.test/data');
        await userEvent.click(screen.getByRole('button', { name: 'Save location' }));
        expect(axios.request).toHaveBeenCalledWith({
            url: '/resources/1/curation/locations',
            method: 'post',
            data: { type: 'sftp', location: 'sftp://example.test/data', revision: 0 },
        });
        await userEvent.click(screen.getByRole('checkbox', { name: 'Notes only' }));
        await waitFor(() => expect(axios.get).toHaveBeenLastCalledWith('/resources/1/curation/events', { params: { notes_only: 1 } }));
        await userEvent.click(screen.getByRole('checkbox', { name: 'Notes only' }));
        await waitFor(() => expect(axios.get).toHaveBeenLastCalledWith('/resources/1/curation/events', { params: { notes_only: 0 } }));
    });

    it('selects mention recipients by id and adds a note separately from metadata', async () => {
        render(<CurationContent resourceId={1} />);
        await userEvent.click(await screen.findByRole('button', { name: '@ Mention a colleague' }));
        await userEvent.click(await screen.findByRole('checkbox', { name: /Next Curator/ }));
        await userEvent.click(screen.getByRole('button', { name: 'Add note' }));
        expect(axios.request).toHaveBeenCalledWith({
            url: '/resources/1/curation/notes',
            method: 'post',
            data: { body: '@Next Curator', mentions: [2], revision: 0 },
        });
    });
});

it.each(['javascript:alert(1)', 'data:text/html,bad', 'file:///server/private', String.raw`\\server\data`])(
    'does not activate unsafe or local location links: %s',
    (location) => {
        expect(locationHref(location)).toBeUndefined();
    },
);

it('only linkifies web URLs in plain text', () => {
    render(<CurationText text="<script>bad</script> javascript:alert(1) https://example.org" />);
    expect(screen.getAllByRole('link')).toHaveLength(1);
    expect(screen.getByText(/<script>/)).toBeVisible();
});
