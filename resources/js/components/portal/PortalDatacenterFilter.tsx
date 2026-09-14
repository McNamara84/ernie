import { useMemo } from 'react';

import { PortalValueFacetFilter } from '@/components/portal/PortalValueFacetFilter';
import type { DatacenterFacet } from '@/types/portal';

interface PortalDatacenterFilterProps {
    facets: DatacenterFacet[];
    selectedNames: string[];
    onSelectionChange: (names: string[]) => void;
}

export function PortalDatacenterFilter({ facets, selectedNames, onSelectionChange }: PortalDatacenterFilterProps) {
    const options = useMemo(() => facets.map((facet) => ({ value: facet.name, label: facet.name, count: facet.count })), [facets]);

    return (
        <PortalValueFacetFilter
            options={options}
            selectedValues={selectedNames}
            onSelectionChange={onSelectionChange}
            ariaLabel="Datacenters"
            emptyMessage="No datacenters available."
            helperText="A result may match any selected datacenter."
            searchable
            searchPlaceholder="Search datacenters..."
        />
    );
}
