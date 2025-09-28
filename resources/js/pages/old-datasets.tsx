import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Head } from '@inertiajs/react';
import { useState, useRef, useCallback } from 'react';
import axios from 'axios';

interface Dataset {
    id?: number;
    identifier?: string;
    resourcetypegeneral?: string;
    curator?: string;
    title?: string;
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
}

export default function OldDatasets({ datasets: initialDatasets, pagination: initialPagination, error }: DatasetsProps) {
    const [datasets, setDatasets] = useState<Dataset[]>(initialDatasets);
    const [pagination, setPagination] = useState<PaginationInfo>(initialPagination);
    const [loading, setLoading] = useState(false);
    const [loadingError, setLoadingError] = useState<string>('');
    const observer = useRef<IntersectionObserver | null>(null);
    
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
        } catch (err) {
            console.error('Error loading more datasets:', err);
            setLoadingError('Failed to load more datasets. Please try again.');
        } finally {
            setLoading(false);
        }
    }, [loading, pagination.current_page, pagination.per_page, pagination.has_more]);

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
    const LoadingSkeleton = () => (
        <>
            {[...Array(5)].map((_, index) => (
                <tr key={`skeleton-${index}`} className="animate-pulse">
                    <td className="px-6 py-4 whitespace-nowrap">
                        <div className="h-4 bg-gray-200 dark:bg-gray-700 rounded w-8"></div>
                    </td>
                    {getDatasetKeys().map((key) => (
                        <td key={key} className={`px-6 py-4 ${getColumnWidth(key)}`}>
                            <div className="h-4 bg-gray-200 dark:bg-gray-700 rounded"></div>
                        </td>
                    ))}
                </tr>
            ))}
        </>
    );

    const formatDate = (dateString: string | null): string => {
        if (!dateString) return 'Not available';
        try {
            const date = new Date(dateString);
            return date.toLocaleDateString('en-US', {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
            });
        } catch {
            return 'Invalid date';
        }
    };

    const formatKeyName = (key: string): string => {
        const keyMappings: { [key: string]: string } = {
            'id': 'ID',
            'identifier': 'Identifier (DOI)',
            'resourcetypegeneral': 'Resource Type',
            'curator': 'Curator',
            'title': 'Title',
            'created_at': 'Created Date',
            'updated_at': 'Updated Date',
            'publicstatus': 'Publication Status',
            'publisher': 'Publisher',
            'publicationyear': 'Publication Year',
        };

        return keyMappings[key] || key
            .replace(/_/g, ' ')
            .replace(/\b\w/g, (letter) => letter.toUpperCase());
    };

    const formatValue = (key: string, value: unknown): string => {
        if (value === null || value === undefined) return 'N/A';
        
        if (key.includes('_at') || key.includes('date')) {
            return formatDate(value as string);
        }
        
        if (key === 'title' && typeof value === 'string' && value.length > 110) {
            return value.substring(0, 107) + '...';
        }
        
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

    const getDatasetKeys = (): string[] => {
        // Define the desired column order
        return [
            'identifier',
            'title', 
            'resourcetypegeneral',
            'curator',
            'created_at',
            'updated_at',
            'publicstatus'
        ];
    };

    const getColumnWidth = (key: string): string => {
        const widthMap: { [key: string]: string } = {
            'identifier': 'w-20', // Even smaller width for DOI
            'title': 'w-96', // Even wider for title
            'resourcetypegeneral': 'w-40',
            'curator': 'w-24', // Half width for curator (first names only)
            'created_at': 'w-32',
            'updated_at': 'w-32',
            'publicstatus': 'w-28'
        };
        return widthMap[key] || 'w-32';
    };

    const keys = getDatasetKeys();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Old Datasets" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <Card>
                    <CardHeader>
                        <CardTitle>Old Datasets</CardTitle>
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
                                    "Keine Datensätze verfügbar. Überprüfen Sie die Datenbankverbindung." :
                                    "Keine Datensätze gefunden."
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
                                                {keys.map((key: string) => (
                                                    <th key={key} className={`px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider ${getColumnWidth(key)}`}>
                                                        {formatKeyName(key)}
                                                    </th>
                                                ))}
                                            </tr>
                                        </thead>
                                        <tbody className="bg-white divide-y divide-gray-200 dark:bg-gray-900 dark:divide-gray-700">
                                            {datasets.map((dataset, index) => {
                                                const isLast = index === datasets.length - 1;
                                                return (
                                                    <tr 
                                                        key={dataset.id} 
                                                        className="hover:bg-gray-50 dark:hover:bg-gray-800"
                                                        ref={isLast ? lastDatasetElementRef : null}
                                                    >
                                                        <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-gray-100 w-16">
                                                            {dataset.id}
                                                        </td>
                                                        {keys.map((key: string) => (
                                                            <td key={key} className={`px-6 py-4 text-sm text-gray-500 dark:text-gray-300 ${getColumnWidth(key)} ${key === 'title' ? '' : 'whitespace-nowrap'}`}>
                                                                {formatValue(key, dataset[key])}
                                                            </td>
                                                        ))}
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