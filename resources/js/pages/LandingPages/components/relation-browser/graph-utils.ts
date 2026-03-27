export const LABEL_MAX_WIDTH = 14;

export function truncateLabel(label: string): string {
    if (label.length <= LABEL_MAX_WIDTH) return label;
    return label.slice(0, LABEL_MAX_WIDTH - 1) + '…';
}
