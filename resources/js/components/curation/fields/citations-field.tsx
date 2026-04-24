import { Quote } from 'lucide-react';
import { useState } from 'react';

import { CitationManagerModal } from '@/components/citations/CitationManagerModal';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { useCitationVocabularies } from '@/hooks/use-citation-vocabularies';
import { useRelatedItems } from '@/hooks/use-related-items';

interface CitationsFieldProps {
    resourceId: number | null;
}

/**
 * Curation form field for DataCite 4.7 `relatedItem` entries.
 *
 * Related items carry full inline metadata (authors, titles, year, pages, …)
 * and can only be managed once the resource has been persisted because the
 * backend REST endpoints are scoped to `/resources/{id}/related-items`.
 */
export function CitationsField({ resourceId }: CitationsFieldProps) {
    const [modalOpen, setModalOpen] = useState(false);
    const { vocabularies, isLoading: vocabLoading } = useCitationVocabularies();
    const { items, isLoading: itemsLoading } = useRelatedItems(resourceId ?? 0);

    if (resourceId === null) {
        return (
            <Alert>
                <AlertDescription>
                    Save the dataset first to manage its citations (DataCite <code>relatedItem</code>).
                </AlertDescription>
            </Alert>
        );
    }

    const count = items.length;

    return (
        <div className="space-y-3">
            <div className="flex items-center justify-between gap-2">
                <p className="text-sm text-muted-foreground">
                    {itemsLoading
                        ? 'Loading citations…'
                        : count === 0
                          ? 'No citations added yet.'
                          : `${count} citation${count === 1 ? '' : 's'} linked to this dataset.`}
                </p>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => setModalOpen(true)}
                    disabled={vocabLoading}
                    data-testid="open-citation-manager"
                >
                    <Quote className="mr-2 size-4" aria-hidden="true" />
                    Manage Citations
                </Button>
            </div>

            {modalOpen && (
                <CitationManagerModal
                    open={modalOpen}
                    onOpenChange={setModalOpen}
                    resourceId={resourceId}
                    resourceTypes={vocabularies.resourceTypes}
                    relationTypes={vocabularies.relationTypes}
                    contributorTypes={vocabularies.contributorTypes}
                />
            )}
        </div>
    );
}
