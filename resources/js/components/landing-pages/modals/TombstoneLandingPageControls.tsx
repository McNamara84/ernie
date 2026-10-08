import axios from 'axios';
import { ChevronDown } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { openLandingPagePreviewPlaceholder } from '@/components/landing-pages/landing-page-preview-window';
import { getLandingPageRequestErrorMessage } from '@/components/landing-pages/modals/landing-page-modal-helpers';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import type { LandingPageConfig } from '@/types/landing-page';

export interface TombstoneState {
    can_manage: boolean;
    is_tombstone: boolean;
    revision: number;
    reason: string | null;
    statement: string | null;
    reasons: Array<{ value: string; label: string }>;
    sync: { status: 'pending' | 'running' | 'succeeded' | 'failed' | 'superseded'; attempts: number; last_error: string | null } | null;
    restore: { has_configuration: boolean; template: string | null; is_published: boolean | null; datacite_state: string | null } | null;
}

export interface ActivationEligibility {
    status: 'eligible' | 'ineligible' | 'unavailable';
    reason: string | null;
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
    const [eligibility, setEligibility] = useState<ActivationEligibility | null>(null);
    const [open, setOpen] = useState(false);
    const [reload, setReload] = useState(0);
    const [reason, setReason] = useState('data_lost');
    const [statement, setStatement] = useState('');
    const [confirmed, setConfirmed] = useState(false);
    const [restoreConfirmed, setRestoreConfirmed] = useState(false);
    const [restorePublished, setRestorePublished] = useState(true);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const requestScope = useRef(0);
    const endpoint = `/resources/${resourceId}/landing-page/tombstone`;
    const storageKey = `setup-landing-page-modal:tombstone:${resourceId}:${state?.revision ?? revision}`;
    const dirty = state !== null && (statement !== (state.statement ?? '') || reason !== (state.reason ?? 'data_lost'));

    useEffect(() => {
        requestScope.current += 1;
        setState(null);
        setOpen(false);
        return () => {
            requestScope.current += 1;
            onBusyChange(false);
        };
    }, [resourceId, onBusyChange]);

    useEffect(() => {
        const controller = new AbortController();
        setEligibility(null);
        setError('');
        setConfirmed(false);
        setRestoreConfirmed(false);
        void axios
            .get<{ tombstone: TombstoneState; activation_eligibility: ActivationEligibility }>(endpoint, {
                signal: controller.signal,
                params: { include_eligibility: 1 },
            })
            .then(({ data }) => {
                if (controller.signal.aborted) return;
                setState(data.tombstone);
                setEligibility(data.activation_eligibility);
                setOpen(data.tombstone.is_tombstone);
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
                if (controller.signal.aborted) return;
                setEligibility({ status: 'unavailable', reason: 'verification_unavailable' });
                setError(getLandingPageRequestErrorMessage(requestError, 'Unable to verify DOI registration. Please retry.'));
            });
        return () => controller.abort();
    }, [endpoint, resourceId, revision, reload]);

    useEffect(() => () => onDirtyChange(false), [onDirtyChange]);

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
        const scope = requestScope.current;
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
            if (scope !== requestScope.current) return;
            setState(response.data.tombstone);
            if (action === 'activate') setOpen(true);
            if (action === 'restore') {
                setOpen(false);
                setEligibility(null);
                setReload((value) => value + 1);
            }
            if (action !== 'retry') {
                try {
                    sessionStorage.removeItem(storageKey);
                } catch {
                    /* The server result remains authoritative. */
                }
                setReason(response.data.tombstone.reason ?? 'data_lost');
                setStatement(response.data.tombstone.statement ?? '');
                setConfirmed(false);
                setRestoreConfirmed(false);
                if (response.data.landing_page) onSaved(response.data.landing_page);
            }
        } catch (requestError) {
            if (scope === requestScope.current)
                setError(
                    getLandingPageRequestErrorMessage(
                        requestError,
                        'The tombstone change could not be saved. Reload the modal if the page has changed.',
                    ),
                );
        } finally {
            if (scope === requestScope.current) {
                setBusy(false);
                onBusyChange(false);
            }
        }
    }

    async function preview() {
        const scope = requestScope.current;
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
            if (scope === requestScope.current) previewWindow.location.href = data.preview_url;
            else previewWindow.close();
        } catch (requestError) {
            previewWindow.close();
            if (scope === requestScope.current)
                setError(getLandingPageRequestErrorMessage(requestError, 'The tombstone preview could not be opened.'));
        }
    }

    const canActivate = eligibility?.status === 'eligible';
    const showSettings = state?.is_tombstone || canActivate;
    const feedback = (
        <>
            {error && (
                <p role="alert" className="text-sm text-destructive">
                    {error}
                </p>
            )}
            {(eligibility?.status === 'unavailable' || (!state && error)) && (
                <div className="space-y-2 text-sm">
                    {!error && <p role="status">DOI registration could not be verified. Please retry.</p>}
                    <Button type="button" variant="outline" onClick={() => setReload((value) => value + 1)}>
                        Retry registration check
                    </Button>
                </div>
            )}
            {state?.sync && (
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
        </>
    );

    if (!state || !showSettings) {
        if (!error && eligibility?.status !== 'unavailable' && !state?.sync) return null;
        return <div className="mt-6 space-y-3">{feedback}</div>;
    }

    return (
        <section aria-labelledby="tombstone-settings-heading" className="mt-6 space-y-3 rounded-lg border p-4">
            <Collapsible open={open} onOpenChange={setOpen}>
                <h3 id="tombstone-settings-heading" className="font-semibold">
                    <CollapsibleTrigger className="flex w-full items-center justify-between gap-2 rounded-sm text-left outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2">
                        Tombstone page
                        <ChevronDown aria-hidden="true" className={open ? 'size-4 rotate-180' : 'size-4'} />
                    </CollapsibleTrigger>
                </h3>
                {feedback}
                <CollapsibleContent className="space-y-3 pt-3">
                    <p className="text-sm text-muted-foreground">
                        {state.is_tombstone
                            ? 'This resource is Dead. Its tombstone page remains publicly accessible.'
                            : 'Use a tombstone when a resource with a registered DOI is permanently unavailable.'}
                    </p>
                    <fieldset disabled={busy || !state.can_manage} className="space-y-3">
                        <div className="space-y-1">
                            <Label htmlFor="tombstone-reason">Reason</Label>
                            <Select value={reason} onValueChange={setReason} disabled={busy || !state.can_manage}>
                                <SelectTrigger id="tombstone-reason" className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {state.reasons.map((option) => (
                                        <SelectItem key={option.value} value={option.value}>
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
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
                                            <div className="flex items-center gap-2">
                                                <Checkbox
                                                    id="tombstone-restore-published"
                                                    checked={restorePublished}
                                                    onCheckedChange={(checked) => setRestorePublished(checked === true)}
                                                    disabled={busy || !state.can_manage}
                                                />
                                                <Label htmlFor="tombstone-restore-published">Publish the restored default landing page</Label>
                                            </div>
                                        )}
                                        <div className="flex items-start gap-2">
                                            <Checkbox
                                                id="tombstone-restore-confirmed"
                                                className="mt-0.5"
                                                checked={restoreConfirmed}
                                                onCheckedChange={(checked) => setRestoreConfirmed(checked === true)}
                                                disabled={busy || !state.can_manage}
                                            />
                                            <Label htmlFor="tombstone-restore-confirmed" className="leading-normal">
                                                I confirm that this resource is available again and want to restore its landing page.
                                            </Label>
                                        </div>
                                        <Button type="button" disabled={!restoreConfirmed} onClick={() => void mutate('restore')}>
                                            Restore landing page
                                        </Button>
                                    </>
                                ) : (
                                    <>
                                        <div className="flex items-start gap-2">
                                            <Checkbox
                                                id="tombstone-activation-confirmed"
                                                className="mt-0.5"
                                                checked={confirmed}
                                                onCheckedChange={(checked) => setConfirmed(checked === true)}
                                                disabled={busy || !state.can_manage}
                                            />
                                            <Label htmlFor="tombstone-activation-confirmed" className="leading-normal">
                                                I confirm that this resource is unavailable. Its status will become Dead, data access will be
                                                disabled, it will be hidden from the portal, and DataCite will be updated.
                                            </Label>
                                        </div>
                                        <Button
                                            type="button"
                                            variant="destructive"
                                            disabled={!canActivate || !confirmed || !statement.trim()}
                                            onClick={() => void mutate('activate')}
                                        >
                                            Activate tombstone page
                                        </Button>
                                    </>
                                )}
                            </>
                        )}
                    </fieldset>
                </CollapsibleContent>
            </Collapsible>
        </section>
    );
}
