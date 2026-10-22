import { Head, Link, router } from '@inertiajs/react';
import axios from 'axios';
import { Fragment, useEffect, useRef, useState } from 'react';

import { CurationText, dateLabel, locationHref } from '@/components/curation-workflow/curation-text';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import type { CurationPanelData, CurationRow, CurationUser } from '@/types/curation';

type Filters = Record<string, string | number | boolean | null>;
type Props = {
    resources: { data: CurationRow[]; current_page: number; last_page: number; total: number };
    filters: Filters;
    counts: Record<string, number>;
    curators: CurationUser[];
    datacenters: { id: number; name: string }[];
};

function Details({ id }: { id: number }) {
    const [data, setData] = useState<CurationPanelData | null>(null);
    const [error, setError] = useState(false);
    useEffect(() => {
        const controller = new AbortController();
        axios
            .get<CurationPanelData>(`/resources/${id}/curation`, { signal: controller.signal })
            .then((response) => setData(response.data))
            .catch(() => {
                if (!controller.signal.aborted) setError(true);
            });
        return () => controller.abort();
    }, [id]);
    if (error) return <p role="alert">Could not load details. Close and reopen this row to retry.</p>;
    if (!data) return <p role="status">Loading details…</p>;
    return (
        <div className="grid gap-4 p-3 md:grid-cols-3">
            <section>
                <h3 className="font-semibold">Open checklist items</h3>
                <ul className="list-disc pl-4">
                    {data.items
                        .filter((item) => item.state === 'open')
                        .map((item) => (
                            <li key={item.key}>{item.label}</li>
                        ))}
                </ul>
            </section>
            <section>
                <h3 className="font-semibold">Latest note</h3>
                <CurationText text={data.latest_note ?? 'No notes yet.'} />
            </section>
            <section>
                <h3 className="font-semibold">Data locations</h3>
                {data.locations.length === 0 && <p>No locations recorded.</p>}
                {data.locations.map((location) => (
                    <p key={location.id} className="break-all">
                        {locationHref(location.location) ? (
                            <a className="underline" href={locationHref(location.location)} rel="noopener noreferrer" target="_blank">
                                {location.location}
                            </a>
                        ) : (
                            location.location
                        )}
                    </p>
                ))}
            </section>
        </div>
    );
}

export default function CurationOverview({ resources, filters: serverFilters, counts, curators, datacenters }: Props) {
    const [expanded, setExpanded] = useState<number[]>([]);
    const [filters, setFilters] = useState(serverFilters);
    const currentFilters = useRef(serverFilters);
    const visitId = useRef(0);
    useEffect(() => {
        visitId.current++;
        currentFilters.current = serverFilters;
        setFilters(serverFilters);
    }, [serverFilters]);
    const navigate = (next: Filters) => {
        const visit = ++visitId.current;
        currentFilters.current = next;
        setFilters(next);
        const restore = () => {
            // Cancelling an older request must not erase a newer selection.
            if (visit !== visitId.current) return;
            currentFilters.current = serverFilters;
            setFilters(serverFilters);
        };
        router.get('/curation', Object.fromEntries(Object.entries(next).filter(([, value]) => value !== '' && value !== null && value !== false)), {
            preserveScroll: true,
            preserveState: true,
            onError: restore,
            onCancel: restore,
        });
    };
    const update = (patch: Filters) => navigate({ ...currentFilters.current, ...patch, page: patch.page ?? 1 });
    const query = new URLSearchParams(
        Object.entries(filters)
            .filter(([, value]) => value !== null)
            .map(([key, value]) => [key, String(value)]),
    );
    const tabs = [
        ['progress', 'In progress'],
        ['review', 'Review'],
        ['embargo', 'Embargo'],
        ['published', 'Published'],
    ];
    const cell = 'px-3 py-3 align-top text-sm';
    return (
        <AppLayout breadcrumbs={[{ title: 'Curation', href: '/curation' }]} transitionKey="curation">
            <Head title="Curation" />
            <div className="space-y-5 p-4 sm:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold">Curation</h1>
                        <p className="text-muted-foreground">Track responsibility, progress and the next steps for your resources.</p>
                    </div>
                    <Button asChild variant="outline">
                        <a href={`/curation/export?${query}`}>Export CSV</a>
                    </Button>
                </div>
                <div className="flex flex-wrap gap-4 rounded-lg border p-4" aria-label="Curation filters">
                    {[
                        ['mine', 'My Resources'],
                        ['waiting', 'Waiting for reply'],
                        ['help', 'Needs help'],
                        ['overdue', 'Overdue'],
                        ['unassigned', 'Unassigned'],
                    ].map(([key, label]) => (
                        <div className="flex items-center gap-2" key={key}>
                            <Checkbox
                                id={`curation-filter-${key}`}
                                checked={Boolean(Number(filters[key] ?? 0))}
                                onCheckedChange={(checked) => update({ [key]: checked === true ? 1 : null })}
                            />
                            <Label htmlFor={`curation-filter-${key}`}>{label}</Label>
                        </div>
                    ))}
                    <div className="flex items-center gap-2">
                        <Label htmlFor="curation-filter-curator">Curator</Label>
                        <Select
                            value={String(filters.curator || 'all')}
                            onValueChange={(value) => update({ curator: value === 'all' ? null : value })}
                        >
                            <SelectTrigger id="curation-filter-curator" className="w-auto">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All curators</SelectItem>
                                {curators.map((user) => (
                                    <SelectItem key={user.id} value={String(user.id)}>
                                        {user.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="flex items-center gap-2">
                        <Label htmlFor="curation-filter-datacenter">Data centre</Label>
                        <Select
                            value={String(filters.datacenter || 'all')}
                            onValueChange={(value) => update({ datacenter: value === 'all' ? null : value })}
                        >
                            <SelectTrigger id="curation-filter-datacenter" className="w-auto">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All data centres</SelectItem>
                                {datacenters.map((centre) => (
                                    <SelectItem key={centre.id} value={String(centre.id)}>
                                        {centre.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <Button type="button" variant="ghost" onClick={() => navigate({})}>
                        Clear filters
                    </Button>
                </div>
                {filters.tab === 'all' && (
                    <p>Showing open work across all stages{filters.waiting_overdue ? ' waiting longer than the configured limit' : ''}.</p>
                )}
                <nav className="flex flex-wrap gap-2" aria-label="Curation stages">
                    {tabs.map(([key, label]) => (
                        <Button
                            key={key}
                            type="button"
                            variant={(filters.tab ?? 'progress') === key ? 'default' : 'outline'}
                            aria-current={(filters.tab ?? 'progress') === key ? 'page' : undefined}
                            onClick={() => update({ tab: key, open: null, waiting_overdue: null })}
                        >
                            {label} ({counts[key] ?? 0})
                        </Button>
                    ))}
                </nav>
                <div className="flex flex-wrap gap-3">
                    <div className="flex items-center gap-2">
                        <Label htmlFor="curation-sort">Sort by</Label>
                        <Select value={String(filters.sort ?? 'activity')} onValueChange={(value) => update({ sort: value })}>
                            <SelectTrigger id="curation-sort" className="w-auto">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="activity">Last activity</SelectItem>
                                <SelectItem value="created">First saved</SelectItem>
                                <SelectItem value="deadline">Deadline</SelectItem>
                                <SelectItem value="progress">Progress</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="flex items-center gap-2">
                        <Label htmlFor="curation-direction">Direction</Label>
                        <Select value={String(filters.direction ?? 'desc')} onValueChange={(value) => update({ direction: value })}>
                            <SelectTrigger id="curation-direction" className="w-auto">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="desc">Descending</SelectItem>
                                <SelectItem value="asc">Ascending</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </div>
                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-left">
                        <caption className="sr-only">Curation resources, {resources.total} matching records</caption>
                        <thead className="bg-muted">
                            <tr>
                                {[
                                    'Resource',
                                    'DOI',
                                    'Data centre / Contact',
                                    'First saved',
                                    'Status',
                                    'Curators',
                                    'Progress',
                                    'Working state',
                                    'Deadline',
                                    'Last activity',
                                ].map((title) => (
                                    <th scope="col" key={title} className={cell}>
                                        {title}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {resources.data.map((row) => (
                                <Fragment key={row.id}>
                                    <tr className="border-t">
                                        <td className={`${cell} min-w-60`}>
                                            <Link className="font-medium underline" href={`/editor?resourceId=${row.id}`}>
                                                {row.title}
                                            </Link>
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="ghost"
                                                aria-expanded={expanded.includes(row.id)}
                                                aria-controls={`curation-details-${row.id}`}
                                                onClick={() =>
                                                    setExpanded((ids) =>
                                                        ids.includes(row.id) ? ids.filter((id) => id !== row.id) : [...ids, row.id],
                                                    )
                                                }
                                            >
                                                {expanded.includes(row.id) ? 'Hide details' : 'Show details'}
                                            </Button>
                                        </td>
                                        <td className={cell}>{row.doi ?? '—'}</td>
                                        <td className={cell}>
                                            {row.datacenter ?? '—'}
                                            <p className="text-muted-foreground">{row.contact}</p>
                                        </td>
                                        <td className={cell}>{dateLabel(row.created_at)}</td>
                                        <td className={cell}>{row.status}</td>
                                        <td className={cell}>
                                            <p className="font-medium">
                                                {row.curators.find((user) => user.id === row.lead_id)?.name ?? 'Unassigned'}
                                            </p>
                                            {row.curators
                                                .filter((user) => user.id !== row.lead_id)
                                                .map((user) => (
                                                    <p key={user.id}>{user.name}</p>
                                                ))}
                                        </td>
                                        <td className={cell}>{row.progress}/13</td>
                                        <td className={cell}>
                                            {row.waiting_days !== null && <p>Waiting {row.waiting_days} days</p>}
                                            {row.needs_help && <p>Needs help</p>}
                                            {row.overdue && <p className="font-medium text-destructive">Overdue</p>}
                                        </td>
                                        <td className={cell}>{dateLabel(row.deadline)}</td>
                                        <td className={cell}>{dateLabel(row.last_activity_at)}</td>
                                    </tr>
                                    {expanded.includes(row.id) && (
                                        <tr id={`curation-details-${row.id}`} className="border-t bg-muted/30">
                                            <td colSpan={10}>
                                                <Details id={row.id} />
                                            </td>
                                        </tr>
                                    )}
                                </Fragment>
                            ))}
                            {resources.data.length === 0 && (
                                <tr>
                                    <td colSpan={10} className="p-8 text-center text-muted-foreground">
                                        No curations match these filters.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
                <div className="flex items-center justify-between gap-3">
                    <Button
                        type="button"
                        variant="outline"
                        disabled={resources.current_page <= 1}
                        onClick={() => update({ page: resources.current_page - 1 })}
                    >
                        Previous
                    </Button>
                    <p>
                        Page {resources.current_page} of {resources.last_page} · {resources.total} resources
                    </p>
                    <Button
                        type="button"
                        variant="outline"
                        disabled={resources.current_page >= resources.last_page}
                        onClick={() => update({ page: resources.current_page + 1 })}
                    >
                        Next
                    </Button>
                </div>
            </div>
        </AppLayout>
    );
}
