import axios from 'axios';
import { useEffect, useState } from 'react';

import { openLandingPagePreviewPlaceholder } from '@/components/landing-pages/landing-page-preview-window';
import { getLandingPageRequestErrorMessage } from '@/components/landing-pages/modals/landing-page-modal-helpers';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import type { LandingPageConfig } from '@/types/landing-page';

export interface TombstoneState {
    can_manage: boolean;
    can_activate: boolean;
    is_tombstone: boolean;
    revision: number;
    reason: string | null;
    statement: string | null;
    reasons: Array<{ value: string; label: string }>;
    sync: { status: 'pending' | 'running' | 'succeeded' | 'failed' | 'superseded'; attempts: number; last_error: string | null } | null;
    restore: { has_configuration: boolean; template: string | null; is_published: boolean | null; datacite_state: string | null } | null;
}

interface Props {
    resourceId: number;
    revision: number;
    onSaved: (page: LandingPageConfig) => void;
    onDirtyChange: (dirty: boolean) => void;
    onBusyChange: (busy: boolean) => void;
}

export default function TombstoneLandingPageControls({ resourceId, revision, onSaved, onDirtyChange, onBusyChange }: Props) {
    const [state, setState] = useState<TombstoneState | null>(null);
    const [reason, setReason] = useState('data_lost');
    const [statement, setStatement] = useState('');
    const [confirmed, setConfirmed] = useState(false);
    const [restoreConfirmed, setRestoreConfirmed] = useState(false);
    const [restorePublished, setRestorePublished] = useState(true);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const endpoint = `/resources/${resourceId}/landing-page/tombstone`;
    const storageKey = `setup-landing-page-modal:tombstone:${resourceId}:${state?.revision ?? revision}`;
    const dirty = state !== null && (statement !== (state.statement ?? '') || reason !== (state.reason ?? 'data_lost'));

    useEffect(() => {
        const controller = new AbortController();
        setState(null);
        setError('');
        setConfirmed(false);
        setRestoreConfirmed(false);
        void axios
            .get<{ tombstone: TombstoneState }>(endpoint, { signal: controller.signal })
            .then(({ data }) => {
                if (controller.signal.aborted) return;
                setState(data.tombstone);
                let draft: { reason?: string; statement?: string } | null = null;
                try {
                    draft = JSON.parse(
                        sessionStorage.getItem(`setup-landing-page-modal:tombstone:${resourceId}:${data.tombstone.revision}`) ?? 'null',
                    );
                } catch {
                    /* An unavailable or stale draft does not affect the saved configuration. */
                }
                setReason(typeof draft?.reason === 'string' ? draft.reason : (data.tombstone.reason ?? 'data_lost'));
                setStatement(typeof draft?.statement === 'string' ? draft.statement : (data.tombstone.statement ?? ''));
            })
            .catch((requestError: unknown) => {
                if (!controller.signal.aborted)
                    setError(getLandingPageRequestErrorMessage(requestError, 'Unable to load tombstone settings. Reopen this modal to retry.'));
            });
        return () => controller.abort();
    }, [endpoint, resourceId, revision]);

    useEffect(() => {
        onDirtyChange(dirty);
        if (!state) return;
        try {
            if (dirty) sessionStorage.setItem(storageKey, JSON.stringify({ reason, statement }));
            else sessionStorage.removeItem(storageKey);
        } catch {
            /* Editing remains available without browser storage. */
        }
    }, [dirty, onDirtyChange, reason, state, statement, storageKey]);

    const syncStatus = state?.sync?.status;
    useEffect(() => {
        if (!syncStatus || !['pending', 'running'].includes(syncStatus)) return;
        const controller = new AbortController();
        const timer = setInterval(() => {
            void axios
                .get<{ tombstone: TombstoneState }>(endpoint, { signal: controller.signal })
                .then(({ data }) => {
                    if (!controller.signal.aborted)
                        setState((current) => (current?.revision === data.tombstone.revision ? { ...current, sync: data.tombstone.sync } : current));
                })
                .catch(() => {
                    /* A transient polling failure leaves the last confirmed status visible. */
                });
        }, 5000);
        return () => {
            clearInterval(timer);
            controller.abort();
        };
    }, [endpoint, syncStatus]);

    async function mutate(action: 'activate' | 'update' | 'restore' | 'retry') {
        if (!state) return;
        setBusy(true);
        onBusyChange(true);
        setError('');
        try {
            const payload = {
                revision: state.revision,
                reason,
                statement,
                confirmed: action === 'restore' ? restoreConfirmed : confirmed,
                restore_published: restorePublished,
            };
            const response =
                action === 'restore'
                    ? await axios.delete<{ tombstone: TombstoneState; landing_page: LandingPageConfig }>(endpoint, { data: payload })
                    : action === 'update'
                      ? await axios.patch<{ tombstone: TombstoneState; landing_page: LandingPageConfig }>(endpoint, payload)
                      : await axios.post<{ tombstone: TombstoneState; landing_page?: LandingPageConfig }>(
                            action === 'retry' ? `${endpoint}/retry-sync` : endpoint,
                            payload,
                        );
            try {
                sessionStorage.removeItem(storageKey);
            } catch {
                /* The server result remains authoritative. */
            }
            setState(response.data.tombstone);
            setReason(response.data.tombstone.reason ?? 'data_lost');
            setStatement(response.data.tombstone.statement ?? '');
            setConfirmed(false);
            setRestoreConfirmed(false);
            if (response.data.landing_page) onSaved(response.data.landing_page);
        } catch (requestError) {
            setError(
                getLandingPageRequestErrorMessage(requestError, 'The tombstone change could not be saved. Reload the modal if the page has changed.'),
            );
        } finally {
            setBusy(false);
            onBusyChange(false);
        }
    }

    async function preview() {
        const previewWindow = openLandingPagePreviewPlaceholder();
        if (!previewWindow) {
            setError('Allow popups to open the tombstone preview.');
            return;
        }
        try {
            const { data } = await axios.post<{ preview_url: string }>(`/resources/${resourceId}/landing-page/preview`, {
                template: 'default_gfz',
                is_tombstone: true,
                tombstone_reason: reason,
                tombstone_statement: statement,
            });
            previewWindow.location.href = data.preview_url;
        } catch (requestError) {
            previewWindow.close();
            setError(getLandingPageRequestErrorMessage(requestError, 'The tombstone preview could not be opened.'));
        }
    }

    return (
        <section aria-labelledby="tombstone-settings-heading" className="mb-6 space-y-3 rounded-lg border p-4">
            <h3 id="tombstone-settings-heading" className="font-semibold">
                Tombstone page
            </h3>
            {error && (
                <p role="alert" className="text-sm text-destructive">
                    {error}
                </p>
            )}
            {!state && !error && <p role="status">Loading tombstone settings...</p>}
            {state && (
                <>
                    <p className="text-sm text-muted-foreground">
                        {state.is_tombstone
                            ? 'This resource is Dead. Its tombstone page remains publicly accessible.'
                            : 'Use a tombstone when a resource with a registered DOI is permanently unavailable.'}
                    </p>
                    {state.sync && (
                        <div role="status" className="text-sm">
                            DataCite sync: {state.sync.status === 'succeeded' ? 'completed' : state.sync.status}.
                            {state.sync.last_error && <p>{state.sync.last_error} The saved landing page remains active.</p>}
                            {state.can_manage && ['pending', 'failed'].includes(state.sync.status) && (
                                <Button type="button" variant="outline" disabled={busy} onClick={() => void mutate('retry')}>
                                    Retry DataCite sync
                                </Button>
                            )}
                        </div>
                    )}
                    <fieldset disabled={busy || !state.can_manage} className="space-y-3">
                        <div className="space-y-1">
                            <Label htmlFor="tombstone-reason">Reason</Label>
                            <select
                                id="tombstone-reason"
                                value={reason}
                                onChange={(event) => setReason(event.target.value)}
                                className="w-full rounded-md border bg-background p-2 text-sm"
                            >
                                {state.reasons.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="tombstone-statement">Public explanation</Label>
                            <Textarea
                                id="tombstone-statement"
                                value={statement}
                                maxLength={5000}
                                onChange={(event) => setStatement(event.target.value)}
                                aria-describedby="tombstone-statement-help"
                            />
                            <p id="tombstone-statement-help" className="text-xs text-muted-foreground">
                                Required. This explanation will appear on the public landing page.
                            </p>
                        </div>
                        {state.can_manage && (
                            <>
                                <Button type="button" variant="outline" disabled={!statement.trim()} onClick={() => void preview()}>
                                    Preview tombstone page
                                </Button>
                                {state.is_tombstone ? (
                                    <>
                                        <Button type="button" disabled={!dirty || !statement.trim()} onClick={() => void mutate('update')}>
                                            Save tombstone explanation
                                        </Button>
                                        <p className="text-sm">
                                            Restore{' '}
                                            {state.restore?.has_configuration
                                                ? `the previous ${state.restore.template} configuration (${state.restore.is_published ? 'published' : 'draft'}).`
                                                : 'a normal default landing page.'}{' '}
                                            The original DataCite state will be restored.
                                        </p>
                                        {!state.restore?.has_configuration && (
                                            <label className="flex items-center gap-2 text-sm">
                                                <input
                                                    type="checkbox"
                                                    checked={restorePublished}
                                                    onChange={(event) => setRestorePublished(event.target.checked)}
                                                />
                                                Publish the restored default landing page
                                            </label>
                                        )}
                                        <label className="flex items-start gap-2 text-sm">
                                            <input
                                                type="checkbox"
                                                checked={restoreConfirmed}
                                                onChange={(event) => setRestoreConfirmed(event.target.checked)}
                                            />
                                            I confirm that this resource is available again and want to restore its landing page.
                                        </label>
                                        <Button type="button" disabled={!restoreConfirmed} onClick={() => void mutate('restore')}>
                                            Restore landing page
                                        </Button>
                                    </>
                                ) : (
                                    <>
                                        <label className="flex items-start gap-2 text-sm">
                                            <input type="checkbox" checked={confirmed} onChange={(event) => setConfirmed(event.target.checked)} />I
                                            confirm that this resource is unavailable. Its status will become Dead, data access will be disabled, it
                                            will be hidden from the portal, and DataCite will be updated.
                                        </label>
                                        <Button
                                            type="button"
                                            variant="destructive"
                                            disabled={!state.can_activate || !confirmed || !statement.trim()}
                                            onClick={() => void mutate('activate')}
                                        >
                                            Activate tombstone page
                                        </Button>
                                        {!state.can_activate && <p className="text-sm">An existing resource DOI is required.</p>}
                                    </>
                                )}
                            </>
                        )}
                    </fieldset>
                </>
            )}
        </section>
    );
}
