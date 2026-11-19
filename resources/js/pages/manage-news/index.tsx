import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import { type FormEvent, useEffect, useState } from 'react';
import { toast } from 'sonner';

import { HomeNews } from '@/components/home/HomeNews';
import { Alert, AlertDescription } from '@/components/ui/alert';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import type { ManagedNews, NewsManagementProps } from '@/types/news';

interface Draft {
    id: number | null;
    revision: number | null;
    title: string;
    body_markdown: string;
    kind: 'regular' | 'temporary';
    starts_at: string;
    ends_at: string;
    starts_at_offset: string;
    ends_at_offset: string;
}

const emptyDraft = (): Draft => ({
    id: null,
    revision: null,
    title: '',
    body_markdown: '',
    kind: 'regular',
    starts_at: '',
    ends_at: '',
    starts_at_offset: '',
    ends_at_offset: '',
});

function draftFrom(news: ManagedNews): Draft {
    return {
        id: news.id,
        revision: news.revision,
        title: news.title,
        body_markdown: news.body_markdown,
        kind: news.kind,
        starts_at: news.starts_at?.slice(0, 16) ?? '',
        ends_at: news.ends_at?.slice(0, 16) ?? '',
        starts_at_offset: news.starts_at?.slice(-6) ?? '',
        ends_at_offset: news.ends_at?.slice(-6) ?? '',
    };
}

function berlinTime(value: string | null): string {
    return value
        ? new Date(value).toLocaleString('en-GB', {
              timeZone: 'Europe/Berlin',
              year: 'numeric',
              month: 'short',
              day: '2-digit',
              hour: '2-digit',
              minute: '2-digit',
              timeZoneName: 'longOffset',
          })
        : '—';
}

function InputError({ message }: { message?: string }) {
    return message ? <p className="text-sm text-destructive">{message}</p> : null;
}

export default function ManageNews({ news, active_news_id, state_revision, currently_displayed }: NewsManagementProps) {
    const [draft, setDraft] = useState<Draft>(emptyDraft);
    const [savedDraft, setSavedDraft] = useState<Draft>(emptyDraft);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);
    const [showArchive, setShowArchive] = useState(false);
    const [archiveTarget, setArchiveTarget] = useState<ManagedNews | null>(null);
    const [preview, setPreview] = useState('');
    const [previewError, setPreviewError] = useState('');
    const [previewLoading, setPreviewLoading] = useState(false);
    const editing = news.find((item) => item.id === draft.id);
    const active = news.find((item) => item.id === active_news_id);
    const dirty = JSON.stringify(draft) !== JSON.stringify(savedDraft);

    useEffect(() => {
        const controller = new AbortController();
        setPreviewError('');
        setPreview('');
        setPreviewLoading(Boolean(draft.body_markdown));
        const timer = window.setTimeout(() => {
            if (!draft.body_markdown) return;
            void axios
                .post<{ html: string }>('/manage-news/preview', { body_markdown: draft.body_markdown }, { signal: controller.signal })
                .then(({ data }) => {
                    if (!controller.signal.aborted) setPreview(data.html);
                })
                .catch(() => {
                    if (!controller.signal.aborted) setPreviewError('The preview could not be loaded. Your text is kept.');
                })
                .finally(() => {
                    if (!controller.signal.aborted) setPreviewLoading(false);
                });
        }, 300);
        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [draft.body_markdown]);

    function selectNews(item: ManagedNews | null, force = false) {
        if (!force && dirty && !window.confirm('Discard your unsaved news edits?')) return;
        const next = item ? draftFrom(item) : emptyDraft();
        setDraft(next);
        setSavedDraft(next);
        setErrors({});
    }

    async function send(path: string, data: object, method: 'post' | 'patch' = 'post', selectSaved = false) {
        setBusy(true);
        setErrors({});
        try {
            const response = await axios.request<{ id: number }>({ url: path, method, data });
            router.reload({
                only: ['news', 'active_news_id', 'state_revision', 'currently_displayed'],
                onSuccess: (page) => {
                    if (selectSaved) {
                        const updated = (page.props.news as ManagedNews[]).find((item) => item.id === response.data.id);
                        if (updated) selectNews(updated, true);
                    } else if (!dirty && draft.id !== null) {
                        const updated = (page.props.news as ManagedNews[]).find((item) => item.id === draft.id);
                        if (updated) selectNews(updated.archived_at ? null : updated, true);
                    }
                    toast.success('News saved.');
                },
                onFinish: () => setBusy(false),
            });
        } catch (error) {
            if (axios.isAxiosError<{ errors?: Record<string, string[]>; message?: string }>(error)) {
                const fields = error.response?.data.errors;
                setErrors(
                    fields
                        ? Object.fromEntries(Object.entries(fields).map(([key, messages]) => [key, messages[0]]))
                        : { request: error.response?.data.message ?? 'The request failed. Please try again.' },
                );
            } else {
                setErrors({ request: 'The request failed. Please try again.' });
            }
            setBusy(false);
        }
    }

    function save(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const { id, ...data } = draft;
        void send(id === null ? '/manage-news' : `/manage-news/${id}`, data, id === null ? 'post' : 'patch', true);
    }

    function statusActionDisabled(item: ManagedNews) {
        return busy || (dirty && draft.id === item.id);
    }

    function action(item: ManagedNews, operation: string) {
        if (statusActionDisabled(item)) return;
        void send(`/manage-news/${item.id}/${operation}`, { revision: item.revision, state_revision });
    }

    function reloadLatest() {
        if (dirty && !window.confirm('Reload the latest news and discard the edits in this form?')) return;
        setBusy(true);
        router.reload({
            onSuccess: (page) => selectNews((page.props.news as ManagedNews[]).find((item) => item.id === draft.id) ?? null, true),
            onFinish: () => setBusy(false),
        });
    }

    return (
        <AppLayout breadcrumbs={[{ title: 'Manage News', href: '/manage-news' }]}>
            <Head title="Manage News" />
            <div className="flex flex-col gap-6 p-4 sm:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold">Manage News</h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Publish homepage news and plan temporary announcements. All times use Europe/Berlin.
                        </p>
                    </div>
                    <Button type="button" onClick={() => selectNews(null)} disabled={busy}>
                        New news
                    </Button>
                </div>

                <section aria-label="Publication status" className="grid gap-4 lg:grid-cols-2">
                    <div>
                        <h2 className="mb-2 font-semibold">Currently displayed</h2>
                        <HomeNews news={currently_displayed} />
                    </div>
                    <Card>
                        <CardHeader>
                            <CardTitle>Active regular news</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            <p className="font-medium">{active?.title}</p>
                            <p className="text-sm text-muted-foreground">
                                This news appears whenever no temporary announcement is scheduled for the current time.
                            </p>
                            {currently_displayed.id !== active_news_id && (
                                <p className="text-sm">
                                    A temporary announcement currently replaces it until{' '}
                                    {berlinTime(news.find((item) => item.id === currently_displayed.id)?.ends_at ?? null)} (Europe/Berlin).
                                </p>
                            )}
                        </CardContent>
                    </Card>
                </section>

                {Object.keys(errors).length > 0 && (
                    <Alert variant="destructive" role="alert">
                        <AlertDescription>
                            {Object.values(errors)[0]}
                            {errors.revision && (
                                <Button type="button" variant="outline" className="mt-2" onClick={reloadLatest} disabled={busy}>
                                    Reload latest version
                                </Button>
                            )}
                        </AlertDescription>
                    </Alert>
                )}

                <section aria-label="News editor" className="grid items-start gap-6 xl:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>{draft.id === null ? 'Create news' : 'Edit news'}</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={save} className="space-y-4">
                                {(editing?.is_scheduled || editing?.id === active_news_id) && (
                                    <p className="text-sm text-amber-700 dark:text-amber-300">
                                        This news is published or scheduled. Saved changes affect its public display.
                                    </p>
                                )}
                                <div className="space-y-2">
                                    <Label htmlFor="news-title">Title</Label>
                                    <Input
                                        id="news-title"
                                        value={draft.title}
                                        required
                                        maxLength={255}
                                        disabled={busy}
                                        aria-invalid={Boolean(errors.title)}
                                        onChange={(event) => setDraft({ ...draft, title: event.target.value })}
                                    />
                                    <InputError message={errors.title} />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="news-kind">Type</Label>
                                    <Select
                                        value={draft.kind}
                                        disabled={busy || editing?.id === active_news_id || editing?.is_scheduled}
                                        onValueChange={(value) =>
                                            setDraft({
                                                ...draft,
                                                kind: value as Draft['kind'],
                                                starts_at: '',
                                                ends_at: '',
                                                starts_at_offset: '',
                                                ends_at_offset: '',
                                            })
                                        }
                                    >
                                        <SelectTrigger id="news-kind" aria-invalid={Boolean(errors.kind)}>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="regular">Regular news</SelectItem>
                                            <SelectItem value="temporary">Temporary announcement</SelectItem>
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.kind} />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="news-body">News text (Markdown)</Label>
                                    <Textarea
                                        id="news-body"
                                        rows={12}
                                        value={draft.body_markdown}
                                        required
                                        maxLength={100000}
                                        disabled={busy}
                                        aria-describedby="news-markdown-help"
                                        aria-invalid={Boolean(errors.body_markdown)}
                                        onChange={(event) => setDraft({ ...draft, body_markdown: event.target.value })}
                                    />
                                    <p id="news-markdown-help" className="text-xs text-muted-foreground">
                                        Use **bold**, *italic*, # headings, lists, [link text](https://example.org) and ![image
                                        description](https://example.org/image.jpg). Images need HTTPS URLs. HTML is removed.
                                    </p>
                                    <InputError message={errors.body_markdown} />
                                </div>
                                {draft.kind === 'temporary' && (
                                    <fieldset className="space-y-3 rounded-md border p-3">
                                        <legend className="px-1 text-sm font-medium">Display period · Europe/Berlin</legend>
                                        {(['starts_at', 'ends_at'] as const).map((field) => (
                                            <div key={field} className="grid gap-3 sm:grid-cols-2">
                                                <div className="space-y-2">
                                                    <Label htmlFor={`news-${field}`}>{field === 'starts_at' ? 'Start' : 'End'} (Europe/Berlin)</Label>
                                                    <Input
                                                        id={`news-${field}`}
                                                        type="datetime-local"
                                                        step={60}
                                                        required
                                                        value={draft[field]}
                                                        disabled={busy}
                                                        aria-invalid={Boolean(errors[field])}
                                                        onChange={(event) =>
                                                            setDraft({ ...draft, [field]: event.target.value, [`${field}_offset`]: '' })
                                                        }
                                                    />
                                                    <InputError message={errors[field]} />
                                                </div>
                                                <div className="space-y-2">
                                                    <Label htmlFor={`news-${field}-offset`}>
                                                        {field === 'starts_at' ? 'Start' : 'End'} UTC offset
                                                    </Label>
                                                    <Select
                                                        value={draft[`${field}_offset`] || 'automatic'}
                                                        disabled={busy}
                                                        onValueChange={(value) =>
                                                            setDraft({ ...draft, [`${field}_offset`]: value === 'automatic' ? '' : value })
                                                        }
                                                    >
                                                        <SelectTrigger id={`news-${field}-offset`} aria-invalid={Boolean(errors[`${field}_offset`])}>
                                                            <SelectValue />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            <SelectItem value="automatic">Automatic (unambiguous times)</SelectItem>
                                                            <SelectItem value="+02:00">+02:00 · Summer time (first occurrence)</SelectItem>
                                                            <SelectItem value="+01:00">+01:00 · Standard time (second occurrence)</SelectItem>
                                                        </SelectContent>
                                                    </Select>
                                                    <InputError message={errors[`${field}_offset`]} />
                                                </div>
                                            </div>
                                        ))}
                                        <p className="text-xs text-muted-foreground">
                                            The end is exclusive. During the autumn clock change, choose the UTC offset for a repeated time. Save
                                            first, then schedule the announcement.
                                        </p>
                                    </fieldset>
                                )}
                                <div className="flex flex-wrap gap-2">
                                    <Button type="submit" disabled={busy || Boolean(editing?.archived_at)}>
                                        {busy ? 'Saving…' : draft.id === null ? 'Save draft' : 'Save changes'}
                                    </Button>
                                    {draft.id !== null && (
                                        <Button type="button" variant="outline" disabled={busy} onClick={reloadLatest}>
                                            Reload latest version
                                        </Button>
                                    )}
                                </div>
                                {dirty && draft.id !== null && (
                                    <p className="text-sm text-muted-foreground">Save or reload your changes before changing this news status.</p>
                                )}
                                <p className="text-xs text-muted-foreground">
                                    New news remain drafts until you activate or schedule them in the list below.
                                </p>
                            </form>
                        </CardContent>
                    </Card>
                    <div aria-label="News preview" className="space-y-2">
                        <h2 className="font-semibold">Preview</h2>
                        <p role="status" className="text-sm text-muted-foreground">
                            {previewLoading
                                ? 'Updating preview…'
                                : previewError ||
                                  (!draft.body_markdown
                                      ? 'Enter Markdown text to see a preview.'
                                      : 'Preview uses the same formatting as the homepage.')}
                        </p>
                        <HomeNews news={{ id: draft.id ?? 0, title: draft.title || 'News title', html: preview }} />
                    </div>
                </section>

                <section aria-label="Saved news" className="space-y-3">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h2 className="text-lg font-semibold">Saved news</h2>
                        <div className="flex items-center gap-2">
                            <Checkbox id="news-show-archive" checked={showArchive} onCheckedChange={(checked) => setShowArchive(checked === true)} />
                            <Label htmlFor="news-show-archive">Show archived news</Label>
                        </div>
                    </div>
                    <div className="overflow-x-auto rounded-md border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Title</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Period (Europe/Berlin)</TableHead>
                                    <TableHead>Last updated</TableHead>
                                    <TableHead>Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {news
                                    .filter((item) => (showArchive ? Boolean(item.archived_at) : !item.archived_at))
                                    .map((item) => (
                                        <TableRow key={item.id} data-news-id={item.id}>
                                            <TableCell className="max-w-sm min-w-40 whitespace-normal">
                                                <p className="font-medium">{item.title}</p>
                                                <p className="text-xs text-muted-foreground">
                                                    {item.kind === 'regular' ? 'Regular news' : 'Temporary announcement'}
                                                </p>
                                            </TableCell>
                                            <TableCell>
                                                <Badge variant="outline">{item.status}</Badge>
                                            </TableCell>
                                            <TableCell className="whitespace-nowrap">
                                                {item.kind === 'temporary' ? (
                                                    <>
                                                        {berlinTime(item.starts_at)}
                                                        <br />
                                                        to {berlinTime(item.ends_at)}
                                                    </>
                                                ) : (
                                                    'No time limit'
                                                )}
                                            </TableCell>
                                            <TableCell className="whitespace-nowrap">{berlinTime(item.updated_at)}</TableCell>
                                            <TableCell>
                                                <div className="flex flex-wrap gap-2">
                                                    {item.archived_at ? (
                                                        <Button
                                                            type="button"
                                                            size="sm"
                                                            variant="outline"
                                                            disabled={statusActionDisabled(item)}
                                                            onClick={() => action(item, 'restore')}
                                                        >
                                                            Restore
                                                        </Button>
                                                    ) : (
                                                        <>
                                                            <Button
                                                                type="button"
                                                                size="sm"
                                                                variant="outline"
                                                                disabled={busy}
                                                                onClick={() => selectNews(item)}
                                                            >
                                                                Edit
                                                            </Button>
                                                            {item.kind === 'regular' && (
                                                                <Button
                                                                    type="button"
                                                                    size="sm"
                                                                    variant="outline"
                                                                    disabled={statusActionDisabled(item) || item.id === active_news_id}
                                                                    onClick={() => action(item, 'activate')}
                                                                >
                                                                    Set as active
                                                                </Button>
                                                            )}
                                                            {item.kind === 'temporary' && (
                                                                <Button
                                                                    type="button"
                                                                    size="sm"
                                                                    variant="outline"
                                                                    disabled={statusActionDisabled(item)}
                                                                    onClick={() => action(item, item.is_scheduled ? 'cancel-schedule' : 'schedule')}
                                                                >
                                                                    {item.is_scheduled ? 'Cancel schedule' : 'Schedule'}
                                                                </Button>
                                                            )}
                                                            <Button
                                                                type="button"
                                                                size="sm"
                                                                variant="outline"
                                                                disabled={statusActionDisabled(item) || item.id === active_news_id}
                                                                title={
                                                                    item.id === active_news_id
                                                                        ? 'Select another regular news before archiving this one.'
                                                                        : undefined
                                                                }
                                                                onClick={() => setArchiveTarget(item)}
                                                            >
                                                                Archive
                                                            </Button>
                                                        </>
                                                    )}
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                {news.filter((item) => (showArchive ? Boolean(item.archived_at) : !item.archived_at)).length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={5}>No news in this view.</TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </div>
                </section>
            </div>
            <AlertDialog
                open={archiveTarget !== null}
                onOpenChange={(open) => {
                    if (!open) setArchiveTarget(null);
                }}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Archive this news?</AlertDialogTitle>
                        <AlertDialogDescription>
                            This keeps the news for later reuse and cancels its schedule. If it is currently displayed, the homepage returns to the
                            active regular news.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction
                            disabled={archiveTarget === null || statusActionDisabled(archiveTarget)}
                            onClick={() => {
                                if (archiveTarget) action(archiveTarget, 'archive');
                                setArchiveTarget(null);
                            }}
                        >
                            Archive news
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AppLayout>
    );
}
