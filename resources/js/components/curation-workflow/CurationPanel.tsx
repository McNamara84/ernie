import axios from 'axios';
import { useCallback, useEffect, useRef, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import { Textarea } from '@/components/ui/textarea';
import type { ChecklistItem, CurationEvent, CurationPanelData, CurationUser, DataLocation } from '@/types/curation';

import { CurationText, dateLabel, locationHref } from './curation-text';
import { CurationUserSearch } from './curation-user-search';

type Save = (path: string, data: Record<string, unknown>, method?: 'post' | 'patch' | 'put' | 'delete') => Promise<boolean>;

function ChecklistRow({ item, writable, save, busy }: { item: ChecklistItem; writable: boolean; save: Save; busy: boolean }) {
    const [reason, setReason] = useState('');
    const [showReason, setShowReason] = useState(false);
    return (
        <li className="space-y-2 rounded-lg border p-3">
            <div className="flex items-start gap-2">
                <Checkbox
                    id={`curation-checklist-${item.key}`}
                    className="mt-1"
                    checked={item.state !== 'open'}
                    disabled={!writable || item.automatic || busy}
                    onCheckedChange={(checked) =>
                        void save(`/checklist/${item.key}`, { state: checked === true ? 'done' : 'open', revision: item.revision })
                    }
                />
                <Label htmlFor={`curation-checklist-${item.key}`} className="block leading-6">
                    {item.label}
                    {item.automatic && <span className="ml-2 text-xs text-muted-foreground">ERNIE · automatic</span>}
                </Label>
            </div>
            <p className="text-xs text-muted-foreground">{item.help}</p>
            {item.state === 'not_applicable' && (
                <p className="text-sm">
                    Not applicable: <CurationText text={item.reason ?? ''} />
                </p>
            )}
            {item.changed_at && (
                <p className="text-xs text-muted-foreground">
                    {item.observed ? 'Observed at start' : 'Changed'} {dateLabel(item.changed_at)}
                    {item.changed_by_name && ` · ${item.changed_by_name}`}
                </p>
            )}
            {writable && item.allows_na && (
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    disabled={busy}
                    onClick={() => {
                        setReason(item.reason ?? '');
                        setShowReason(!showReason);
                    }}
                >
                    Not applicable
                </Button>
            )}
            {showReason && writable && (
                <div className="space-y-2">
                    <Label htmlFor={`reason-${item.key}`}>Reason for {item.label}</Label>
                    <Input id={`reason-${item.key}`} value={reason} maxLength={2000} onChange={(e) => setReason(e.target.value)} />
                    <Button
                        type="button"
                        disabled={busy || !reason.trim()}
                        onClick={async () => {
                            if (await save(`/checklist/${item.key}`, { state: 'not_applicable', reason, revision: item.revision }))
                                setShowReason(false);
                        }}
                    >
                        Save reason
                    </Button>
                </div>
            )}
        </li>
    );
}

function Responsibility({ data, save, busy }: { data: CurationPanelData; save: Save; busy: boolean }) {
    const [editing, setEditing] = useState<'assign' | 'handover' | null>(null);
    const [users, setUsers] = useState<CurationUser[]>([]);
    const [lead, setLead] = useState('');
    const [members, setMembers] = useState<number[]>([]);
    const [note, setNote] = useState('');
    const [error, setError] = useState('');
    const [revision, setRevision] = useState(0);
    const begin = async (mode: 'assign' | 'handover') => {
        setError('');
        try {
            setUsers((await axios.get<CurationUser[]>('/curation/users')).data);
        } catch {
            setError('Could not load eligible curators. Try again.');
            return;
        }
        setLead(String(data.state?.lead_curator_id ?? ''));
        setMembers(data.curators.map((user) => user.id));
        setRevision(data.state?.revision ?? 0);
        setNote('');
        setEditing(mode);
    };
    return (
        <section className="space-y-3" aria-label="Responsible curators">
            <h3 className="font-semibold">Responsible curators</h3>
            <p>Lead: {data.curators.find((user) => user.id === data.state?.lead_curator_id)?.name ?? 'Unassigned'}</p>
            {data.curators.map((user) => (
                <p key={user.id} className="text-sm">
                    {user.name}
                    {user.eligible === false ? ' — unavailable for new assignments' : ''}
                </p>
            ))}
            {data.can_write && data.started && (
                <div className="flex gap-2">
                    <Button type="button" variant="outline" onClick={() => void begin('assign')}>
                        Assign curators
                    </Button>
                    <Button type="button" variant="outline" onClick={() => void begin('handover')}>
                        Hand over
                    </Button>
                </div>
            )}
            {error && <p role="alert">{error}</p>}
            {editing && data.can_write && (
                <div className="space-y-3 rounded-lg border p-3">
                    <CurationUserSearch onUsers={setUsers} />
                    <Label htmlFor="curation-lead">New lead curator</Label>
                    <Select value={lead || 'none'} onValueChange={(value) => setLead(value === 'none' ? '' : value)}>
                        <SelectTrigger id="curation-lead">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="none">Select lead</SelectItem>
                            {users.map((user) => (
                                <SelectItem value={String(user.id)} key={user.id}>
                                    {user.name} ({user.email})
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    {editing === 'assign' && (
                        <fieldset>
                            <legend>Further curators</legend>
                            {users.map((user) => (
                                <div key={user.id} className="flex items-center gap-2 py-1">
                                    <Checkbox
                                        id={`curation-member-${user.id}`}
                                        checked={members.includes(user.id)}
                                        onCheckedChange={(checked) =>
                                            setMembers((current) =>
                                                checked === true ? [...current, user.id] : current.filter((id) => id !== user.id),
                                            )
                                        }
                                    />
                                    <Label htmlFor={`curation-member-${user.id}`}>{user.name}</Label>
                                </div>
                            ))}
                        </fieldset>
                    )}
                    <Label htmlFor="handover-note">Handover note (optional)</Label>
                    <Textarea id="handover-note" maxLength={2000} value={note} onChange={(e) => setNote(e.target.value)} />
                    <div className="flex gap-2">
                        <Button
                            type="button"
                            disabled={busy || !lead}
                            onClick={async () => {
                                if (
                                    await save(
                                        editing === 'handover' ? '/handover' : '/curators',
                                        { lead: Number(lead), members, note, revision },
                                        editing === 'handover' ? 'post' : 'put',
                                    )
                                )
                                    setEditing(null);
                            }}
                        >
                            Save responsibility
                        </Button>
                        <Button type="button" variant="ghost" onClick={() => setEditing(null)}>
                            Cancel
                        </Button>
                    </div>
                </div>
            )}
        </section>
    );
}

function WorkingState({ data, save, busy }: { data: CurationPanelData; save: Save; busy: boolean }) {
    const [draft, setDraft] = useState<{
        revision: number;
        waiting_for: string;
        waiting_text: string;
        help: boolean;
        needs_help_text: string;
        deadline: string;
        deadline_reason: string;
    } | null>(null);
    const state = data.state;
    return (
        <section className="space-y-3" aria-label="Working state">
            <h3 className="font-semibold">Working state</h3>
            {state?.waiting_for && (
                <p>
                    Waiting for {state.waiting_for} · {data.summary.waiting_days} days
                    <br />
                    <CurationText text={state.waiting_text ?? ''} />
                </p>
            )}
            {state?.needs_help_text && (
                <p>
                    Needs help: <CurationText text={state.needs_help_text} />
                </p>
            )}
            {state?.deadline && (
                <p>
                    Deadline: {state.deadline} {state.deadline_reason}
                </p>
            )}
            {data.summary.overdue && <p className="font-medium text-destructive">Overdue</p>}
            {data.can_write && state && (
                <Button
                    type="button"
                    variant="outline"
                    onClick={() =>
                        setDraft({
                            revision: state.revision,
                            waiting_for: state.waiting_for ?? '',
                            waiting_text: state.waiting_text ?? '',
                            help: Boolean(state.needs_help_text),
                            needs_help_text: state.needs_help_text ?? '',
                            deadline: state.deadline ?? '',
                            deadline_reason: state.deadline_reason ?? '',
                        })
                    }
                >
                    Edit working state
                </Button>
            )}
            {draft && data.can_write && (
                <div className="space-y-3 rounded-lg border p-3">
                    <Label htmlFor="waiting-for">Waiting for reply from</Label>
                    <Select
                        value={draft.waiting_for || 'none'}
                        onValueChange={(value) => setDraft({ ...draft, waiting_for: value === 'none' ? '' : value })}
                    >
                        <SelectTrigger id="waiting-for">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="none">Not waiting</SelectItem>
                            <SelectItem value="submitters">Submitters</SelectItem>
                            <SelectItem value="colleague">Colleague</SelectItem>
                            <SelectItem value="other">Other</SelectItem>
                        </SelectContent>
                    </Select>
                    <Label htmlFor="waiting-text">Waiting details</Label>
                    <Textarea
                        id="waiting-text"
                        value={draft.waiting_text}
                        maxLength={2000}
                        onChange={(e) => setDraft({ ...draft, waiting_text: e.target.value })}
                    />
                    <div className="flex items-center gap-2">
                        <Checkbox
                            id="curation-needs-help"
                            checked={draft.help}
                            onCheckedChange={(checked) => setDraft({ ...draft, help: checked === true })}
                        />
                        <Label htmlFor="curation-needs-help">Needs help</Label>
                    </div>
                    {draft.help && (
                        <>
                            <Label htmlFor="help-text">Help needed (required)</Label>
                            <Textarea
                                id="help-text"
                                value={draft.needs_help_text}
                                maxLength={2000}
                                onChange={(e) => setDraft({ ...draft, needs_help_text: e.target.value })}
                            />
                        </>
                    )}
                    <Label htmlFor="curation-deadline">Deadline</Label>
                    <Input
                        id="curation-deadline"
                        type="date"
                        value={draft.deadline}
                        onChange={(e) => setDraft({ ...draft, deadline: e.target.value })}
                    />
                    <Label htmlFor="deadline-reason">Deadline reason</Label>
                    <Input
                        id="deadline-reason"
                        value={draft.deadline_reason}
                        maxLength={2000}
                        onChange={(e) => setDraft({ ...draft, deadline_reason: e.target.value })}
                    />
                    <div className="flex gap-2">
                        <Button
                            type="button"
                            disabled={busy || (draft.help && !draft.needs_help_text.trim())}
                            onClick={async () => {
                                if (
                                    await save('/state', {
                                        revision: draft.revision,
                                        waiting_for: draft.waiting_for || null,
                                        waiting_text: draft.waiting_text || null,
                                        needs_help_text: draft.help ? draft.needs_help_text : null,
                                        deadline: draft.deadline || null,
                                        deadline_reason: draft.deadline_reason || null,
                                    })
                                )
                                    setDraft(null);
                            }}
                        >
                            Save working state
                        </Button>
                        <Button type="button" variant="ghost" onClick={() => setDraft(null)}>
                            Cancel
                        </Button>
                    </div>
                </div>
            )}
        </section>
    );
}

function Locations({ data, save, busy }: { data: CurationPanelData; save: Save; busy: boolean }) {
    const [draft, setDraft] = useState<DataLocation | null>(null);
    const [copyMessage, setCopyMessage] = useState('');
    return (
        <section className="space-y-3" aria-label="Data locations">
            <h3 className="font-semibold">Data locations</h3>
            {data.locations.map((location) => (
                <div key={location.id} className="space-y-1 rounded border p-2">
                    <p className="text-xs">{location.type.replaceAll('_', ' ')}</p>
                    {locationHref(location.location) ? (
                        <a href={locationHref(location.location)} target="_blank" rel="noopener noreferrer" className="break-all underline">
                            {location.location}
                        </a>
                    ) : (
                        <p className="break-all">{location.location}</p>
                    )}
                    <div className="flex gap-2">
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={async () => {
                                try {
                                    await navigator.clipboard.writeText(location.location);
                                    setCopyMessage('Location copied.');
                                } catch {
                                    setCopyMessage('Copy failed. Select the path to copy it manually.');
                                }
                            }}
                        >
                            Copy path
                        </Button>
                        {data.can_write && data.started && (
                            <>
                                <Button type="button" size="sm" variant="ghost" onClick={() => setDraft(location)}>
                                    Edit location
                                </Button>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    disabled={busy}
                                    onClick={() => void save(`/locations/${location.id}`, { revision: location.revision }, 'delete')}
                                >
                                    Remove location
                                </Button>
                            </>
                        )}
                    </div>
                </div>
            ))}
            <p role="status" className="text-xs">
                {copyMessage}
            </p>
            {data.can_write && data.started && (
                <Button type="button" variant="outline" onClick={() => setDraft({ id: 0, type: 'network_drive', location: '', revision: 0 })}>
                    Add data location
                </Button>
            )}
            {draft && data.can_write && (
                <div className="space-y-3 rounded border p-3">
                    <Label htmlFor="location-type">Location type</Label>
                    <Select value={draft.type} onValueChange={(value) => setDraft({ ...draft, type: value })}>
                        <SelectTrigger id="location-type">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {['network_drive', 'sftp', 'nextcloud', 'data_server', 'external_server', 'other'].map((type) => (
                                <SelectItem key={type} value={type}>
                                    {type.replaceAll('_', ' ')}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Label htmlFor="location-path">Path or link</Label>
                    <Input
                        id="location-path"
                        value={draft.location}
                        maxLength={2000}
                        onChange={(e) => setDraft({ ...draft, location: e.target.value })}
                    />
                    <Button
                        type="button"
                        disabled={busy || !draft.location.trim()}
                        onClick={async () => {
                            if (
                                await save(
                                    draft.id ? `/locations/${draft.id}` : '/locations',
                                    { type: draft.type, location: draft.location, revision: draft.revision },
                                    draft.id ? 'patch' : 'post',
                                )
                            )
                                setDraft(null);
                        }}
                    >
                        Save location
                    </Button>
                    <Button type="button" variant="ghost" onClick={() => setDraft(null)}>
                        Cancel
                    </Button>
                </div>
            )}
        </section>
    );
}

function History({ base, writable, save, busy, version }: { base: string; writable: boolean; save: Save; busy: boolean; version: number }) {
    const [events, setEvents] = useState<CurationEvent[]>([]);
    const [cursor, setCursor] = useState<number | null>(null);
    const [notesOnly, setNotesOnly] = useState(false);
    const [body, setBody] = useState('');
    const [editing, setEditing] = useState<CurationEvent | null>(null);
    const [users, setUsers] = useState<CurationUser[]>([]);
    const [mentions, setMentions] = useState<number[]>([]);
    const [showMentions, setShowMentions] = useState(false);
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(false);
    const generation = useRef(0);
    const load = useCallback(
        async (older: number | null = null) => {
            const current = ++generation.current;
            setLoading(true);
            setError('');
            try {
                const response = await axios.get<{ data: CurationEvent[]; next_cursor: number | null }>(`${base}/events`, {
                    params: { notes_only: notesOnly ? 1 : 0, ...(older ? { cursor: older } : {}) },
                });
                if (current !== generation.current) return;
                setEvents((previous) =>
                    older
                        ? [...previous, ...response.data.data.filter((event) => !previous.some((saved) => saved.id === event.id))]
                        : response.data.data,
                );
                setCursor(response.data.next_cursor);
            } catch {
                if (current === generation.current) setError('Could not load history.');
            } finally {
                if (current === generation.current) setLoading(false);
            }
        },
        [base, notesOnly],
    );
    useEffect(() => {
        void load();
        const sequence = generation;
        return () => {
            sequence.current++;
        };
    }, [load, version]);
    return (
        <section className="space-y-3" aria-label="Notes and activity">
            <h3 className="font-semibold">Notes and activity</h3>
            {writable && (
                <div className="space-y-2">
                    <Label htmlFor="curation-note">{editing ? 'Edit note' : 'New note'}</Label>
                    <Textarea id="curation-note" value={body} maxLength={10000} onChange={(e) => setBody(e.target.value)} />
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={async () => {
                            try {
                                setUsers((await axios.get<CurationUser[]>('/curation/users?mentions=1')).data);
                                setShowMentions(true);
                            } catch {
                                setError('Could not load colleagues.');
                            }
                        }}
                    >
                        @ Mention a colleague
                    </Button>
                    {showMentions && (
                        <fieldset>
                            <CurationUserSearch mentions onUsers={setUsers} />
                            <legend>Notify mentioned colleagues</legend>
                            {users.map((user) => (
                                <div className="flex items-center gap-2 text-sm" key={user.id}>
                                    <Checkbox
                                        id={`curation-mention-${user.id}`}
                                        checked={mentions.includes(user.id)}
                                        onCheckedChange={(checked) => {
                                            setMentions((current) =>
                                                checked === true ? [...current, user.id] : current.filter((id) => id !== user.id),
                                            );
                                            if (checked === true) setBody((text) => `${text}${text ? ' ' : ''}@${user.name}`);
                                        }}
                                    />
                                    <Label htmlFor={`curation-mention-${user.id}`}>
                                        {user.name} ({user.email})
                                    </Label>
                                </div>
                            ))}
                        </fieldset>
                    )}
                    <Button
                        type="button"
                        disabled={busy || !body.trim()}
                        onClick={async () => {
                            if (
                                await save(
                                    editing ? `/notes/${editing.id}` : '/notes',
                                    { body, mentions, revision: editing?.revision ?? 0 },
                                    editing ? 'patch' : 'post',
                                )
                            ) {
                                setBody('');
                                setEditing(null);
                                setMentions([]);
                                setUsers([]);
                                setShowMentions(false);
                            }
                        }}
                    >
                        {editing ? 'Save note' : 'Add note'}
                    </Button>
                    {editing && (
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => {
                                setEditing(null);
                                setBody('');
                            }}
                        >
                            Cancel edit
                        </Button>
                    )}
                </div>
            )}
            <div className="flex items-center gap-2">
                <Checkbox id="curation-notes-only" checked={notesOnly} onCheckedChange={(checked) => setNotesOnly(checked === true)} />
                <Label htmlFor="curation-notes-only">Notes only</Label>
            </div>
            {error && (
                <div role="alert">
                    {error}
                    <Button type="button" variant="ghost" onClick={() => void load()}>
                        Retry history
                    </Button>
                </div>
            )}
            <ol className="space-y-3">
                {events.map((event) => (
                    <li key={event.id} className="rounded-lg border p-3">
                        <p className="text-sm font-medium">
                            {event.actor_name} · {event.type.replaceAll('.', ' ')}
                            {event.edited_at && ' · edited'}
                        </p>
                        <time className="text-xs text-muted-foreground" dateTime={event.created_at}>
                            {dateLabel(event.created_at)}
                        </time>
                        {event.deleted_at ? (
                            <p>Note deleted</p>
                        ) : (
                            event.body && (
                                <p>
                                    <CurationText text={event.body} />
                                </p>
                            )
                        )}
                        {event.description && (
                            <p className="mt-2 text-sm">
                                <CurationText text={event.description} />
                            </p>
                        )}
                        {writable && event.can_edit && (
                            <div className="flex gap-2">
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    onClick={() => {
                                        setEditing(event);
                                        setBody(event.body ?? '');
                                        setMentions([]);
                                    }}
                                >
                                    Edit note
                                </Button>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    disabled={busy}
                                    onClick={() => void save(`/notes/${event.id}`, { revision: event.revision }, 'delete')}
                                >
                                    Delete note
                                </Button>
                            </div>
                        )}
                    </li>
                ))}
            </ol>
            {loading && <p role="status">Loading history…</p>}
            {cursor && (
                <Button type="button" variant="outline" disabled={loading} onClick={() => void load(cursor)}>
                    Load older entries
                </Button>
            )}
        </section>
    );
}

export function CurationContent({ resourceId, refreshKey = false }: { resourceId: number; refreshKey?: boolean }) {
    const [data, setData] = useState<CurationPanelData | null>(null);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [version, setVersion] = useState(0);
    const generation = useRef(0);
    const resourceGeneration = useRef(0);
    const refreshPending = useRef(false);
    const saving = useRef(false);
    const base = `/resources/${resourceId}/curation`;
    const load = useCallback(async () => {
        if (saving.current) {
            refreshPending.current = true;
            return;
        }
        const current = ++generation.current;
        try {
            const response = await axios.get<CurationPanelData>(base);
            if (current === generation.current) {
                setData(response.data);
                setError('');
            }
        } catch {
            if (current === generation.current) setError('Could not load curation. Please retry.');
        }
    }, [base]);
    useEffect(() => {
        const sequence = resourceGeneration;
        return () => {
            sequence.current++;
        };
    }, [base]);
    useEffect(() => {
        void load();
        window.addEventListener('focus', load);
        const sequence = generation;
        return () => {
            sequence.current++;
            window.removeEventListener('focus', load);
        };
    }, [load, refreshKey]);
    const save: Save = async (path, payload, method = 'patch') => {
        if (saving.current) return false;
        saving.current = true;
        setBusy(true);
        setError('');
        ++generation.current;
        const current = resourceGeneration.current;
        let saved = false;
        try {
            const response = await axios.request<CurationPanelData>({ url: base + path, method, data: payload });
            if (current !== resourceGeneration.current) return false;
            setData(response.data);
            setVersion((v) => v + 1);
            saved = true;
            return true;
        } catch (failure) {
            if (current !== resourceGeneration.current) return false;
            const message = axios.isAxiosError<{ message?: string; errors?: Record<string, string[]> }>(failure)
                ? failure.response?.status === 409
                    ? 'This entry changed in another session. Reload curation, then reopen the form and apply your changes.'
                    : Object.values(failure.response?.data.errors ?? {})
                          .flat()
                          .join(' ') || failure.response?.data.message
                : null;
            setError(message || 'Could not save curation. Your input is still available.');
            return false;
        } finally {
            saving.current = false;
            setBusy(false);
            const shouldRefresh = refreshPending.current && saved && current === resourceGeneration.current;
            refreshPending.current = false;
            if (shouldRefresh) {
                void load();
            }
        }
    };
    return (
        <div className="space-y-6" aria-busy={busy}>
            {error && (
                <div role="alert" className="rounded border border-destructive p-3">
                    {error}
                    <Button type="button" variant="ghost" disabled={busy} onClick={() => void load()}>
                        Reload curation
                    </Button>
                </div>
            )}
            {!data && !error && <p role="status">Loading curation…</p>}
            {data && (
                <>
                    <p className="font-medium">
                        {data.summary.progress} of 13 done ·{' '}
                        {data.summary.open ? 'Open curation' : data.started ? 'Completed curation' : 'Not started'}
                    </p>
                    {!data.can_write && <p className="rounded bg-muted p-3">Read-only access to curation.</p>}
                    {!data.started && (
                        <div>
                            <p>Current state preview. Manual steps have not been tracked for this resource.</p>
                            {data.can_write && (
                                <Button type="button" disabled={busy} onClick={() => void save('/start', {}, 'post')}>
                                    Start curation
                                </Button>
                            )}
                        </div>
                    )}
                    <Responsibility data={data} save={save} busy={busy} />
                    {['Intake', 'Curation', 'Review', 'Publication'].map((stage) => (
                        <section key={stage} aria-label={`${stage} checklist`}>
                            <h3 className="mb-2 font-semibold">{stage}</h3>
                            <ul className="space-y-2">
                                {data.items
                                    .filter((item) => item.stage === stage)
                                    .map((item) => (
                                        <ChecklistRow key={item.key} item={item} writable={data.can_write && data.started} save={save} busy={busy} />
                                    ))}
                            </ul>
                        </section>
                    ))}
                    <WorkingState data={data} save={save} busy={busy} />
                    <Locations data={data} save={save} busy={busy} />
                    {data.started && <History base={base} writable={data.can_write} save={save} busy={busy} version={version} />}
                </>
            )}
        </div>
    );
}

export default function CurationPanel({ resourceId, refreshKey = false }: { resourceId: number | null; refreshKey?: boolean }) {
    const [open, setOpen] = useState(false);
    return (
        <Sheet open={open} onOpenChange={setOpen}>
            <SheetTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    disabled={!resourceId}
                    title={!resourceId ? 'Save the resource to start curation' : undefined}
                >
                    Curation
                </Button>
            </SheetTrigger>
            <SheetContent className="w-full overflow-y-auto sm:max-w-xl">
                <SheetHeader>
                    <SheetTitle>Curation</SheetTitle>
                    <SheetDescription>Internal checklist, responsibility and handover history.</SheetDescription>
                </SheetHeader>
                <div className="px-4 pb-8">
                    {open && resourceId && <CurationContent key={resourceId} resourceId={resourceId} refreshKey={refreshKey} />}
                </div>
            </SheetContent>
        </Sheet>
    );
}
