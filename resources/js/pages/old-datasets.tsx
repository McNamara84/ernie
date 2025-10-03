import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { curation as curationRoute } from '@/routes';
import { Head, router } from '@inertiajs/react';
import { useState, useRef, useCallback, useEffect } from 'react';
import type { ReactNode } from 'react';
import { ArrowUpRight } from 'lucide-react';
import axios, { isAxiosError } from 'axios';

interface Dataset {
    id?: number;
    identifier?: string;
    resourcetypegeneral?: string;
    curator?: string;
    title?: string;
    titleType?: string;
    title_type?: string;
    titles?: { title?: string | null; titleType?: string | null; title_type?: string | null }[];
    licenses?: (string | { identifier?: string | null; rightsIdentifier?: string | null; license?: string | null })[];
    license?: string;
    version?: string;
    language?: string;
    resourcetype?: string | number;
    resourcetypeid?: string | number;
    resource_type_id?: string | number;
    resourceTypeId?: string | number;
    created_at?: string;
    updated_at?: string;
    publicstatus?: string;
    publisher?: string;
    publicationyear?: number;
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    [key: string]: any;
}

interface PaginationInfo {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number;
    to: number;
    has_more: boolean;
}

interface DatasetsProps {
    datasets: Dataset[];
    pagination: PaginationInfo;
    error?: string;
    debug?: Record<string, unknown>;
}

interface DatasetColumn {
    key: string;
    label: ReactNode;
    widthClass: string;
    cellClassName?: string;
    render?: (dataset: Dataset) => React.ReactNode;
}

const TITLE_COLUMN_WIDTH_CLASSES = 'min-w-[20rem] lg:min-w-[28rem] xl:min-w-[32rem]';
const TITLE_COLUMN_CELL_CLASSES = 'whitespace-normal break-words text-gray-900 dark:text-gray-100 leading-relaxed align-top';
const DATE_COLUMN_CONTAINER_CLASSES = 'flex flex-col gap-1 text-left text-gray-600 dark:text-gray-300';
const DATE_COLUMN_HEADER_LABEL = (
    <span className="flex flex-col leading-tight normal-case">
        <span>Created</span>
        <span>Updated</span>
    </span>
);
const ACTIONS_COLUMN_WIDTH_CLASSES = 'w-24 min-w-[6rem]';

type DateType = 'Created' | 'Updated';
type DateDetails = { label: string; iso: string | null };

interface NormalisedTitle {
    title: string;
    titleType: string;
}

const NORMALISED_MAIN_TITLE = 'main-title';

const normaliseTitleType = (value: string | null | undefined): string => {
    if (!value) {
        return NORMALISED_MAIN_TITLE;
    }

    const trimmed = value.trim();

    if (!trimmed) {
        return NORMALISED_MAIN_TITLE;
    }

    return trimmed
        .toLowerCase()
        .replace(/[^a-z0-9\s-]/gi, '')
        .replace(/\s+/g, '-')
        .replace(/-+/g, '-');
};

const normaliseTitles = (dataset: Dataset): NormalisedTitle[] => {
    const titles: NormalisedTitle[] = [];

    if (Array.isArray(dataset.titles)) {
        dataset.titles.forEach((raw) => {
            if (typeof raw === 'string') {
                const text = raw.trim();
                if (text) {
                    titles.push({ title: text, titleType: NORMALISED_MAIN_TITLE });
                }
                return;
            }

            if (!raw) return;

            const value = raw.title ?? null;
            const titleText = typeof value === 'string' ? value.trim() : '';

            if (!titleText) return;

            const typeValue = normaliseTitleType(raw.titleType ?? raw.title_type ?? null);
            titles.push({ title: titleText, titleType: typeValue });
        });
    }

    const fallbackTitle = typeof dataset.title === 'string' ? dataset.title.trim() : '';

    if (fallbackTitle) {
        const fallbackType = normaliseTitleType(dataset.titleType ?? dataset.title_type ?? null);
        titles.push({ title: fallbackTitle, titleType: fallbackType });
    }

    const mainTitles = titles.filter((entry) => entry.titleType === NORMALISED_MAIN_TITLE);
    const secondaryTitles = titles.filter((entry) => entry.titleType !== NORMALISED_MAIN_TITLE);

    return [...mainTitles, ...secondaryTitles];
};

const normaliseLicenses = (dataset: Dataset): string[] => {
    const licenses: string[] = [];

    const appendLicense = (value: unknown) => {
        if (typeof value === 'string') {
            const trimmed = value.trim();
            if (trimmed) {
                licenses.push(trimmed);
            }
            return;
        }

        if (typeof value === 'object' && value !== null) {
            const candidate =
                'identifier' in value
                    ? value.identifier
                    : 'rightsIdentifier' in value
                        ? value.rightsIdentifier
                        : 'license' in value
                            ? value.license
                            : null;

            if (typeof candidate === 'string') {
                const trimmed = candidate.trim();
                if (trimmed) {
                    licenses.push(trimmed);
                }
            }
        }
    };

    if (Array.isArray(dataset.licenses)) {
        dataset.licenses.forEach(appendLicense);
    }

    appendLicense(dataset.license ?? null);

    return licenses;
};

const getResourceTypeIdentifier = (dataset: Dataset): string | null => {
    const candidates = [
        dataset.resourceTypeId,
        dataset.resource_type_id,
        dataset.resourcetypeid,
        dataset.resourcetype,
    ];

    for (const candidate of candidates) {
        if (candidate === null || candidate === undefined) {
            continue;
        }

        if (typeof candidate === 'number') {
            return String(candidate);
        }

        if (typeof candidate === 'string') {
            const trimmed = candidate.trim();
            if (!trimmed) continue;
            if (/^\d+$/.test(trimmed)) {
                return trimmed;
            }
        }
    }

    return null;
};

const buildCurationQuery = (dataset: Dataset): Record<string, string> => {
    const query: Record<string, string> = {};

    if (dataset.identifier) {
        query.doi = dataset.identifier;
    }

    if (dataset.publicationyear !== undefined && dataset.publicationyear !== null) {
        query.year = String(dataset.publicationyear);
    }

    if (dataset.version) {
        query.version = dataset.version;
    }

    if (dataset.language) {
        query.language = dataset.language;
    }

    const resourceType = getResourceTypeIdentifier(dataset);
    if (resourceType) {
        query.resourceType = resourceType;
    }

    const titles = normaliseTitles(dataset);
    titles.forEach((title, index) => {
        query[`titles[${index}][title]`] = title.title;
        query[`titles[${index}][titleType]`] = title.titleType;
    });

    const licenses = normaliseLicenses(dataset);
    licenses.forEach((license, index) => {
        query[`licenses[${index}]`] = license;
    });

    return query;
};

const renderDateContent = (details: DateDetails): ReactNode => {
    if (details.iso) {
        return (
            <time dateTime={details.iso} className="font-medium">
                {details.label}
            </time>
        );
    }

    return <span className="text-gray-600 dark:text-gray-300">{details.label}</span>;
};

const describeDate = (
    label: string,
    iso: string | null,
    rawValue: string | undefined,
    dateType: DateType,
): string | null => {
    if (iso) {
        return `${dateType} on ${label}`;
    }

    if (!rawValue) {
        return `${dateType} date not available`;
    }

    if (label === 'Invalid date') {
        return `${dateType} date is invalid`;
    }

    return null;
};

export default function OldDatasets({ datasets: initialDatasets, pagination: initialPagination, error, debug }: DatasetsProps) {
    const [datasets, setDatasets] = useState<Dataset[]>(initialDatasets);
    const [pagination, setPagination] = useState<PaginationInfo>(initialPagination);
    const [loading, setLoading] = useState(false);
    const [loadingError, setLoadingError] = useState<string>('');
    const observer = useRef<IntersectionObserver | null>(null);

    const handleOpenInCuration = useCallback((dataset: Dataset) => {
        const query = buildCurationQuery(dataset);
        router.get(curationRoute({ query }).url);
    }, []);

    const logDebugInformation = useCallback((source: string, message: string | undefined, payload?: Record<string, unknown>) => {
        if (!payload || Object.keys(payload).length === 0) {
            return;
        }

        const title = `SUMARIOPMD diagnostics – ${source}`;

        if (typeof console.groupCollapsed === 'function') {
            console.groupCollapsed(title);
        } else {
            console.info(title);
        }

        if (message) {
            console.info('Message:', message);
        }

        console.info('Details:', payload);

        if (typeof console.groupEnd === 'function') {
            console.groupEnd();
        }
    }, []);

    useEffect(() => {
        if (error) {
            logDebugInformation('initial page load', error, debug);
        }
    }, [debug, error, logDebugInformation]);
    
    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Old Datasets',
            href: '/old-datasets',
        },
    ];

    const loadMoreDatasets = useCallback(async () => {
        if (loading || !pagination.has_more) return;
        
        setLoading(true);
        setLoadingError('');
        
        try {
            const response = await axios.get('/old-datasets/load-more', {
                params: {
                    page: pagination.current_page + 1,
                    per_page: pagination.per_page,
                },
            });

            if (response.data.datasets) {
                setDatasets(prev => [...prev, ...response.data.datasets]);
                setPagination(response.data.pagination);
            }
        } catch (err: unknown) {
            console.error('Error loading more datasets:', err);

            if (isAxiosError(err)) {
                const debugPayload = err.response?.data?.debug as Record<string, unknown> | undefined;
                const errorMessage = err.message || err.response?.data?.error;
                logDebugInformation('load more request', errorMessage, debugPayload);
            }

            setLoadingError('Failed to load more datasets. Please try again.');
        } finally {
            setLoading(false);
        }
    }, [loading, pagination.current_page, pagination.per_page, pagination.has_more, logDebugInformation]);

    // Reference to the last dataset element for intersection observer
    const lastDatasetElementRef = useCallback((node: HTMLElement | null) => {
        if (loading) return;
        if (observer.current) observer.current.disconnect();
        observer.current = new IntersectionObserver(entries => {
            if (entries[0].isIntersecting && pagination.has_more) {
                loadMoreDatasets();
            }
        });
        if (node) observer.current.observe(node);
    }, [loading, pagination.has_more, loadMoreDatasets]);

    // Loading skeleton component
    const getDateDetails = (dateString: string | null): DateDetails => {
        if (!dateString) {
            return { label: 'Not available', iso: null };
        }

        try {
            const date = new Date(dateString);
            if (Number.isNaN(date.getTime())) {
                return { label: 'Invalid date', iso: null };
            }

            return {
                label: date.toLocaleDateString('en-US', {
                    year: 'numeric',
                    month: '2-digit',
                    day: '2-digit',
                }),
                iso: date.toISOString(),
            };
        } catch {
            return { label: 'Invalid date', iso: null };
        }
    };

    const formatValue = (key: string, value: unknown): string => {
        if (value === null || value === undefined) return 'N/A';

        if (key === 'publicstatus') {
            const statusMap: { [key: string]: string } = {
                'published': 'Published',
                'draft': 'Draft',
                'review': 'Under Review',
                'archived': 'Archived',
            };
            return statusMap[value as string] || String(value);
        }
        
        return String(value);
    };
    const datasetColumns: DatasetColumn[] = [
        {
            key: 'identifier',
            label: 'Identifier (DOI)',
            widthClass: 'min-w-[8rem]',
            cellClassName: 'whitespace-nowrap',
        },
        {
            key: 'title',
            label: 'Title',
            widthClass: TITLE_COLUMN_WIDTH_CLASSES,
            cellClassName: TITLE_COLUMN_CELL_CLASSES,
        },
        {
            key: 'resourcetypegeneral',
            label: 'Resource Type',
            widthClass: 'min-w-[10rem]',
            cellClassName: 'whitespace-nowrap',
        },
        {
            key: 'curator',
            label: 'Curator',
            widthClass: 'min-w-[7rem]',
            cellClassName: 'whitespace-nowrap',
        },
        {
            key: 'created_updated',
            label: DATE_COLUMN_HEADER_LABEL,
            widthClass: 'min-w-[9rem]',
            cellClassName: 'whitespace-normal align-top',
            render: (dataset: Dataset) => {
                const createdDetails = getDateDetails(dataset.created_at ?? null);
                const updatedDetails = getDateDetails(dataset.updated_at ?? null);

                const ariaLabelParts = [
                    describeDate(createdDetails.label, createdDetails.iso, dataset.created_at, 'Created'),
                    describeDate(updatedDetails.label, updatedDetails.iso, dataset.updated_at, 'Updated'),
                ].filter((part): part is string => part !== null);

                const dateColumnAriaLabel = ariaLabelParts.length > 0 ? ariaLabelParts.join('. ') : undefined;

                return (
                    <div
                        className={DATE_COLUMN_CONTAINER_CLASSES}
                        aria-label={dateColumnAriaLabel}
                    >
                        {renderDateContent(createdDetails)}
                        {renderDateContent(updatedDetails)}
                    </div>
                );
            },
        },
        {
            key: 'publicstatus',
            label: 'Publication Status',
            widthClass: 'min-w-[10rem]',
            cellClassName: 'whitespace-nowrap',
        },
    ];

    const LoadingSkeleton = () => (
        <>
            {[...Array(5)].map((_, index) => (
                <tr key={`skeleton-${index}`} className="animate-pulse">
                    <td className="px-6 py-4 whitespace-nowrap">
                        <div className="h-4 w-8 rounded bg-gray-200 dark:bg-gray-700"></div>
                    </td>
                    {datasetColumns.map((column) => (
                        <td key={column.key} className={`px-6 py-4 ${column.widthClass} ${column.cellClassName ?? ''}`}>
                            {column.key === 'created_updated' ? (
                                <div className="flex flex-col gap-2">
                                    <div className="h-4 w-28 rounded bg-gray-200 dark:bg-gray-700"></div>
                                    <div className="h-4 w-32 rounded bg-gray-200 dark:bg-gray-700"></div>
                                </div>
                            ) : (
                                <div className="h-4 w-3/4 rounded bg-gray-200 dark:bg-gray-700"></div>
                            )}
                        </td>
                    ))}
                    <td className={`px-6 py-4 ${ACTIONS_COLUMN_WIDTH_CLASSES}`}>
                        <div className="size-9 rounded-full bg-gray-200 dark:bg-gray-700" />
                    </td>
                </tr>
            ))}
        </>
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Old Datasets" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <Card>
                    <CardHeader>
                        <CardTitle asChild>
                            <h1 className="text-2xl font-semibold tracking-tight">Old Datasets</h1>
                        </CardTitle>
                        <CardDescription>
                            Overview of legacy resources from the SUMARIOPMD database
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {error ? (
                            <Alert className="mb-4" variant="destructive">
                                <AlertDescription>
                                    {error}
                                </AlertDescription>
                            </Alert>
                        ) : null}

                        {datasets.length === 0 ? (
                            <div className="text-center py-8 text-muted-foreground">
                                {error ? 
                                    "No datasets available. Please check the database connection." :
                                    "No old datasets found."
                                }
                            </div>
                        ) : (
                            <>
                                <div className="mb-4 flex items-center gap-2">
                                    <Badge variant="secondary">
                                        1-{datasets.length} of {pagination.total} datasets
                                    </Badge>
                                </div>
                                
                                <div className="overflow-x-auto">
                                    <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                        <thead className="bg-gray-50 dark:bg-gray-800">
                                            <tr>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider w-16">
                                                    ID
                                                </th>
                                                {datasetColumns.map((column) => (
                                                    <th
                                                        key={column.key}
                                                        className={`px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300 ${column.widthClass}`}
                                                    >
                                                        {column.label}
                                                    </th>
                                                ))}
                                                <th
                                                    className={`px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300 ${ACTIONS_COLUMN_WIDTH_CLASSES}`}
                                                >
                                                    Actions
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="bg-white divide-y divide-gray-200 dark:bg-gray-900 dark:divide-gray-700">
                                            {datasets.map((dataset, index) => {
                                                const isLast = index === datasets.length - 1;
                                                const datasetLabel =
                                                    dataset.identifier ??
                                                    dataset.title ??
                                                    (dataset.id !== undefined ? `#${dataset.id}` : 'entry');
                                                return (
                                                    <tr
                                                        key={dataset.id ?? dataset.identifier ?? `dataset-${index}`}
                                                        className="hover:bg-gray-50 dark:hover:bg-gray-800"
                                                        ref={isLast ? lastDatasetElementRef : null}
                                                    >
                                                        <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-gray-100 w-16">
                                                            {dataset.id}
                                                        </td>
                                                        {datasetColumns.map((column) => (
                                                            <td
                                                                key={column.key}
                                                                className={`px-6 py-4 text-sm text-gray-500 dark:text-gray-300 ${column.widthClass} ${column.cellClassName ?? ''}`}
                                                            >
                                                                {column.render
                                                                    ? column.render(dataset)
                                                                    : formatValue(column.key, dataset[column.key])}
                                                            </td>
                                                        ))}
                                                        <td className={`px-6 py-4 text-sm text-gray-500 dark:text-gray-300 ${ACTIONS_COLUMN_WIDTH_CLASSES}`}>
                                                            <Button
                                                                type="button"
                                                                variant="ghost"
                                                                size="icon"
                                                                onClick={() => handleOpenInCuration(dataset)}
                                                                aria-label={`Open dataset ${datasetLabel} in curation form`}
                                                                title={`Open dataset ${datasetLabel} in curation form`}
                                                            >
                                                                <ArrowUpRight aria-hidden="true" className="size-4" />
                                                            </Button>
                                                        </td>
                                                    </tr>
                                                );
                                            })}
                                            {loading && <LoadingSkeleton />}
                                        </tbody>
                                    </table>
                                </div>

                                {loadingError && (
                                    <Alert className="mt-4" variant="destructive">
                                        <AlertDescription>
                                            {loadingError}
                                            <Button 
                                                variant="outline" 
                                                size="sm" 
                                                className="ml-2"
                                                onClick={loadMoreDatasets}
                                            >
                                                Retry
                                            </Button>
                                        </AlertDescription>
                                    </Alert>
                                )}

                                {!loading && !pagination.has_more && datasets.length > 0 && (
                                    <div className="text-center py-4 text-muted-foreground text-sm">
                                        All datasets have been loaded ({pagination.total} total)
                                    </div>
                                )}
                            </>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}