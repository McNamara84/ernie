import axios from 'axios';
import { Copy } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';

interface PreviewItem {
    id: number;
    identifier: string;
    status: string;
    operation: string;
    error_message: string | null;
}

interface PreviewPage {
    items: PreviewItem[];
    pagination: { last_page: number };
}

const registrationMessage = 'Test successfull! When test Mode is off, this JSON-String would be send to DataCite for the Registration of this IGSN:';

function JsonBody({ json }: { json: string }) {
    const tokens = json.split(/("(?:\\.|[^"\\])*":?|-?\b\d+(?:\.\d+)?(?:[eE][+-]?\d+)?\b|\b(?:true|false|null)\b)/g);
    return (
        <pre tabIndex={0} aria-label="DataCite JSON request" className="max-h-[45vh] overflow-auto rounded-md bg-muted p-3 text-sm">
            <code>
                {tokens.map((token, index) => {
                    if (index % 2 === 0) return token;
                    const color = token.endsWith('":')
                        ? 'text-purple-800 dark:text-purple-300'
                        : token.startsWith('"')
                          ? 'text-emerald-800 dark:text-emerald-300'
                          : /^[\d-]/.test(token)
                            ? 'text-blue-800 dark:text-blue-300'
                            : 'text-amber-800 dark:text-amber-300';
                    return (
                        <span key={index} className={color}>
                            {token}
                        </span>
                    );
                })}
            </code>
        </pre>
    );
}

export function IgsnRegistrationPreview({ runId }: { runId: string }) {
    const [page, setPage] = useState(1);
    const [items, setItems] = useState<PreviewItem[]>([]);
    const [lastPage, setLastPage] = useState(1);
    const [selectedId, setSelectedId] = useState<number>();
    const [json, setJson] = useState('');
    const [error, setError] = useState('');
    const [retry, setRetry] = useState(0);
    const selected = items.find((item) => item.id === selectedId);
    const path = `/igsns/batch-register/${runId}/items`;

    useEffect(() => {
        const request = new AbortController();
        setItems([]);
        setJson('');
        setError('');
        void axios
            .get<PreviewPage>(path, { params: { page }, signal: request.signal })
            .then(({ data }) => {
                if (request.signal.aborted) return;
                setItems(data.items);
                setLastPage(data.pagination.last_page);
                setSelectedId((data.items.find((item) => item.status === 'preview_ready') ?? data.items[0])?.id);
            })
            .catch(() => {
                if (!request.signal.aborted) setError('The simulation results could not be loaded.');
            });
        return () => request.abort();
    }, [page, path, retry]);

    useEffect(() => {
        setJson('');
        if (selected?.status !== 'preview_ready') return;
        const request = new AbortController();
        void axios
            .get<{ payload: object }>(`${path}/${selected.id}/preview`, { signal: request.signal })
            .then(({ data }) => {
                if (!request.signal.aborted) setJson(JSON.stringify(data.payload, null, 2));
            })
            .catch(() => {
                if (!request.signal.aborted) setError('The JSON preview could not be loaded.');
            });
        return () => request.abort();
    }, [path, selected]);

    return (
        <section className="min-w-0 space-y-3" aria-label="Simulation results">
            {items.length > 0 && (
                <label className="block space-y-1 text-sm font-medium">
                    <span>IGSN</span>
                    <select
                        className="block w-full rounded-md border bg-background p-2"
                        value={selectedId ?? ''}
                        onChange={(event) => {
                            const nextId = Number(event.target.value);
                            if (nextId === selectedId) return;
                            setJson('');
                            setError('');
                            setSelectedId(nextId);
                        }}
                    >
                        {items.map((item) => (
                            <option key={item.id} value={item.id}>
                                {item.identifier}
                                {item.status === 'preview_ready' ? '' : ` (${item.status})`}
                            </option>
                        ))}
                    </select>
                </label>
            )}
            {lastPage > 1 && (
                <div className="flex items-center gap-3 text-sm">
                    <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>
                        Previous
                    </Button>
                    <span>
                        Page {page} of {lastPage}
                    </span>
                    <Button variant="outline" size="sm" disabled={page >= lastPage} onClick={() => setPage(page + 1)}>
                        Next
                    </Button>
                </div>
            )}
            {error && (
                <div role="alert">
                    {error}{' '}
                    <Button variant="outline" size="sm" onClick={() => setRetry(retry + 1)}>
                        Retry
                    </Button>
                </div>
            )}
            {selected?.error_message && <p role="alert">{selected.error_message}</p>}
            {json && (
                <>
                    <p>
                        {selected?.operation === 'update'
                            ? 'Test successful! This JSON would be sent to DataCite to update the IGSN metadata:'
                            : registrationMessage}
                    </p>
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={async () => {
                            try {
                                await navigator.clipboard.writeText(json);
                                toast.success('JSON copied.');
                            } catch {
                                toast.error('Copy failed. Select and copy the JSON below.');
                            }
                        }}
                    >
                        <Copy />
                        Copy JSON
                    </Button>
                    <JsonBody json={json} />
                </>
            )}
            {!error && (!selected || (selected.status === 'preview_ready' && !json)) && <p role="status">Loading preview…</p>}
        </section>
    );
}
