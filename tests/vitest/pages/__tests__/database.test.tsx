import '@testing-library/jest-dom/vitest';

import { act, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const axiosGetMock = vi.hoisted(() => vi.fn());
const axiosPostMock = vi.hoisted(() => vi.fn());
const toastMock = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title?: string }) => {
        if (title) document.title = title;

        return null;
    },
}));

vi.mock('axios', () => ({
    default: {
        get: axiosGetMock,
        post: axiosPostMock,
    },
    isAxiosError: (error: unknown) => Boolean(error && typeof error === 'object' && 'isAxiosError' in error),
}));

vi.mock('sonner', () => ({ toast: toastMock }));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <main data-testid="app-layout">{children}</main>,
}));

import DatabasePage from '@/pages/database';

type DumpStatus = 'pending' | 'running' | 'completed' | 'failed' | 'expired';

type ExportPayload = {
    id: string;
    targetKey: string;
    targetLabel: string;
    connectionName: string;
    databaseName: string;
    status: DumpStatus;
    filename: string | null;
    sizeBytes: number | null;
    sha256: string | null;
    serverVersion: string | null;
    dumpClient: string | null;
    errorMessage: string | null;
    requestedAt: string | null;
    startedAt: string | null;
    finishedAt: string | null;
    expiresAt: string | null;
    downloadCount: number;
    lastDownloadedAt: string | null;
    downloadUrl: string | null;
};

type TargetPayload = {
    key: string;
    label: string;
    description: string;
    connection: string;
    database: string | null;
    legacy: boolean;
    requiresLegacySslProbe: boolean;
    serverVersionHint: string | null;
    latestExport: ExportPayload | null;
};

function makeExport(overrides: Partial<ExportPayload> = {}): ExportPayload {
    return {
        id: 'dump-1',
        targetKey: 'ernie',
        targetLabel: 'ERNIE',
        connectionName: 'mysql',
        databaseName: 'ernie',
        status: 'completed',
        filename: 'ernie.sql.gz',
        sizeBytes: 1536,
        sha256: 'abc123',
        serverVersion: 'MySQL 9.7.0',
        dumpClient: 'mysqldump',
        errorMessage: null,
        requestedAt: '2026-07-13T10:00:00.000Z',
        startedAt: '2026-07-13T10:00:01.000Z',
        finishedAt: '2026-07-13T10:00:03.000Z',
        expiresAt: '2026-07-14T10:00:03.000Z',
        downloadCount: 0,
        lastDownloadedAt: null,
        downloadUrl: '/database/dumps/dump-1/download',
        ...overrides,
    };
}

function makeTarget(overrides: Partial<TargetPayload> = {}): TargetPayload {
    return {
        key: 'ernie',
        label: 'ERNIE',
        description: 'Laravel application database.',
        connection: 'mysql',
        database: 'ernie',
        legacy: false,
        requiresLegacySslProbe: false,
        serverVersionHint: 'MySQL 9.7.0',
        latestExport: null,
        ...overrides,
    };
}

function renderDatabasePage(targets: TargetPayload[]) {
    return render(<DatabasePage targets={targets} />);
}

describe('DatabasePage', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.useRealTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('renders configured database targets and the latest completed dump', () => {
        renderDatabasePage([
            makeTarget({ latestExport: makeExport() }),
            makeTarget({
                key: 'igsn',
                label: 'IGSN legacy',
                description: 'Legacy IGSN database.',
                connection: 'igsn_legacy',
                database: 'igsn',
                legacy: true,
                requiresLegacySslProbe: true,
                serverVersionHint: 'MySQL 5.6.36',
            }),
            makeTarget({
                key: 'missing',
                label: 'Missing database',
                database: null,
            }),
        ]);

        expect(screen.getByRole('heading', { name: 'Database Dumps' })).toBeInTheDocument();
        expect(screen.getByText('Admin exports for ERNIE and legacy metadata databases.')).toBeInTheDocument();
        expect(screen.getByText('ernie.sql.gz')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /download ernie database dump/i })).toHaveAttribute('href', '/database/dumps/dump-1/download');
        expect(screen.getByText('IGSN legacy')).toBeInTheDocument();
        expect(screen.getByText('Legacy SSL check required before production use.')).toBeInTheDocument();

        const missingRow = screen.getByRole('row', { name: /missing database/i });
        expect(within(missingRow).getByRole('button', { name: /create dump/i })).toBeDisabled();
    });

    it('starts a dump and replaces the target latest export from the response', async () => {
        const user = userEvent.setup();
        const queuedExport = makeExport({
            id: 'dump-2',
            status: 'pending',
            filename: 'ernie-queued.sql.gz',
            downloadUrl: null,
            sizeBytes: null,
        });
        axiosPostMock.mockResolvedValueOnce({ data: { export: queuedExport } });

        renderDatabasePage([makeTarget()]);

        await user.click(screen.getByRole('button', { name: /create dump/i }));

        expect(axiosPostMock).toHaveBeenCalledWith('/database/ernie/dumps');
        expect(toastMock.success).toHaveBeenCalledWith('Database dump queued');
        expect(await screen.findByText('ernie-queued.sql.gz')).toBeInTheDocument();
        expect(screen.getByText('pending')).toBeInTheDocument();
    });

    it('polls active exports and updates the row when a dump completes', async () => {
        vi.useFakeTimers();
        const completedExport = makeExport({
            id: 'dump-active',
            status: 'completed',
            filename: 'ernie-complete.sql.gz',
            downloadUrl: '/database/dumps/dump-active/download',
        });
        axiosGetMock.mockResolvedValueOnce({ data: { export: completedExport } });

        renderDatabasePage([
            makeTarget({
                latestExport: makeExport({
                    id: 'dump-active',
                    status: 'running',
                    filename: 'ernie-running.sql.gz',
                    downloadUrl: null,
                }),
            }),
        ]);

        expect(screen.getByText('Updating')).toBeInTheDocument();

        await act(async () => {
            await vi.advanceTimersByTimeAsync(3000);
        });

        expect(axiosGetMock).toHaveBeenCalledWith('/database/dumps/dump-active/status');
        expect(screen.getByText('ernie-complete.sql.gz')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /download ernie database dump/i })).toHaveAttribute('href', '/database/dumps/dump-active/download');
    });

    it('shows backend error messages when starting a dump fails', async () => {
        const user = userEvent.setup();
        axiosPostMock.mockRejectedValueOnce({
            isAxiosError: true,
            response: { data: { message: 'Only one database dump can run at a time.' } },
        });

        renderDatabasePage([makeTarget()]);

        await user.click(screen.getByRole('button', { name: /create dump/i }));

        expect(toastMock.error).toHaveBeenCalledWith('Only one database dump can run at a time.');
    });
});
