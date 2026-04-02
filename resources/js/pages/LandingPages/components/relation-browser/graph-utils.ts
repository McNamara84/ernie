export const LABEL_MAX_WIDTH = 14;

export function truncateLabel(label: string): string {
    if (label.length <= LABEL_MAX_WIDTH) return label;
    return label.slice(0, LABEL_MAX_WIDTH - 1) + '…';
}

/**
 * Convert a PascalCase contributor type to a human-readable label.
 * e.g. "DataCollector" → "Data Collector", "HostingInstitution" → "Hosting Institution"
 */
export function humanizeContributorType(type: string): string {
    return type.replace(/([A-Z])/g, ' $1').trim();
}
