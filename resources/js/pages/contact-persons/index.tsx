import { Head, Link, router } from '@inertiajs/react';
import { Check, Copy, FlaskConical, Layers, Search } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import type { ContactPersonsPageProps } from '@/types/contact-persons';

function CopyEmail({ email }: { email: string }) {
    const [copied, setCopied] = useState(false);
    const copy = async () => {
        try {
            await navigator.clipboard.writeText(email);
            setCopied(true);
            toast.success('Email address copied');
        } catch {
            setCopied(false);
            toast.error('Could not copy the email address. Select the address and copy it manually.');
        }
    };

    return (
        <Button variant="ghost" size="icon" onClick={() => void copy()} aria-label={`Copy email ${email}`} title={`Copy ${email}`}>
            {copied ? <Check className="size-4" /> : <Copy className="size-4" />}
        </Button>
    );
}

export default function ContactPersons({ groups, search, pagination }: ContactPersonsPageProps) {
    const [input, setInput] = useState(search);
    const [loading, setLoading] = useState(false);
    const inputRevision = useRef(0);
    const activeVisit = useRef<{ revision: number; query: string } | null>(null);
    const searchTimeout = useRef<ReturnType<typeof setTimeout> | null>(null);
    const pageSizeTrigger = useRef<HTMLButtonElement>(null);
    const pendingFocus = useRef<HTMLElement | null>(null);
    const cancelPendingSearch = useCallback(() => {
        if (searchTimeout.current !== null) clearTimeout(searchTimeout.current);
        searchTimeout.current = null;
    }, []);
    const visit = useCallback(
        (query: string, page = 1, perPage = pagination.per_page, focusTarget: HTMLElement | null = null) => {
            cancelPendingSearch();
            const request = { revision: inputRevision.current, query };
            activeVisit.current = request;
            router.get(
                '/contact-persons',
                { ...(query ? { search: query } : {}), page: query === search ? page : 1, per_page: perPage },
                {
                    preserveState: true,
                    preserveScroll: true,
                    onStart: () => setLoading(true),
                    onSuccess: (response) => {
                        // A response must not replace edits made after this visit started.
                        if (activeVisit.current === request && inputRevision.current === request.revision) {
                            setInput(response.props.search as string);
                        }
                    },
                    onFinish: () => {
                        if (activeVisit.current === request) {
                            activeVisit.current = null;
                            pendingFocus.current = focusTarget;
                            setLoading(false);
                        }
                    },
                },
            );
        },
        [search, pagination.per_page, cancelPendingSearch],
    );

    useEffect(() => {
        if (loading) return;
        const target = pendingFocus.current;
        pendingFocus.current = null;
        // Radix cannot return focus to a disabled trigger. Wait until it is enabled,
        // and leave focus alone if the user moved to another control while loading.
        if (document.activeElement === document.body) target?.focus({ preventScroll: true });
    }, [loading]);

    const changeSearch = (value: string) => {
        inputRevision.current += 1;
        setInput(value);
        cancelPendingSearch();
        const query = value.trim();
        if (query === search && (!activeVisit.current || activeVisit.current.query === query)) return;
        // Keep the debounce tied to the edit, independent of incoming response props.
        searchTimeout.current = setTimeout(() => visit(query), 400);
    };

    useEffect(() => {
        const unsubscribe = router.on('navigate', ({ detail }) => {
            if (detail.type !== 'history' || detail.page.component !== 'contact-persons/index') return;
            inputRevision.current += 1;
            activeVisit.current = null;
            cancelPendingSearch();
            setInput(detail.page.props.search as string);
            setLoading(false);
        });
        return () => {
            activeVisit.current = null;
            cancelPendingSearch();
            unsubscribe();
        };
    }, [cancelPendingSearch]);

    return (
        <AppLayout breadcrumbs={[{ title: 'Contact Persons', href: '/contact-persons' }]} transitionKey="contact-persons">
            <Head title="Contact Persons" />
            <section aria-label="Contact person overview" className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">Contact Persons</h1>
                    <p className="mt-2 text-sm text-muted-foreground">Find contact details recorded in resources and IGSNs, grouped by ORCID.</p>
                </div>
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div className="relative w-full sm:max-w-md">
                        <Search className="absolute top-3 left-3 size-4 text-muted-foreground" aria-hidden="true" />
                        <Input
                            type="search"
                            className="pl-9"
                            aria-label="Search contact persons"
                            placeholder="Search name, ORCID, or email…"
                            value={input}
                            onChange={(event) => changeSearch(event.target.value)}
                        />
                    </div>
                    <div className="flex items-center gap-2">
                        <Label htmlFor="contact-persons-per-page">Groups per page</Label>
                        <Select
                            value={String(pagination.per_page)}
                            disabled={loading}
                            onValueChange={(value) => visit(input.trim(), 1, Number(value), pageSizeTrigger.current)}
                        >
                            <SelectTrigger ref={pageSizeTrigger} id="contact-persons-per-page" className="w-20">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {[25, 50, 100].map((size) => (
                                    <SelectItem key={size} value={String(size)}>
                                        {size}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                </div>
                <div aria-busy={loading} role="region" aria-label="Contact details" tabIndex={0} className="overflow-x-auto rounded-lg border">
                    <table className="w-full min-w-3xl text-left text-sm">
                        <caption className="sr-only">
                            Contact persons grouped by ORCID, with recorded name and email variants and links to matching resources.
                        </caption>
                        <thead className="bg-muted/50">
                            <tr>
                                {['ORCID', 'Name', 'Email', 'Resources / IGSNs'].map((label) => (
                                    <th key={label} scope="col" className="px-4 py-3 font-medium">
                                        {label}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        {groups.map((group) => (
                            <tbody key={group.key} className="border-t">
                                {group.variants.map((variant, index) => (
                                    <tr key={variant.key} className="hover:bg-muted/30">
                                        {index === 0 && (
                                            <th
                                                scope="rowgroup"
                                                rowSpan={group.variants.length}
                                                className="px-4 py-3 align-top font-normal whitespace-nowrap"
                                            >
                                                {group.orcid ? (
                                                    <a
                                                        href={`https://orcid.org/${group.orcid}`}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        className="text-primary underline underline-offset-4"
                                                    >
                                                        {group.orcid}
                                                    </a>
                                                ) : (
                                                    <span className="text-muted-foreground">No valid ORCID</span>
                                                )}
                                            </th>
                                        )}
                                        <td className="min-w-40 px-4 py-3 align-top">{variant.name}</td>
                                        <td className="px-4 py-2 align-top">
                                            {variant.email ? (
                                                <div className="flex items-center justify-between gap-2">
                                                    <span className="break-all select-text">{variant.email}</span>
                                                    <CopyEmail key={variant.email} email={variant.email} />
                                                </div>
                                            ) : (
                                                <span className="text-muted-foreground">No email recorded</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-2 align-top">
                                            <div className="flex flex-wrap gap-2">
                                                {variant.resource_count > 0 && (
                                                    <Button variant="outline" size="sm" asChild>
                                                        <Link
                                                            href={`/resources?contact=${variant.key}`}
                                                            onClick={cancelPendingSearch}
                                                            aria-label={`View ${variant.resource_count} resources for ${variant.name}, ${variant.email || 'no email recorded'}`}
                                                            title="View matching resources"
                                                        >
                                                            <Layers className="size-4" />
                                                            {variant.resource_count} Resources
                                                        </Link>
                                                    </Button>
                                                )}
                                                {variant.igsn_count > 0 && (
                                                    <Button variant="outline" size="sm" asChild>
                                                        <Link
                                                            href={`/igsns?contact=${variant.key}`}
                                                            onClick={cancelPendingSearch}
                                                            aria-label={`View ${variant.igsn_count} IGSNs for ${variant.name}, ${variant.email || 'no email recorded'}`}
                                                            title="View matching IGSNs"
                                                        >
                                                            <FlaskConical className="size-4" />
                                                            {variant.igsn_count} IGSNs
                                                        </Link>
                                                    </Button>
                                                )}
                                                {variant.resource_count === 0 && variant.igsn_count === 0 && (
                                                    <span className="text-muted-foreground">No listed resources</span>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        ))}
                    </table>
                    {groups.length === 0 && (
                        <p role="status" className="p-8 text-center text-muted-foreground">
                            {search ? 'No contact persons match your search.' : 'No contact persons recorded yet.'}
                        </p>
                    )}
                </div>
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <p className="text-sm text-muted-foreground" aria-live="polite">
                        {pagination.total} contact groups · Page {pagination.current_page} of {pagination.last_page}
                    </p>
                    <div className="flex gap-2">
                        <Button
                            variant="outline"
                            disabled={loading || pagination.current_page <= 1}
                            onClick={() => visit(input.trim(), pagination.current_page - 1)}
                        >
                            Previous
                        </Button>
                        <Button
                            variant="outline"
                            disabled={loading || pagination.current_page >= pagination.last_page}
                            onClick={() => visit(input.trim(), pagination.current_page + 1)}
                        >
                            Next
                        </Button>
                    </div>
                </div>
            </section>
        </AppLayout>
    );
}
