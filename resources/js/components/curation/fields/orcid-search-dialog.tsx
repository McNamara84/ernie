import { ExternalLink, Search } from 'lucide-react';
import { type KeyboardEvent, useEffect, useId, useRef, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { type OrcidSearchResult, OrcidService } from '@/services/orcid';

interface OrcidSearchDialogProps {
    onSelect: (result: OrcidSearchResult) => void;
    triggerClassName?: string;
}

type SearchState =
    | { kind: 'idle' | 'loading' }
    | { kind: 'error'; message: string; retryable: boolean }
    | { kind: 'results'; results: OrcidSearchResult[]; total: number };

function searchMessage(state: SearchState, length: number): string {
    switch (state.kind) {
        case 'idle':
            if (length < 2) return 'Enter at least two characters to search.';
            if (length > 200) return 'Use 200 characters or fewer.';
            return 'Press Enter or select Search.';
        case 'loading':
            return 'Searching ORCID…';
        case 'error':
            return state.message;
        case 'results':
            if (!state.results.length) return 'No ORCID records found. Try a different name or institution.';
            if (state.results.length < state.total) return `Showing ${state.results.length} of ${state.total} results`;
            return `${state.total} ${state.total === 1 ? 'result' : 'results'} found`;
    }
}

export function OrcidSearchDialog({ onSelect, triggerClassName }: OrcidSearchDialogProps) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [state, setState] = useState<SearchState>({ kind: 'idle' });
    const activeRequest = useRef<AbortController | null>(null);
    const input = useRef<HTMLInputElement>(null);
    const list = useRef<HTMLUListElement>(null);
    const inputId = useId();
    const queryLength = Array.from(query.trim()).length;
    const canSearch = queryLength >= 2 && queryLength <= 200;

    useEffect(() => () => activeRequest.current?.abort(), []);

    function changeQuery(value: string) {
        activeRequest.current?.abort();
        activeRequest.current = null;
        setQuery(value);
        setState({ kind: 'idle' });
    }

    function changeOpen(value: boolean) {
        if (!value) changeQuery('');
        setOpen(value);
    }

    async function search() {
        if (!canSearch || activeRequest.current) return;
        const request = new AbortController();
        activeRequest.current = request;
        input.current?.focus();
        setState({ kind: 'loading' });
        try {
            const response = await OrcidService.searchOrcid(query.trim(), 20, { mode: 'person', signal: request.signal });
            if (request.signal.aborted) return;
            if (response.success && response.data) {
                setState({ kind: 'results', ...response.data });
            } else {
                setState({ kind: 'error', message: response.error || 'Could not search ORCID.', retryable: response.status !== 422 });
            }
        } catch {
            if (!request.signal.aborted) setState({ kind: 'error', message: 'Could not reach ORCID.', retryable: true });
        } finally {
            if (activeRequest.current === request) activeRequest.current = null;
        }
    }

    function focusResult(index: number) {
        list.current?.querySelectorAll('button')[index]?.focus();
    }

    function navigateResults(event: KeyboardEvent<HTMLButtonElement>, index: number) {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            focusResult(index + 1);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            if (index === 0) input.current?.focus();
            else focusResult(index - 1);
        }
    }

    return (
        <Dialog open={open} onOpenChange={changeOpen}>
            <DialogTrigger asChild>
                <Button type="button" variant="ghost" size="icon" className={triggerClassName} aria-label="Search for ORCID">
                    <Search className="h-3 w-3" />
                </Button>
            </DialogTrigger>
            <DialogContent className="flex max-h-[80vh] flex-col sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Search for ORCID</DialogTitle>
                    <DialogDescription>
                        Enter a name as First Last or Last, First. To narrow your search, add an institution after a second comma.
                    </DialogDescription>
                </DialogHeader>
                <div role="search" className="flex items-end gap-2">
                    <div className="min-w-0 flex-1">
                        <label htmlFor={inputId} className="mb-1 block text-sm font-medium">
                            Person name
                        </label>
                        <Input
                            ref={input}
                            id={inputId}
                            type="search"
                            placeholder="e.g. Taylor, Alex, GFZ"
                            value={query}
                            onChange={(event) => changeQuery(event.target.value)}
                            onKeyDown={(event) => {
                                if (event.nativeEvent.isComposing) return;
                                if (event.key === 'Enter') {
                                    event.preventDefault();
                                    if (!event.repeat) void search();
                                } else if (event.key === 'ArrowDown' && list.current) {
                                    event.preventDefault();
                                    focusResult(0);
                                }
                            }}
                        />
                    </div>
                    <Button type="button" disabled={!canSearch || state.kind === 'loading'} onClick={() => void search()}>
                        Search
                    </Button>
                </div>
                <div role="status" aria-atomic="true" className="flex items-center gap-2 text-sm text-muted-foreground">
                    {state.kind === 'loading' && <Spinner size="sm" aria-hidden="true" />}
                    {searchMessage(state, queryLength)}
                </div>
                {state.kind === 'error' && state.retryable && (
                    <Button type="button" variant="outline" className="self-start" onClick={() => void search()}>
                        Try again
                    </Button>
                )}
                {state.kind === 'results' && state.results.length > 0 && (
                    <ul ref={list} aria-label="ORCID search results" className="min-h-0 divide-y overflow-y-auto">
                        {state.results.map((result, index) => (
                            <li key={result.orcid} className="flex items-center gap-1 p-1">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    className="h-auto min-w-0 flex-1 flex-col items-start gap-1 p-2 text-left text-base font-normal wrap-anywhere whitespace-normal"
                                    onKeyDown={(event) => navigateResults(event, index)}
                                    onClick={() => {
                                        changeOpen(false);
                                        onSelect(result);
                                    }}
                                >
                                    <span className="font-medium">
                                        {[result.lastName, result.firstName].filter(Boolean).join(', ') || result.creditName || 'Name unavailable'}
                                    </span>
                                    <code className="text-xs">{result.orcid}</code>
                                    {result.institutions.length > 0 && (
                                        <span className="text-xs text-muted-foreground">{result.institutions.join(' • ')}</span>
                                    )}
                                </Button>
                                {/* An explicit tab stop keeps the profile link reachable with Tab in WebKit. */}
                                <Button asChild variant="ghost" size="icon">
                                    <a
                                        href={`https://orcid.org/${result.orcid}`}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        tabIndex={0}
                                        aria-label={`View ${result.orcid} on ORCID.org`}
                                    >
                                        <ExternalLink />
                                    </a>
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}
            </DialogContent>
        </Dialog>
    );
}
