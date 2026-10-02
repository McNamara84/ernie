import type { BaseSuggestionItem, SubjectHierarchyMetadata, SuggestionAcceptanceInput } from '@/types/assistance';

export function subjectHierarchyMetadata(item: BaseSuggestionItem): SubjectHierarchyMetadata | null {
    if ((item.review?.assistant_id ?? item.assistant_id) !== 'subject-hierarchy-correction') return null;
    const metadata = item.metadata as Partial<SubjectHierarchyMetadata> | undefined;
    if (
        !metadata ||
        typeof metadata.fingerprint !== 'string' ||
        !/^[a-f0-9]{64}$/.test(metadata.fingerprint) ||
        typeof metadata.broader_id !== 'string' ||
        typeof metadata.broader_label !== 'string' ||
        typeof metadata.scheme !== 'string' ||
        !Array.isArray(metadata.nodes) ||
        !Array.isArray(metadata.leaf_ids) ||
        !Array.isArray(metadata.existing_leaf_ids)
    )
        return null;
    if (
        !metadata.nodes.every(
            (node) =>
                node &&
                typeof node.id === 'string' &&
                typeof node.label === 'string' &&
                typeof node.path === 'string' &&
                typeof node.description === 'string' &&
                typeof node.selectable === 'boolean' &&
                Array.isArray(node.children) &&
                node.children.every((id) => typeof id === 'string'),
        ) ||
        !metadata.leaf_ids.every((id) => typeof id === 'string') ||
        !metadata.existing_leaf_ids.every((id) => typeof id === 'string' && metadata.leaf_ids?.includes(id))
    )
        return null;
    return metadata as SubjectHierarchyMetadata;
}

export function isSubjectHierarchyReady(item: BaseSuggestionItem, input: SuggestionAcceptanceInput | undefined): boolean {
    const metadata = subjectHierarchyMetadata(item);
    const selected = input?.selected_leaf_ids;
    if (!metadata || input?.subject_hierarchy_fingerprint !== metadata.fingerprint || !selected?.length) return false;
    return (
        new Set(selected).size === selected.length &&
        selected.every((id) => metadata.leaf_ids.includes(id)) &&
        metadata.existing_leaf_ids.every((id) => selected.includes(id))
    );
}
