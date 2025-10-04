import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { withBasePath } from '@/lib/base-path';
import { buildCurationQueryFromResource } from '@/lib/curation-query';
import { useCallback, useMemo } from 'react';
import { PencilLine } from 'lucide-react';
import { curation as curationRoute } from '@/routes';

interface ResourceTitleType {
    name: string | null;
    slug: string | null;
}

interface ResourceTitle {
    title: string;
    title_type: ResourceTitleType | null;
}

interface ResourceLicense {
    identifier: string | null;
    name: string | null;
}

interface ResourceTypeSummary {
    name: string | null;
    slug: string | null;
}

interface ResourceLanguageSummary {
    code: string | null;
    name: string | null;
}

interface ResourceListItem {
    id: number;
    doi: string | null;
    year: number;
    version: string | null;
    created_at: string | null;
    updated_at: string | null;
    resource_type: ResourceTypeSummary | null;
    language: ResourceLanguageSummary | null;
    titles: ResourceTitle[];
    licenses: ResourceLicense[];
}

interface PaginationInfo {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    has_more: boolean;
}

interface ResourcesPageProps {
    resources: ResourceListItem[];
    pagination: PaginationInfo;
}

const PAGE_TITLE = 'Resources';
const MAIN_TITLE_SLUG = 'main-title';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: PAGE_TITLE,
        href: '/resources',
    },
];

const getPrimaryTitle = (titles: ResourceTitle[]): string => {
    if (titles.length === 0) {
        return 'Untitled resource';
    }

    const mainTitle = titles.find((entry) => entry.title_type?.slug === MAIN_TITLE_SLUG);

    return (mainTitle ?? titles[0]).title || 'Untitled resource';
};

const getAdditionalTitles = (titles: ResourceTitle[]): ResourceTitle[] => {
    if (titles.length <= 1) {
        return [];
    }

    const primarySlug = titles.find((entry) => entry.title_type?.slug === MAIN_TITLE_SLUG)?.title_type?.slug;

    return titles.filter((entry) => {
        if (primarySlug) {
            return entry.title_type?.slug !== primarySlug;
        }

        return entry !== titles[0];
    });
};

const buildDoiUrl = (doi: string | null): string | null => {
    if (!doi) {
        return null;
    }

    const trimmed = doi.trim();

    if (!trimmed) {
        return null;
    }

    return `https://doi.org/${trimmed}`;
};

const formatDateTime = (isoString: string | null): { label: string; iso?: string } => {
    if (!isoString) {
        return { label: 'Not available' };
    }

    const date = new Date(isoString);

    if (Number.isNaN(date.getTime())) {
        return { label: 'Not available' };
    }

    const formatter = new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });

    return { label: formatter.format(date), iso: date.toISOString() };
};

const describeLanguage = (language: ResourceLanguageSummary | null): string => {
    if (!language) {
        return 'Not specified';
    }

    if (language.name && language.code) {
        return `${language.name} (${language.code.toUpperCase()})`;
    }

    return language.name ?? language.code ?? 'Not specified';
};

const describeResourceType = (resourceType: ResourceTypeSummary | null): string =>
    resourceType?.name ?? 'Not classified';

const describeLicense = (license: ResourceLicense): string =>
    license.name ?? license.identifier ?? 'Unlabelled license';

const ResourcesPage = ({ resources, pagination }: ResourcesPageProps) => {
    const hasResources = resources.length > 0;

    const summaryLabel = useMemo(() => {
        if (!hasResources) {
            return 'No resources available yet.';
        }

        const from = pagination.from ?? 0;
        const to = pagination.to ?? resources.length;
        const total = pagination.total;

        return `Showing ${from.toLocaleString()}–${to.toLocaleString()} of ${total.toLocaleString()} resources`;
    }, [hasResources, pagination.from, pagination.to, pagination.total, resources.length]);

    const handlePageChange = (page: number) => {
        router.get(
            withBasePath('/resources'),
            {
                page,
                per_page: pagination.per_page,
            },
            {
                preserveScroll: true,
                preserveState: true,
            },
        );
    };

    const handleEditResource = useCallback(async (resource: ResourceListItem) => {
        try {
            const query = await buildCurationQueryFromResource(resource);
            router.get(curationRoute({ query }).url);
        } catch (error) {
            console.error('Unable to open resource in curation.', error);
            router.get(curationRoute().url);
        }
    }, []);

    const isFirstPage = pagination.current_page <= 1;
    const isLastPage = !pagination.has_more;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={PAGE_TITLE} />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-hidden rounded-xl p-4">
                <Card>
                    <CardHeader className="space-y-2">
                        <CardTitle asChild>
                            <h1 className="text-2xl font-semibold tracking-tight">{PAGE_TITLE}</h1>
                        </CardTitle>
                        <CardDescription className="text-base">
                            Browse and review the curated resources stored in ERNIE.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-6">
                        <p className="text-sm text-muted-foreground" role="status" aria-live="polite">
                            {summaryLabel}
                        </p>

                        {!hasResources ? (
                            <Alert role="status">
                                <AlertTitle>No resources found</AlertTitle>
                                <AlertDescription>
                                    Once new resources are added through the curation workflow, they will appear in this list.
                                </AlertDescription>
                            </Alert>
                        ) : (
                            <>
                                <div className="overflow-x-auto">
                                    <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                        <caption className="sr-only">
                                            Detailed list of curated resources including titles, identifiers, classifications, and lifecycle
                                            information.
                                        </caption>
                                        <thead className="bg-muted/60">
                                            <tr>
                                                <th
                                                    scope="col"
                                                    className="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground"
                                                >
                                                    Title
                                                </th>
                                                <th
                                                    scope="col"
                                                    className="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground"
                                                >
                                                    Identifiers
                                                </th>
                                                <th
                                                    scope="col"
                                                    className="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground"
                                                >
                                                    Classification
                                                </th>
                                                <th
                                                    scope="col"
                                                    className="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground"
                                                >
                                                    Licenses
                                                </th>
                                                <th
                                                    scope="col"
                                                    className="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground"
                                                >
                                                    Lifecycle
                                                </th>
                                                <th
                                                    scope="col"
                                                    className="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground"
                                                >
                                                    Actions
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-gray-200 bg-background text-sm dark:divide-gray-700">
                                            {resources.map((resource) => {
                                                const primaryTitle = getPrimaryTitle(resource.titles);
                                                const additionalTitles = getAdditionalTitles(resource.titles);
                                                const doiUrl = buildDoiUrl(resource.doi);
                                                const createdAt = formatDateTime(resource.created_at);
                                                const updatedAt = formatDateTime(resource.updated_at);

                                                return (
                                                    <tr
                                                        key={resource.id}
                                                        className="transition-colors hover:bg-muted/40 focus-within:bg-muted/50"
                                                    >
                                                        <td className="px-6 py-4 align-top">
                                                            <div className="flex flex-col gap-2">
                                                                <div className="flex flex-wrap items-center gap-3">
                                                                    <span className="text-base font-semibold text-foreground">{primaryTitle}</span>
                                                                    <Badge variant="outline" className="rounded-full px-3 text-xs">
                                                                        {resource.year}
                                                                    </Badge>
                                                                </div>
                                                                {additionalTitles.length > 0 ? (
                                                                    <details className="group rounded-md text-sm text-muted-foreground">
                                                                        <summary className="cursor-pointer rounded-md px-1 py-0.5 font-medium text-foreground outline-none transition focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2">
                                                                            Show additional titles
                                                                        </summary>
                                                                        <ul className="mt-2 list-disc space-y-1 pl-5">
                                                                            {additionalTitles.map((title, index) => (
                                                                                <li key={`${resource.id}-title-${index}`} className="leading-relaxed">
                                                                                    {title.title}
                                                                                    {title.title_type?.name ? (
                                                                                        <span className="ml-2 text-xs uppercase tracking-wide text-muted-foreground">
                                                                                            {title.title_type.name}
                                                                                        </span>
                                                                                    ) : null}
                                                                                </li>
                                                                            ))}
                                                                        </ul>
                                                                    </details>
                                                                ) : null}
                                                            </div>
                                                        </td>
                                                        <td className="px-6 py-4 align-top">
                                                            <dl className="space-y-2 text-sm">
                                                                <div>
                                                                    <dt className="font-medium text-muted-foreground">DOI</dt>
                                                                    <dd>
                                                                        {doiUrl ? (
                                                                            <a
                                                                                className="inline-flex items-center gap-2 text-primary underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                                                                                href={doiUrl}
                                                                                target="_blank"
                                                                                rel="noopener noreferrer"
                                                                            >
                                                                                {resource.doi}
                                                                                <span className="sr-only">(opens in a new tab)</span>
                                                                            </a>
                                                                        ) : (
                                                                            <span className="text-muted-foreground">Not provided</span>
                                                                        )}
                                                                    </dd>
                                                                </div>
                                                                <div>
                                                                    <dt className="font-medium text-muted-foreground">Version</dt>
                                                                    <dd>{resource.version ?? <span className="text-muted-foreground">Not provided</span>}</dd>
                                                                </div>
                                                            </dl>
                                                        </td>
                                                        <td className="px-6 py-4 align-top">
                                                            <dl className="space-y-2 text-sm">
                                                                <div>
                                                                    <dt className="font-medium text-muted-foreground">Resource type</dt>
                                                                    <dd>{describeResourceType(resource.resource_type)}</dd>
                                                                </div>
                                                                <div>
                                                                    <dt className="font-medium text-muted-foreground">Language</dt>
                                                                    <dd>{describeLanguage(resource.language)}</dd>
                                                                </div>
                                                            </dl>
                                                        </td>
                                                        <td className="px-6 py-4 align-top">
                                                            {resource.licenses.length > 0 ? (
                                                                <ul className="flex flex-wrap gap-2" aria-label="Licenses">
                                                                    {resource.licenses.map((license, index) => (
                                                                        <li key={`${resource.id}-license-${index}`}>
                                                                            <Badge variant="secondary" className="rounded-full px-3 py-1 text-xs">
                                                                                {describeLicense(license)}
                                                                            </Badge>
                                                                        </li>
                                                                    ))}
                                                                </ul>
                                                            ) : (
                                                                <span className="text-sm text-muted-foreground">No licenses linked</span>
                                                            )}
                                                        </td>
                                                        <td className="px-6 py-4 align-top">
                                                            <dl className="space-y-2 text-sm">
                                                                <div>
                                                                    <dt className="font-medium text-muted-foreground">Created</dt>
                                                                    <dd>
                                                                        {createdAt.iso ? (
                                                                            <time dateTime={createdAt.iso}>{createdAt.label}</time>
                                                                        ) : (
                                                                            <span className="text-muted-foreground">{createdAt.label}</span>
                                                                        )}
                                                                    </dd>
                                                                </div>
                                                                <div>
                                                                    <dt className="font-medium text-muted-foreground">Updated</dt>
                                                                    <dd>
                                                                        {updatedAt.iso ? (
                                                                            <time dateTime={updatedAt.iso}>{updatedAt.label}</time>
                                                                        ) : (
                                                                            <span className="text-muted-foreground">{updatedAt.label}</span>
                                                                        )}
                                                                    </dd>
                                                                </div>
                                                            </dl>
                                                        </td>
                                                        <td className="px-6 py-4 align-top">
                                                            <Button
                                                                type="button"
                                                                variant="ghost"
                                                                size="icon"
                                                                onClick={() => {
                                                                    void handleEditResource(resource);
                                                                }}
                                                                aria-label={`Edit ${primaryTitle} in the curation editor`}
                                                                title={`Edit ${primaryTitle} in the curation editor`}
                                                            >
                                                                <PencilLine aria-hidden="true" className="size-4" />
                                                            </Button>
                                                        </td>
                                                    </tr>
                                                );
                                            })}
                                        </tbody>
                                    </table>
                                </div>
                                <nav
                                    aria-label="Resources pagination"
                                    className="flex flex-col gap-3 border-t border-border pt-4 sm:flex-row sm:items-center sm:justify-between"
                                >
                                    <div className="text-sm text-muted-foreground">
                                        Page {pagination.current_page.toLocaleString()} of {pagination.last_page.toLocaleString()}
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            disabled={isFirstPage}
                                            onClick={() => handlePageChange(pagination.current_page - 1)}
                                        >
                                            Previous
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            disabled={isLastPage}
                                            onClick={() => handlePageChange(pagination.current_page + 1)}
                                        >
                                            Next
                                        </Button>
                                    </div>
                                </nav>
                            </>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
};

export default ResourcesPage;

export { getPrimaryTitle, getAdditionalTitles, buildDoiUrl, formatDateTime, describeLanguage, describeResourceType, describeLicense };
