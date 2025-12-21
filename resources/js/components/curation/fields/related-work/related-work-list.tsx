import type { RelatedIdentifier } from '@/types';

import RelatedWorkItem from './related-work-item';

interface RelatedWorkListProps {
    items: RelatedIdentifier[];
    onRemove: (index: number) => void;
    validationStatuses?: Map<
        number,
        {
            status: 'validating' | 'valid' | 'invalid' | 'warning';
            message?: string;
        }
    >;
}

/**
 * RelatedWorkList Component
 *
 * Displays a list of related work items.
 * Items are shown in the order they were added (by position).
 */
export default function RelatedWorkList({ items, onRemove, validationStatuses }: RelatedWorkListProps) {
    if (items.length === 0) {
        return null;
    }

    return (
        <div className="space-y-3">
            <div className="flex items-center justify-between">
                <h4 className="text-sm font-medium text-foreground">Added Relations ({items.length})</h4>
                {items.length > 0 && <span className="text-xs text-muted-foreground">Ordered by addition</span>}
            </div>

            <div className="space-y-2" role="list" aria-label="Related works">
                {items.map((item, index) => {
                    const validation = validationStatuses?.get(index);

                    return (
                        <div key={`${item.identifier}-${index}`} role="listitem">
                            <RelatedWorkItem
                                item={item}
                                index={index}
                                onRemove={onRemove}
                                validationStatus={validation?.status}
                                validationMessage={validation?.message}
                            />
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
