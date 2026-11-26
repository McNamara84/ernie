import axios from 'axios';
import { useEffect, useState } from 'react';

import { CitationLabelText } from '@/components/citations/citation-label-text';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { getSelectAllState } from '@/lib/select-all';
import type { CitationLabelPart } from '@/types/landing-page';

interface Candidate {
    id: number;
    resource_id: number;
    resource: string;
    doi: string;
    work_type: string;
    old: string;
    text: string;
    parts: CitationLabelPart[];
    token: string;
}

const skipLabels = {
    other_type: 'Other work types',
    changed_text: 'Stored text differs from the previous formatter',
    insufficient_metadata: 'Insufficient metadata',
    lookup_failed: 'DOI lookup failed',
};
type SkipCounts = Record<keyof typeof skipLabels, number>;
interface Preview {
    candidates: Candidate[];
    skipped: SkipCounts;
    next_cursor: number | null;
}
const statusLabels = {
    applied: 'Applied',
    already_applied: 'Already applied',
    conflict: 'Changed since preview. Reopen to review.',
};
interface ApplyResult {
    id: number;
    status: keyof typeof statusLabels;
}

async function loadPreview(ids: number[], signal: AbortSignal): Promise<Preview> {
    const preview: Preview = {
        candidates: [],
        skipped: { other_type: 0, changed_text: 0, insufficient_metadata: 0, lookup_failed: 0 },
        next_cursor: null,
    };
    const reviewId = crypto.randomUUID();
    for (let offset = 0; offset < ids.length; offset += 100) {
        let cursor: number | null = null;
        do {
            const { data }: { data: Preview } = await axios.post(
                '/resources/citation-labels/preview',
                { ids: ids.slice(offset, offset + 100), cursor, review_id: reviewId },
                { signal },
            );
            preview.candidates.push(...data.candidates);
            for (const key of Object.keys(skipLabels) as (keyof SkipCounts)[]) preview.skipped[key] += data.skipped[key];
            cursor = data.next_cursor;
        } while (cursor !== null);
    }
    return preview;
}

export function CitationLabelReviewDialog({ ids, onClose }: { ids: number[]; onClose: () => void }) {
    const [preview, setPreview] = useState<Preview | null>(null);
    const [selected, setSelected] = useState<number[]>([]);
    const [results, setResults] = useState<Record<number, ApplyResult['status']>>({});
    const [error, setError] = useState('');
    const [attempt, setAttempt] = useState(0);
    const [applying, setApplying] = useState(false);

    useEffect(() => {
        const controller = new AbortController();
        loadPreview(ids, controller.signal)
            .then((data) => {
                if (!controller.signal.aborted) setPreview(data);
            })
            .catch(() => {
                if (!controller.signal.aborted) setError('The citation preview could not be loaded.');
            });
        return () => controller.abort();
    }, [ids, attempt]);

    const available = preview?.candidates.filter((row) => !results[row.id]) ?? [];
    const selectedIds = new Set(selected);
    const selectAll = getSelectAllState(available.map((row) => selectedIds.has(row.id)));
    async function applySelected() {
        const rows = available.filter((row) => selectedIds.has(row.id));
        setApplying(true);
        setError('');
        try {
            for (let offset = 0; offset < rows.length; offset += 50) {
                const { data } = await axios.post<{ results: ApplyResult[] }>('/resources/citation-labels/apply', {
                    tokens: rows.slice(offset, offset + 50).map((row) => row.token),
                });
                const statuses = Object.fromEntries(data.results.map(({ id, status }) => [id, status]));
                setResults((previous) => ({ ...previous, ...statuses }));
                setSelected((previous) => previous.filter((id) => !(id in statuses)));
            }
        } catch {
            setError('Could not confirm all selected labels. Retry to check their status.');
        } finally {
            setApplying(false);
        }
    }

    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open && !applying) onClose();
            }}
        >
            <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-4xl">
                <DialogHeader>
                    <DialogTitle>Review citation labels</DialogTitle>
                    <DialogDescription>Compare existing labels on published landing pages with APA 7. Select the changes to apply.</DialogDescription>
                </DialogHeader>
                {error && <p role="alert">{error}</p>}
                {!preview &&
                    (error ? (
                        <Button
                            variant="outline"
                            onClick={() => {
                                setError('');
                                setAttempt((value) => value + 1);
                            }}
                        >
                            Retry preview
                        </Button>
                    ) : (
                        <p role="status">Looking up DOI metadata…</p>
                    ))}
                {preview && (
                    <>
                        {preview.candidates.length === 0 ? (
                            <p>No matching citation labels found.</p>
                        ) : (
                            <>
                                <label className="flex items-center gap-2">
                                    <Checkbox
                                        disabled={applying || selectAll.isEmpty}
                                        checked={selectAll.allChecked}
                                        indeterminate={selectAll.indeterminate}
                                        onCheckedChange={(checked) => setSelected(checked === true ? available.map((row) => row.id) : [])}
                                    />
                                    Select all
                                </label>
                                {preview.candidates.map((row) => (
                                    <div key={row.id} className="space-y-2 border-t py-3">
                                        <label className="flex items-center gap-2 font-medium">
                                            <Checkbox
                                                disabled={applying || !!results[row.id]}
                                                checked={selectedIds.has(row.id)}
                                                onCheckedChange={(checked) =>
                                                    setSelected((current) =>
                                                        checked === true ? [...current, row.id] : current.filter((id) => id !== row.id),
                                                    )
                                                }
                                            />
                                            {row.resource} (#{row.resource_id}) · {row.work_type} · {row.doi}
                                        </label>
                                        <dl className="grid gap-3 break-words sm:grid-cols-2">
                                            <div>
                                                <dt className="text-sm text-muted-foreground">Stored label</dt>
                                                <dd>{row.old}</dd>
                                            </div>
                                            <div>
                                                <dt className="text-sm text-muted-foreground">APA 7</dt>
                                                <dd>
                                                    <CitationLabelText text={row.text} parts={row.parts} />
                                                </dd>
                                            </div>
                                        </dl>
                                        {results[row.id] && <p role="status">{statusLabels[results[row.id]]}</p>}
                                    </div>
                                ))}
                            </>
                        )}
                        {Object.entries(skipLabels).map(
                            ([key, label]) =>
                                preview.skipped[key as keyof SkipCounts] > 0 && (
                                    <p key={key} className="text-sm text-muted-foreground">
                                        {label} ({preview.skipped[key as keyof SkipCounts]})
                                    </p>
                                ),
                        )}
                    </>
                )}
                <DialogFooter>
                    <Button variant="outline" disabled={applying} onClick={onClose}>
                        Close
                    </Button>
                    <Button disabled={!selected.length || applying} onClick={() => void applySelected()}>
                        {applying ? 'Applying…' : 'Apply selected'} ({selected.length})
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
