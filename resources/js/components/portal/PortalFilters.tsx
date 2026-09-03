import { Calendar, ChevronLeft, ChevronRight, Database, Filter, FlaskConical, Gem, Globe, Layers3, Network, Shapes, Tag, X } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

import { PortalClassificationFilter } from '@/components/portal/PortalClassificationFilter';
import { PortalDatacenterFilter } from '@/components/portal/PortalDatacenterFilter';
import { PortalGeoFilter } from '@/components/portal/PortalGeoFilter';
import { PortalMaterialFilter } from '@/components/portal/PortalMaterialFilter';
import { PortalResourceTypeFilter } from '@/components/portal/PortalResourceTypeFilter';
import { PortalSearchInput } from '@/components/portal/PortalSearchInput';
import { PortalTemporalFilter } from '@/components/portal/PortalTemporalFilter';
import { PortalThesaurusFilter } from '@/components/portal/PortalThesaurusFilter';
import { PortalValueFacetFilter } from '@/components/portal/PortalValueFacetFilter';
import { Accordion, AccordionContent, AccordionItem, AccordionTrigger } from '@/components/ui/accordion';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ScrollArea } from '@/components/ui/scroll-area';
import { cn } from '@/lib/utils';
import type {
    DatacenterFacet,
    GeoBounds,
    PortalBasePath,
    PortalFilters as PortalFilterValues,
    PortalIgsnFacets,
    PortalThesaurusFacet,
    ResourceTypeFacet,
    TemporalFilterValue,
    TemporalRange,
} from '@/types/portal';

interface PortalFiltersProps {
    basePath?: PortalBasePath;
    filters: PortalFilterValues;
    searchValue: string;
    onSearchValueChange: (query: string) => void;
    onSearchChange: (query: string) => void;
    onKeywordSelect: (keyword: string) => void;
    onTypeChange: (type: string[]) => void;
    onDatacenterChange: (datacenter: string[]) => void;
    onKeywordsChange: (keywords: string[]) => void;
    onThesaurusKeywordsChange?: (nodeIds: string[]) => void;
    onSampleTypesChange?: (values: string[]) => void;
    onMaterialsChange?: (values: string[]) => void;
    onClassificationsChange?: (values: string[]) => void;
    onGeologicalAgesChange?: (values: string[]) => void;
    onGeologicalUnitsChange?: (values: string[]) => void;
    onClearFilters: () => void;
    hasActiveFilters: boolean;
    isCollapsed: boolean;
    onToggleCollapse: () => void;
    showCollapseButton?: boolean;
    className?: string;
    thesaurusFacets?: PortalThesaurusFacet[];
    igsnFacets?: PortalIgsnFacets | null;
    geoFilterEnabled: boolean;
    onGeoFilterToggle: (enabled: boolean) => void;
    onBoundsChange: (bounds: GeoBounds | null) => void;
    temporalRange: TemporalRange;
    temporalFilterEnabled: boolean;
    onTemporalFilterToggle: (enabled: boolean) => void;
    onTemporalChange: (temporal: TemporalFilterValue | null) => void;
    resourceTypeFacets: ResourceTypeFacet[];
    datacenterFacets: DatacenterFacet[];
    showResourceTypeFilter?: boolean;
}

type FilterSection =
    | 'thesaurus'
    | 'sample-type'
    | 'material'
    | 'classification'
    | 'geological-age'
    | 'geological-unit'
    | 'temporal'
    | 'geographic'
    | 'resource-type'
    | 'datacenter';

function CountBadge({ count }: { count: number }) {
    return count > 0 ? (
        <Badge variant="secondary" className="mr-2 ml-auto h-5 min-w-5 justify-center px-1 text-xs">
            {count}
        </Badge>
    ) : null;
}

export function PortalFilters({
    basePath = '/doi-search',
    filters,
    searchValue,
    onSearchValueChange,
    onSearchChange,
    onKeywordSelect,
    onTypeChange,
    onDatacenterChange,
    onKeywordsChange,
    onThesaurusKeywordsChange = () => undefined,
    onSampleTypesChange = () => undefined,
    onMaterialsChange = () => undefined,
    onClassificationsChange = () => undefined,
    onGeologicalAgesChange = () => undefined,
    onGeologicalUnitsChange = () => undefined,
    onClearFilters,
    hasActiveFilters,
    isCollapsed,
    onToggleCollapse,
    showCollapseButton = true,
    className,
    thesaurusFacets = [],
    igsnFacets = null,
    geoFilterEnabled,
    onGeoFilterToggle,
    onBoundsChange,
    temporalRange,
    temporalFilterEnabled,
    onTemporalFilterToggle,
    onTemporalChange,
    resourceTypeFacets,
    datacenterFacets,
    showResourceTypeFilter = true,
}: PortalFiltersProps) {
    const isIgsnPortal = basePath === '/igsn-search';
    const selectedKeywordValues = (filters.freeKeywords?.length ?? 0) > 0 ? (filters.freeKeywords ?? []) : filters.keywords;
    const activeSections = useMemo<FilterSection[]>(() => {
        const active: FilterSection[] = [];
        if (!isIgsnPortal && (filters.thesaurusKeywords?.length ?? 0) > 0) active.push('thesaurus');
        if (isIgsnPortal && (filters.sampleTypes?.length ?? 0) > 0) active.push('sample-type');
        if (isIgsnPortal && (filters.materials?.length ?? 0) > 0) active.push('material');
        if (isIgsnPortal && (filters.classifications?.length ?? 0) > 0) active.push('classification');
        if (isIgsnPortal && (filters.geologicalAges?.length ?? 0) > 0) active.push('geological-age');
        if (isIgsnPortal && (filters.geologicalUnits?.length ?? 0) > 0) active.push('geological-unit');
        if (temporalFilterEnabled) active.push('temporal');
        if (geoFilterEnabled) active.push('geographic');
        if (!isIgsnPortal && (filters.type.length > 0 || filters.exclude_type)) active.push('resource-type');
        if (filters.datacenter.length > 0) active.push('datacenter');
        return active;
    }, [
        filters.classifications?.length,
        filters.datacenter.length,
        filters.exclude_type,
        filters.geologicalAges?.length,
        filters.geologicalUnits?.length,
        filters.materials?.length,
        filters.sampleTypes?.length,
        filters.thesaurusKeywords,
        filters.type.length,
        geoFilterEnabled,
        isIgsnPortal,
        temporalFilterEnabled,
    ]);
    const [openSections, setOpenSections] = useState<FilterSection[]>(() =>
        Array.from(new Set<FilterSection>([isIgsnPortal ? 'sample-type' : 'thesaurus', ...activeSections])),
    );

    useEffect(() => {
        if (activeSections.length === 0) return;
        setOpenSections((current) => Array.from(new Set([...current, ...activeSections])));
    }, [activeSections]);

    if (isCollapsed) {
        return (
            <aside
                className="flex h-full w-12 shrink-0 flex-col items-center border-r bg-muted/30 py-3"
                aria-label="Filters"
                data-testid="portal-filter-sidebar"
            >
                <Button variant="ghost" size="icon" onClick={onToggleCollapse} aria-label="Expand filters">
                    <ChevronRight className="h-4 w-4" />
                </Button>
                <Filter className="mt-4 h-5 w-5 text-muted-foreground" />
                {hasActiveFilters && (
                    <span role="status" aria-label="Filters active" className="mt-3 h-2 w-2 rounded-full bg-primary" title="Filters active" />
                )}
            </aside>
        );
    }

    return (
        <aside
            className={cn('flex h-full w-72 shrink-0 flex-col overflow-hidden border-r bg-muted/30', className)}
            aria-label="Filters"
            data-testid="portal-filter-sidebar"
        >
            <div className="flex h-12 shrink-0 items-center justify-between border-b px-3">
                <div className="flex items-center gap-2">
                    <Filter className="h-4 w-4" />
                    <span className="font-semibold">Filters</span>
                    {hasActiveFilters && <span className="h-2 w-2 rounded-full bg-primary" aria-hidden="true" />}
                </div>
                <div className="flex items-center gap-1">
                    {hasActiveFilters && (
                        <Button variant="ghost" size="sm" onClick={onClearFilters} className="h-8 px-2 text-xs" aria-label="Clear all filters">
                            <X className="mr-1 h-3.5 w-3.5" /> Clear
                        </Button>
                    )}
                    {showCollapseButton && (
                        <Button variant="ghost" size="icon" className="h-8 w-8" onClick={onToggleCollapse} aria-label="Collapse filters">
                            <ChevronLeft className="h-4 w-4" />
                        </Button>
                    )}
                </div>
            </div>

            <div className="relative z-20 shrink-0 border-b bg-background/95 p-3 backdrop-blur">
                <PortalSearchInput
                    basePath={basePath}
                    value={searchValue}
                    onValueChange={onSearchValueChange}
                    onSubmit={onSearchChange}
                    selectedKeywords={selectedKeywordValues}
                    onKeywordSelect={onKeywordSelect}
                    onKeywordsChange={onKeywordsChange}
                />
            </div>

            <ScrollArea className="min-h-0 flex-1">
                <Accordion
                    type="multiple"
                    value={openSections}
                    onValueChange={(values) => setOpenSections(values as FilterSection[])}
                    className="px-3"
                >
                    {!isIgsnPortal && (
                        <AccordionItem value="thesaurus">
                            <AccordionTrigger className="items-center py-3 hover:no-underline">
                                <span className="flex min-w-0 items-center gap-2">
                                    <Network className="h-4 w-4" />
                                    Thesaurus Keywords
                                </span>
                                <CountBadge count={filters.thesaurusKeywords?.length ?? 0} />
                            </AccordionTrigger>
                            <AccordionContent>
                                <PortalThesaurusFilter
                                    hideTitle
                                    facets={thesaurusFacets}
                                    selectedNodeIds={filters.thesaurusKeywords ?? []}
                                    onSelectionChange={onThesaurusKeywordsChange}
                                />
                            </AccordionContent>
                        </AccordionItem>
                    )}

                    {isIgsnPortal && (
                        <>
                            <AccordionItem value="sample-type">
                                <AccordionTrigger className="items-center py-3 hover:no-underline">
                                    <span className="flex min-w-0 items-center gap-2">
                                        <Tag className="h-4 w-4" />
                                        Sample Type
                                    </span>
                                    <CountBadge count={filters.sampleTypes?.length ?? 0} />
                                </AccordionTrigger>
                                <AccordionContent>
                                    <PortalValueFacetFilter
                                        options={igsnFacets?.sampleTypes ?? []}
                                        selectedValues={filters.sampleTypes ?? []}
                                        onSelectionChange={onSampleTypesChange}
                                        ariaLabel="Sample types"
                                        emptyMessage="No sample types available."
                                        helperText="A sample may match any selected sample type."
                                    />
                                </AccordionContent>
                            </AccordionItem>

                            <AccordionItem value="material">
                                <AccordionTrigger className="items-center py-3 hover:no-underline">
                                    <span className="flex min-w-0 items-center gap-2">
                                        <FlaskConical className="h-4 w-4" />
                                        Material
                                    </span>
                                    <CountBadge count={filters.materials?.length ?? 0} />
                                </AccordionTrigger>
                                <AccordionContent>
                                    <PortalMaterialFilter
                                        facets={igsnFacets?.materials ?? []}
                                        selectedValues={filters.materials ?? []}
                                        onSelectionChange={onMaterialsChange}
                                    />
                                </AccordionContent>
                            </AccordionItem>

                            <AccordionItem value="classification">
                                <AccordionTrigger className="items-center py-3 hover:no-underline">
                                    <span className="flex min-w-0 items-center gap-2">
                                        <Gem className="h-4 w-4" />
                                        Classification
                                    </span>
                                    <CountBadge count={filters.classifications?.length ?? 0} />
                                </AccordionTrigger>
                                <AccordionContent>
                                    <PortalClassificationFilter
                                        groups={igsnFacets?.classifications ?? []}
                                        selectedValues={filters.classifications ?? []}
                                        onSelectionChange={onClassificationsChange}
                                    />
                                </AccordionContent>
                            </AccordionItem>

                            <AccordionItem value="geological-age">
                                <AccordionTrigger className="items-center py-3 hover:no-underline">
                                    <span className="flex min-w-0 items-center gap-2">
                                        <Calendar className="h-4 w-4" />
                                        Geological Age
                                    </span>
                                    <CountBadge count={filters.geologicalAges?.length ?? 0} />
                                </AccordionTrigger>
                                <AccordionContent>
                                    <PortalValueFacetFilter
                                        options={igsnFacets?.geologicalAges ?? []}
                                        selectedValues={filters.geologicalAges ?? []}
                                        onSelectionChange={onGeologicalAgesChange}
                                        ariaLabel="Geological ages"
                                        emptyMessage="No geological ages available."
                                        helperText="Every selected geological age must be present."
                                        searchable
                                        searchPlaceholder="Search geological ages..."
                                    />
                                </AccordionContent>
                            </AccordionItem>

                            <AccordionItem value="geological-unit">
                                <AccordionTrigger className="items-center py-3 hover:no-underline">
                                    <span className="flex min-w-0 items-center gap-2">
                                        <Layers3 className="h-4 w-4" />
                                        Geological Unit
                                    </span>
                                    <CountBadge count={filters.geologicalUnits?.length ?? 0} />
                                </AccordionTrigger>
                                <AccordionContent>
                                    <PortalValueFacetFilter
                                        options={igsnFacets?.geologicalUnits ?? []}
                                        selectedValues={filters.geologicalUnits ?? []}
                                        onSelectionChange={onGeologicalUnitsChange}
                                        ariaLabel="Geological units"
                                        emptyMessage="No geological units available."
                                        helperText="Every selected geological unit must be present."
                                        searchable
                                        searchPlaceholder="Search geological units..."
                                    />
                                </AccordionContent>
                            </AccordionItem>
                        </>
                    )}

                    <AccordionItem value="temporal">
                        <AccordionTrigger className="items-center py-3 hover:no-underline">
                            <span className="flex items-center gap-2">
                                <Calendar className="h-4 w-4" />
                                Time
                            </span>
                            <CountBadge count={temporalFilterEnabled ? 1 : 0} />
                        </AccordionTrigger>
                        <AccordionContent>
                            <PortalTemporalFilter
                                hideTitle
                                enabled={temporalFilterEnabled}
                                onToggle={onTemporalFilterToggle}
                                temporalRange={temporalRange}
                                temporal={filters.temporal}
                                onTemporalChange={onTemporalChange}
                            />
                        </AccordionContent>
                    </AccordionItem>

                    <AccordionItem value="geographic">
                        <AccordionTrigger className="items-center py-3 hover:no-underline">
                            <span className="flex items-center gap-2">
                                <Globe className="h-4 w-4" />
                                Location
                            </span>
                            <CountBadge count={geoFilterEnabled ? 1 : 0} />
                        </AccordionTrigger>
                        <AccordionContent>
                            <PortalGeoFilter
                                hideTitle
                                enabled={geoFilterEnabled}
                                onToggle={onGeoFilterToggle}
                                bounds={filters.bounds}
                                onBoundsChange={onBoundsChange}
                            />
                        </AccordionContent>
                    </AccordionItem>

                    {showResourceTypeFilter && !isIgsnPortal && (
                        <AccordionItem value="resource-type">
                            <AccordionTrigger className="items-center py-3 hover:no-underline">
                                <span className="flex items-center gap-2">
                                    <Shapes className="h-4 w-4" />
                                    Resource Type
                                </span>
                                <CountBadge count={filters.type.length > 0 ? filters.type.length : filters.exclude_type ? 1 : 0} />
                            </AccordionTrigger>
                            <AccordionContent>
                                <PortalResourceTypeFilter
                                    facets={resourceTypeFacets}
                                    selectedSlugs={filters.type}
                                    excludeType={filters.exclude_type}
                                    onSelectionChange={onTypeChange}
                                />
                            </AccordionContent>
                        </AccordionItem>
                    )}

                    <AccordionItem value="datacenter">
                        <AccordionTrigger className="items-center py-3 hover:no-underline">
                            <span className="flex items-center gap-2">
                                <Database className="h-4 w-4" />
                                Datacenter
                            </span>
                            <CountBadge count={filters.datacenter.length} />
                        </AccordionTrigger>
                        <AccordionContent>
                            <PortalDatacenterFilter
                                facets={datacenterFacets}
                                selectedNames={filters.datacenter}
                                onSelectionChange={onDatacenterChange}
                            />
                        </AccordionContent>
                    </AccordionItem>
                </Accordion>
            </ScrollArea>
        </aside>
    );
}
