import { FileUp, Link2, Plus } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

import { resolve as resolveCitationLabel } from '@/actions/App/Http/Controllers/Api/RelatedIdentifierCitationLabelController';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { normalizeDOI, validateIdentifierFormat } from '@/lib/doi-validation';
import type { IdentifierType, RelatedIdentifier, RelatedIdentifierFormData, RelationType } from '@/types';

import RelatedWorkCsvImport from './related-work-csv-import';
import RelatedWorkList, { type CitationResolutionState } from './related-work-list';

interface RelatedWorkFieldProps {
    relatedWorks: RelatedIdentifier[];
    onChange: (relatedWorks: RelatedIdentifier[]) => void;
    activeRelationTypes?: string[];
    activeIdentifierTypes?: string[];
}

const BULK_IMPORT_CITATION_HYDRATION_CONCURRENCY = 3;
const CITATION_RESOLUTION_IDENTIFIER_TYPES = new Set(['DOI', 'URL']);

interface CitationLookupResult {
    citation: string | null;
    cacheable: boolean;
}

function normalizeIdentifier(identifier: string, identifierType: string): string {
    return identifierType === 'DOI' ? normalizeDOI(identifier) : identifier.trim();
}

function getCitationLookupKey(identifier: string, identifierType: string): string {
    return JSON.stringify([identifierType, normalizeIdentifier(identifier, identifierType)]);
}

function isValidCitationIdentifier(identifier: string, identifierType: string): boolean {
    const validation = validateIdentifierFormat(identifier, identifierType);

    if (!validation.isValid) {
        return false;
    }

    if (identifierType !== 'URL') {
        return true;
    }

    try {
        const protocol = new URL(identifier).protocol.toLowerCase();

        return protocol === 'http:' || protocol === 'https:';
    } catch {
        return false;
    }
}

/**
 * A duplicate is only present when identifier, identifier type, and relation
 * type all match. The empty editor card is never considered a duplicate.
 */
function isDuplicate(identifier: string, identifierType: string, relationType: string, existingItems: RelatedIdentifier[]): boolean {
    const normalized = normalizeIdentifier(identifier, identifierType);

    if (normalized === '') {
        return false;
    }

    return existingItems.some((item) => {
        if (item.identifier_type !== identifierType || item.relation_type !== relationType) {
            return false;
        }

        const existingNormalized = normalizeIdentifier(item.identifier, item.identifier_type);

        return existingNormalized.toLowerCase() === normalized.toLowerCase();
    });
}

function isActiveOption(value: string, activeOptions?: string[]): boolean {
    return activeOptions === undefined || activeOptions.includes(value);
}

function getPreferredActiveOption<T extends string>(preferred: T, activeOptions?: string[]): T | null {
    if (activeOptions === undefined) {
        return preferred;
    }

    if (activeOptions.includes(preferred)) {
        return preferred;
    }

    const firstActive = activeOptions.find((value) => value.trim() !== '');

    return firstActive ? (firstActive as T) : null;
}

export default function RelatedWorkField({ relatedWorks, onChange, activeRelationTypes, activeIdentifierTypes }: RelatedWorkFieldProps) {
    const [showCsvImport, setShowCsvImport] = useState(false);
    const [duplicateError, setDuplicateError] = useState<string | null>(null);
    const [citationResolutionStates, setCitationResolutionStates] = useState<Record<string, CitationResolutionState>>({});
    const relatedWorksRef = useRef(relatedWorks);
    const citationCacheRef = useRef(new Map<string, string | null>());
    const citationRequestsRef = useRef(new Map<string, Promise<string | null>>());
    const defaultIdentifierType = getPreferredActiveOption<IdentifierType>('DOI', activeIdentifierTypes);
    const defaultRelationType = getPreferredActiveOption<RelationType>('Cites', activeRelationTypes);
    const hasEmptyCard = relatedWorks.some((item) => item.identifier.trim() === '');

    useEffect(() => {
        relatedWorksRef.current = relatedWorks;
    }, [relatedWorks]);

    const assignPositions = (items: RelatedIdentifier[]) =>
        items.map((item, index) => ({
            ...item,
            position: index,
        }));

    const setCitationResolutionState = (key: string, state: CitationResolutionState) => {
        setCitationResolutionStates((current) => ({
            ...current,
            [key]: state,
        }));
    };

    const resolveCitation = async (identifier: string, identifierType: string): Promise<string | null> => {
        if (!CITATION_RESOLUTION_IDENTIFIER_TYPES.has(identifierType)) {
            return null;
        }

        const normalizedIdentifier = normalizeIdentifier(identifier, identifierType);
        const lookupKey = getCitationLookupKey(normalizedIdentifier, identifierType);
        const validation = validateIdentifierFormat(normalizedIdentifier, identifierType);

        if (normalizedIdentifier === '' || !isValidCitationIdentifier(normalizedIdentifier, identifierType)) {
            setCitationResolutionState(lookupKey, {
                status: 'unavailable',
                message: validation.message ?? 'Enter a valid identifier to resolve a citation label.',
            });

            return null;
        }

        if (citationCacheRef.current.has(lookupKey)) {
            const cachedCitation = citationCacheRef.current.get(lookupKey) ?? null;

            setCitationResolutionState(lookupKey, {
                status: cachedCitation === null ? 'unavailable' : 'resolved',
                message: cachedCitation === null ? 'No automatic citation label found.' : undefined,
            });

            return cachedCitation;
        }

        const pendingRequest = citationRequestsRef.current.get(lookupKey);

        if (pendingRequest) {
            return pendingRequest;
        }

        setCitationResolutionState(lookupKey, { status: 'resolving' });

        const request = fetch(
            resolveCitationLabel.url({
                query: {
                    identifier: normalizedIdentifier,
                    identifierType,
                },
            }),
            {
                headers: {
                    Accept: 'application/json',
                },
            },
        )
            .then(async (response): Promise<CitationLookupResult> => {
                if (response.status === 404) {
                    return { citation: null, cacheable: true };
                }

                if (!response.ok) {
                    return { citation: null, cacheable: false };
                }

                const payload = (await response.json()) as { citation?: unknown };
                const citation = typeof payload.citation === 'string' ? payload.citation.trim() : '';

                return {
                    citation: citation === '' ? null : citation,
                    cacheable: citation !== '',
                };
            })
            .catch((): CitationLookupResult => ({ citation: null, cacheable: false }))
            .then(({ citation, cacheable }) => {
                if (cacheable) {
                    citationCacheRef.current.set(lookupKey, citation);
                }

                setCitationResolutionState(lookupKey, {
                    status: citation === null ? 'unavailable' : 'resolved',
                    message:
                        citation === null
                            ? cacheable
                                ? 'No automatic citation label found.'
                                : 'Automatic citation lookup failed. Leave the identifier again to retry.'
                            : undefined,
                });

                return citation;
            })
            .finally(() => {
                citationRequestsRef.current.delete(lookupKey);
            });

        citationRequestsRef.current.set(lookupKey, request);

        return request;
    };

    const hydrateCitationLabel = async (identifier: string, identifierType: string) => {
        const citationLabel = await resolveCitation(identifier, identifierType);

        if (!citationLabel) {
            return;
        }

        const lookupKey = getCitationLookupKey(identifier, identifierType);
        let didUpdate = false;
        const updated = relatedWorksRef.current.map((item) => {
            if (
                item.identifier_type === identifierType &&
                getCitationLookupKey(item.identifier, item.identifier_type) === lookupKey &&
                !item.citation_label?.trim()
            ) {
                didUpdate = true;

                return {
                    ...item,
                    citation_label: citationLabel,
                };
            }

            return item;
        });

        if (!didUpdate) {
            return;
        }

        relatedWorksRef.current = updated;
        onChange(updated);
    };

    const hydrateCitationLabelsForImportedItems = async (items: RelatedIdentifier[]) => {
        const itemsNeedingHydration = items.filter(
            (item) => CITATION_RESOLUTION_IDENTIFIER_TYPES.has(item.identifier_type) && !item.citation_label?.trim(),
        );

        for (let index = 0; index < itemsNeedingHydration.length; index += BULK_IMPORT_CITATION_HYDRATION_CONCURRENCY) {
            const chunk = itemsNeedingHydration.slice(index, index + BULK_IMPORT_CITATION_HYDRATION_CONCURRENCY);

            await Promise.all(chunk.map((item) => hydrateCitationLabel(item.identifier, item.identifier_type)));
        }
    };

    const handleAddCard = () => {
        if (
            hasEmptyCard ||
            relatedWorksRef.current.some((item) => item.identifier.trim() === '') ||
            defaultIdentifierType === null ||
            defaultRelationType === null
        ) {
            return;
        }

        const currentItems = relatedWorksRef.current;
        const updated: RelatedIdentifier[] = [
            ...currentItems,
            {
                identifier: '',
                identifier_type: defaultIdentifierType,
                identifier_type_manually_selected: false,
                relation_type: defaultRelationType,
                citation_label: null,
                position: currentItems.length,
            },
        ];

        setDuplicateError(null);
        relatedWorksRef.current = updated;
        onChange(updated);
    };

    const handleBulkImport = (data: RelatedIdentifierFormData[]) => {
        const combinedList = [...relatedWorksRef.current];
        const importedItems: RelatedIdentifier[] = [];
        const skippedDuplicates: string[] = [];
        const skippedInactiveOptions: string[] = [];

        data.forEach((item) => {
            const inactiveOptions = [
                !isActiveOption(item.identifierType, activeIdentifierTypes) ? `identifier type ${item.identifierType}` : null,
                !isActiveOption(item.relationType, activeRelationTypes) ? `relation type ${item.relationType}` : null,
            ].filter((option): option is string => option !== null);

            if (inactiveOptions.length > 0) {
                skippedInactiveOptions.push(`${item.identifier} (${inactiveOptions.join(', ')})`);

                return;
            }

            if (isDuplicate(item.identifier, item.identifierType, item.relationType, combinedList)) {
                skippedDuplicates.push(`${item.identifier} (${item.relationType})`);

                return;
            }

            const importedItem: RelatedIdentifier = {
                identifier: item.identifier,
                identifier_type: item.identifierType,
                relation_type: item.relationType,
                ...(item.relationTypeInformation ? { relation_type_information: item.relationTypeInformation } : {}),
                ...(item.citationLabel ? { citation_label: item.citationLabel } : {}),
                position: combinedList.length,
            };

            combinedList.push(importedItem);
            importedItems.push(importedItem);
        });

        const positionedItems = assignPositions(combinedList);

        relatedWorksRef.current = positionedItems;
        onChange(positionedItems);
        setShowCsvImport(false);
        void hydrateCitationLabelsForImportedItems(importedItems);

        const importWarnings: string[] = [];

        if (skippedDuplicates.length > 0) {
            importWarnings.push(
                `Skipped ${skippedDuplicates.length} ${skippedDuplicates.length === 1 ? 'duplicate' : 'duplicates'} from CSV import: ${skippedDuplicates.slice(0, 3).join(', ')}${skippedDuplicates.length > 3 ? '...' : ''}`,
            );
        }

        if (skippedInactiveOptions.length > 0) {
            importWarnings.push(
                `Skipped ${skippedInactiveOptions.length} CSV ${skippedInactiveOptions.length === 1 ? 'row' : 'rows'} using inactive options: ${skippedInactiveOptions.slice(0, 3).join(', ')}${skippedInactiveOptions.length > 3 ? '...' : ''}`,
            );
        }

        if (importWarnings.length > 0) {
            setDuplicateError(importWarnings.join(' '));
            setTimeout(() => setDuplicateError(null), 8000);
        }
    };

    const handleRemove = (index: number) => {
        const reindexed = assignPositions(relatedWorksRef.current.filter((_, itemIndex) => itemIndex !== index));

        relatedWorksRef.current = reindexed;
        onChange(reindexed);

        if (reindexed.length === 0) {
            setShowCsvImport(false);
            setDuplicateError(null);
        }
    };

    const handleItemChange = (index: number, updatedItem: RelatedIdentifier): boolean => {
        const currentItems = relatedWorksRef.current;
        const otherItems = currentItems.filter((_, itemIndex) => itemIndex !== index);

        if (isDuplicate(updatedItem.identifier, updatedItem.identifier_type, updatedItem.relation_type, otherItems)) {
            setDuplicateError(
                'This exact relation already exists in the list (same identifier and relation type). Note: You can add the same identifier with a different relation type.',
            );
            setTimeout(() => setDuplicateError(null), 5000);

            return false;
        }

        setDuplicateError(null);

        const previousItem = currentItems[index];

        if (!previousItem) {
            return false;
        }

        const identifierChanged = previousItem.identifier !== updatedItem.identifier || previousItem.identifier_type !== updatedItem.identifier_type;
        const updated = currentItems.map((item, itemIndex) => {
            if (itemIndex !== index) {
                return item;
            }

            return {
                ...updatedItem,
                citation_label: identifierChanged ? null : (updatedItem.citation_label ?? null),
                related_title: identifierChanged ? null : (updatedItem.related_title ?? null),
                related_metadata: identifierChanged ? null : (updatedItem.related_metadata ?? null),
                position: itemIndex,
            };
        });

        relatedWorksRef.current = updated;
        onChange(updated);

        return true;
    };

    const handleIdentifierBlur = (index: number) => {
        const currentItem = relatedWorksRef.current[index];

        if (!currentItem) {
            return;
        }

        const normalizedIdentifier = normalizeIdentifier(currentItem.identifier, currentItem.identifier_type);
        let itemForLookup = currentItem;

        if (normalizedIdentifier !== currentItem.identifier) {
            itemForLookup = {
                ...currentItem,
                identifier: normalizedIdentifier,
            };

            if (!handleItemChange(index, itemForLookup)) {
                return;
            }
        }

        if (
            normalizedIdentifier === '' ||
            !CITATION_RESOLUTION_IDENTIFIER_TYPES.has(itemForLookup.identifier_type) ||
            itemForLookup.citation_label?.trim()
        ) {
            return;
        }

        const validation = validateIdentifierFormat(normalizedIdentifier, itemForLookup.identifier_type);

        if (!isValidCitationIdentifier(normalizedIdentifier, itemForLookup.identifier_type)) {
            setCitationResolutionState(getCitationLookupKey(normalizedIdentifier, itemForLookup.identifier_type), {
                status: 'unavailable',
                message: validation.message ?? 'Enter a valid identifier to resolve a citation label.',
            });

            return;
        }

        void hydrateCitationLabel(normalizedIdentifier, itemForLookup.identifier_type);
    };

    const handleReorder = (items: RelatedIdentifier[]) => {
        const reindexed = assignPositions(items);

        relatedWorksRef.current = reindexed;
        onChange(reindexed);
    };

    const resolutionStatesByIndex = useMemo(() => {
        const states = new Map<number, CitationResolutionState>();

        relatedWorks.forEach((item, index) => {
            if (!CITATION_RESOLUTION_IDENTIFIER_TYPES.has(item.identifier_type) || item.identifier.trim() === '') {
                return;
            }

            const state = citationResolutionStates[getCitationLookupKey(item.identifier, item.identifier_type)];

            if (state) {
                states.set(index, state);
            }
        });

        return states;
    }, [citationResolutionStates, relatedWorks]);

    return (
        <div className="space-y-6">
            {duplicateError && (
                <Alert variant="destructive">
                    <AlertDescription>{duplicateError}</AlertDescription>
                </Alert>
            )}

            {showCsvImport ? (
                <div className="rounded-lg border bg-card p-6">
                    <RelatedWorkCsvImport
                        onImport={handleBulkImport}
                        onClose={() => setShowCsvImport(false)}
                        activeRelationTypes={activeRelationTypes}
                        activeIdentifierTypes={activeIdentifierTypes}
                    />
                </div>
            ) : relatedWorks.length === 0 ? (
                <EmptyState
                    icon={<Link2 className="h-8 w-8" />}
                    title="No related works added"
                    description="Add relationships to other datasets, publications, or resources."
                    action={{
                        label: 'Add Related Work',
                        onClick: handleAddCard,
                    }}
                    secondaryAction={{
                        label: 'Import CSV',
                        onClick: () => setShowCsvImport(true),
                        icon: <FileUp className="mr-2 h-4 w-4" />,
                    }}
                    data-testid="related-work-empty-state"
                />
            ) : (
                <>
                    <RelatedWorkList
                        items={relatedWorks}
                        completedItemCount={relatedWorks.filter((item) => item.identifier.trim() !== '').length}
                        onItemChange={handleItemChange}
                        onIdentifierBlur={handleIdentifierBlur}
                        onRemove={handleRemove}
                        onReorder={handleReorder}
                        activeRelationTypes={activeRelationTypes}
                        activeIdentifierTypes={activeIdentifierTypes}
                        citationResolutionStates={resolutionStatesByIndex}
                    />

                    <div className="flex flex-wrap justify-end gap-2">
                        <Button type="button" variant="outline" size="sm" onClick={() => setShowCsvImport(true)}>
                            <FileUp className="mr-2 h-4 w-4" />
                            Import from CSV
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            onClick={handleAddCard}
                            disabled={hasEmptyCard || defaultIdentifierType === null || defaultRelationType === null}
                        >
                            <Plus className="mr-2 h-4 w-4" />
                            Add Related Work
                        </Button>
                    </div>
                </>
            )}
        </div>
    );
}
