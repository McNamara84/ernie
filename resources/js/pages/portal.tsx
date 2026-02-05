import { Head, router } from '@inertiajs/react';
import { useCallback, useState } from 'react';

import { PortalFilters } from '@/components/portal/PortalFilters';
import { PortalMap } from '@/components/portal/PortalMap';
import { PortalResultList } from '@/components/portal/PortalResultList';
import { usePortalFilters } from '@/hooks/use-portal-filters';
import PortalLayout from '@/layouts/portal-layout';
import type { PortalPageProps } from '@/types/portal';

export default function Portal({ resources, mapData, pagination, filters }: PortalPageProps) {
    const [isFilterCollapsed, setIsFilterCollapsed] = useState(false);

    const { setSearch, setType, clearFilters, hasActiveFilters } = usePortalFilters({
        filters,
        currentPage: pagination.current_page,
    });

    const handlePageChange = useCallback(
        (page: number) => {
            const params = new URLSearchParams();

            if (filters.query && filters.query.trim() !== '') {
                params.set('q', filters.query.trim());
            }

            if (filters.type && filters.type !== 'all') {
                params.set('type', filters.type);
            }

            params.set('page', String(page));

            const queryString = params.toString();
            const url = `/portal?${queryString}`;

            router.get(url, {}, { preserveState: true, preserveScroll: false });
        },
        [filters],
    );

    return (
        <PortalLayout>
            <Head title="Data Portal" />

            <div className="flex h-[calc(100vh-8rem)] flex-col">
                {/* Page Header */}
                <div className="border-b px-6 py-4">
                    <h1 className="text-2xl font-bold">Data Portal</h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Discover and explore published research datasets
                    </p>
                </div>

                {/* Main Content */}
                <div className="flex flex-1 overflow-hidden">
                    {/* Filter Sidebar */}
                    <PortalFilters
                        filters={filters}
                        onSearchChange={setSearch}
                        onTypeChange={setType}
                        onClearFilters={clearFilters}
                        hasActiveFilters={hasActiveFilters}
                        isCollapsed={isFilterCollapsed}
                        onToggleCollapse={() => setIsFilterCollapsed(!isFilterCollapsed)}
                        totalResults={pagination.total}
                    />

                    {/* Results + Map Container */}
                    <div className="flex flex-1 flex-col overflow-hidden 2xl:flex-row">
                        {/* Results List */}
                        <div className="flex flex-1 flex-col overflow-hidden 2xl:min-w-[500px]">
                            <PortalResultList
                                resources={resources}
                                pagination={pagination}
                                onPageChange={handlePageChange}
                            />
                        </div>

                        {/* Map - stacked on smaller screens, side panel on 2xl+ */}
                        <div className="border-t 2xl:border-l 2xl:border-t-0 2xl:w-[500px] 2xl:shrink-0">
                            <PortalMap resources={mapData} />
                        </div>
                    </div>
                </div>
            </div>
        </PortalLayout>
    );
}
