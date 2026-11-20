import type { RelatedIdentifier } from '@/types';

const citationLabelCollator = new Intl.Collator('en', { usage: 'sort', sensitivity: 'base', numeric: true });

/** Return display indices without modifying the stored order or label values. */
export function getRelatedWorkDisplayOrder(items: readonly RelatedIdentifier[]): number[] {
    return items
        .map((item, index) => ({ index, label: (item.citation_label ?? '').trim() }))
        .sort((left, right) => {
            if (left.label === '' && right.label !== '') return -1;
            if (left.label !== '' && right.label === '') return 1;
            return citationLabelCollator.compare(left.label, right.label) || left.index - right.index;
        })
        .map(({ index }) => index);
}
