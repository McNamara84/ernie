import { Link } from '@inertiajs/react';
import { ChevronDown, ChevronRight } from 'lucide-react';
import { type ReactNode, useEffect, useMemo, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { subjectHierarchyMetadata } from '@/lib/subject-hierarchy';
import { isSafeHttpUrl } from '@/pages/LandingPages/lib/resolveIdentifierUrl';
import { editor } from '@/routes';
import type { BaseSuggestionItem, SuggestionAcceptanceInput } from '@/types/assistance';

interface Props {
    suggestion: BaseSuggestionItem;
    acceptanceInput: SuggestionAcceptanceInput;
    onAcceptanceInputChange: (input: SuggestionAcceptanceInput) => void;
    isProcessing: boolean;
}

export function SubjectHierarchyCorrectionCard({ suggestion, acceptanceInput, onAcceptanceInputChange, isProcessing }: Props) {
    const metadata = subjectHierarchyMetadata(suggestion);
    const [search, setSearch] = useState('');
    const [expanded, setExpanded] = useState<Set<string>>(() => new Set(metadata ? [metadata.broader_id] : []));
    const nodes = useMemo(() => new Map(metadata?.nodes.map((node) => [node.id, node]) ?? []), [metadata]);
    const selected =
        acceptanceInput.subject_hierarchy_fingerprint === metadata?.fingerprint
            ? (acceptanceInput.selected_leaf_ids ?? [])
            : (metadata?.existing_leaf_ids ?? []);

    useEffect(() => {
        if (metadata && acceptanceInput.subject_hierarchy_fingerprint !== metadata.fingerprint) {
            onAcceptanceInputChange({ selected_leaf_ids: metadata.existing_leaf_ids, subject_hierarchy_fingerprint: metadata.fingerprint });
            setExpanded(new Set([metadata.broader_id]));
        }
    }, [metadata, acceptanceInput.subject_hierarchy_fingerprint, onAcceptanceInputChange]);

    if (!metadata) return <p className="p-3 text-sm">This suggestion is incomplete. Run the subject hierarchy check again.</p>;
    if (metadata.suggestion_kind === 'hint')
        return (
            <div className="space-y-2 p-3 text-sm">
                <p className="font-medium">{metadata.broader_label}</p>
                <p>This is a navigation group, not an indexable subject concept. Review its replacement in the editor.</p>
                <Link className="text-primary underline" href={editor({ query: { resourceId: suggestion.resource_id } }).url}>
                    Open resource editor
                </Link>
            </div>
        );

    const update = (ids: string[]) => onAcceptanceInputChange({ selected_leaf_ids: ids, subject_hierarchy_fingerprint: metadata.fingerprint });
    const needle = search.trim().toLowerCase();
    const add = selected.filter((id) => !metadata.existing_leaf_ids.includes(id));
    const keepBroader = selected.length === metadata.leaf_ids.length;

    const renderNode = (id: string, ancestors: string[] = []): ReactNode => {
        const node = nodes.get(id);
        if (!node || ancestors.includes(id)) return null;
        const leaf = metadata.leaf_ids.includes(id);
        const open = expanded.has(id) || needle !== '';
        const children = open ? node.children.map((child) => renderNode(child, [...ancestors, id])).filter(Boolean) : [];
        if (needle && !node.path.toLowerCase().includes(needle) && children.length === 0) return null;
        const existing = metadata.existing_leaf_ids.includes(id);

        return (
            <li key={id} className="space-y-1">
                <div className="flex items-start gap-2 py-1">
                    {node.children.length > 0 && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="h-6 w-6 shrink-0 p-0"
                            aria-label={`${open ? 'Collapse' : 'Expand'} ${node.label}`}
                            aria-expanded={open}
                            onClick={() =>
                                setExpanded((current) => {
                                    const next = new Set(current);
                                    if (next.has(id)) next.delete(id);
                                    else next.add(id);
                                    return next;
                                })
                            }
                        >
                            {open ? <ChevronDown className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}
                        </Button>
                    )}
                    {leaf && (
                        <Checkbox
                            className="mt-1"
                            checked={selected.includes(id)}
                            disabled={isProcessing || existing}
                            aria-label={`Choose ${node.path}${existing ? ' (already assigned)' : ''}`}
                            onCheckedChange={(checked) =>
                                update(checked === true ? [...new Set([...selected, id])] : selected.filter((candidate) => candidate !== id))
                            }
                        />
                    )}
                    <div className="min-w-0 text-sm">
                        <span>{node.label}</span>
                        {isSafeHttpUrl(node.id) && (
                            <a className="ml-2 text-xs break-all text-primary underline" href={node.id} target="_blank" rel="noopener noreferrer">
                                {node.id}
                            </a>
                        )}
                        {existing && <span className="ml-2 text-muted-foreground">Already assigned</span>}
                        {node.description && <p className="text-xs text-muted-foreground">{node.description}</p>}
                    </div>
                </div>
                {open && children.length > 0 && <ul className="ml-5 border-l pl-3">{children}</ul>}
            </li>
        );
    };

    return (
        <div className="space-y-3 p-3" data-testid={`subject-hierarchy-${suggestion.id}`}>
            <div>
                <p className="font-medium">{metadata.broader_label}</p>
                <p className="text-xs text-muted-foreground">{metadata.scheme}</p>
            </div>
            <p className="text-sm">More specific subject terms are available. Choose only terms that accurately describe this resource.</p>
            <Input
                aria-label={`Search narrower terms for ${metadata.broader_label}`}
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                placeholder="Search narrower terms"
            />
            <Button type="button" variant="outline" size="sm" disabled={isProcessing} onClick={() => update([...metadata.leaf_ids])}>
                Select all {metadata.leaf_ids.length} narrower terms
            </Button>
            <ul className="max-h-96 overflow-auto rounded border p-2" aria-label={`Narrower terms for ${metadata.broader_label}`}>
                {renderNode(metadata.broader_id)}
            </ul>
            <div className="space-y-1 rounded bg-muted p-3 text-sm" aria-live="polite">
                <p>
                    {selected.length} of {metadata.leaf_ids.length} narrower terms selected.
                </p>
                {selected.length > 0 ? (
                    <>
                        <p>{keepBroader ? 'The broader term will be retained.' : 'The broader term will be removed.'}</p>
                        <p>
                            {add.length} subject(s) will be added: {add.map((id) => nodes.get(id)?.label).join(', ') || 'None'}
                        </p>
                    </>
                ) : (
                    <p>Choose at least one narrower term, or decline with a reason to keep the current tagging.</p>
                )}
            </div>
        </div>
    );
}
