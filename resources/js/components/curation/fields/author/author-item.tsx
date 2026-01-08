/**
 * AuthorItem Component
 *
 * Individual author entry with all fields.
 * Supports both person and institution types with drag & drop reordering.
 * Migrated from author-field.tsx
 */

import { useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { CheckCircle2, ExternalLink, GripVertical, Loader2, Trash2 } from 'lucide-react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { useAffiliationsTagify } from '@/hooks/use-affiliations-tagify';
import { useOrcidAutofill } from '@/hooks/use-orcid-autofill';
import { type OrcidSearchResult, OrcidService } from '@/services/orcid';
import type { AffiliationSuggestion, AffiliationTag } from '@/types/affiliations';

import InputField from '../input-field';
import { OrcidSearchDialog } from '../orcid-search-dialog';
import { SelectField } from '../select-field';
import TagInputField, { type TagInputItem } from '../tag-input-field';
import type { AuthorEntry, AuthorType } from './types';

interface AuthorItemProps {
    author: AuthorEntry;
    index: number;
    onTypeChange: (type: AuthorType) => void;
    onPersonFieldChange: (field: 'orcid' | 'firstName' | 'lastName' | 'email' | 'website', value: string) => void;
    onInstitutionNameChange: (value: string) => void;
    onContactChange: (checked: boolean) => void;
    onAffiliationsChange: (value: { raw: string; tags: AffiliationTag[] }) => void;
    onAuthorChange: (author: AuthorEntry) => void; // For bulk updates (e.g., ORCID verification)
    onRemove: () => void;
    canRemove: boolean;
    affiliationSuggestions: AffiliationSuggestion[];
}

/**
 * AuthorItem - Single author entry component with full field implementation and drag & drop support
 */
export default function AuthorItem({
    author,
    index,
    onTypeChange,
    onPersonFieldChange,
    onInstitutionNameChange,
    onContactChange,
    onAffiliationsChange,
    onAuthorChange,
    onRemove,
    canRemove,
    affiliationSuggestions,
}: AuthorItemProps) {
    const isPerson = author.type === 'person';

    // Drag & Drop
    const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id: author.id });

    const style = {
        transform: CSS.Transform.toString(transform),
        transition,
        opacity: isDragging ? 0.5 : 1,
    };
    const contactLabelTextId = `${author.id}-contact-label-text`;

    // Track user interactions with name fields to prevent auto-suggest on initial load
    const [hasUserInteracted, setHasUserInteracted] = useState(false);

    // Wrapper for onPersonFieldChange that marks user interaction for name fields only
    // Only firstName/lastName changes should enable ORCID auto-suggest, not email/website/orcid
    const handlePersonFieldChange = (field: 'orcid' | 'firstName' | 'lastName' | 'email' | 'website', value: string) => {
        if (field === 'firstName' || field === 'lastName') {
            setHasUserInteracted(true);
        }
        onPersonFieldChange(field, value);
    };

    // ORCID auto-fill, verification, and suggestions via shared hook
    const {
        isVerifying,
        verificationError,
        orcidSuggestions,
        isLoadingSuggestions,
        showSuggestions,
        hideSuggestions,
        handleOrcidSelect,
    } = useOrcidAutofill<AuthorEntry>({
        entry: author,
        onEntryChange: onAuthorChange,
        hasUserInteracted,
        includeEmail: true, // Authors can have email auto-filled
    });

    // Handle selecting an ORCID suggestion
    const handleSelectSuggestion = async (suggestion: OrcidSearchResult) => {
        hideSuggestions();
        await handleOrcidSelect(suggestion.orcid);
    };

    // Affiliations Tagify settings via shared hook
    const { tagifySettings, affiliationsWithRorId, affiliationsDescriptionId } = useAffiliationsTagify({
        affiliationSuggestions,
        affiliations: author.affiliations,
        idPrefix: author.id,
    });

    return (
        <section
            ref={setNodeRef}
            style={style}
            className="rounded-lg border border-border bg-card p-6 shadow-sm transition hover:shadow-md"
            aria-labelledby={`${author.id}-heading`}
        >
            {/* Header with Drag Handle and Remove Button */}
            <div className="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                <div className="flex items-center gap-3">
                    {/* Drag Handle */}
                    <button
                        type="button"
                        className="cursor-grab touch-none text-muted-foreground transition-colors hover:text-foreground active:cursor-grabbing"
                        {...attributes}
                        {...listeners}
                        aria-label={`Reorder author ${index + 1}`}
                    >
                        <GripVertical className="h-5 w-5" />
                    </button>
                    <div>
                        <h3 id={`${author.id}-heading`} className="text-lg leading-6 font-semibold text-foreground">
                            Author {index + 1}
                        </h3>
                    </div>
                </div>
                {canRemove && (
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        onClick={onRemove}
                        aria-label={`Remove author ${index + 1}`}
                        className="self-end"
                    >
                        <Trash2 className="h-4 w-4" />
                    </Button>
                )}
            </div>

            {/* Form Fields */}
            <div className="mt-6 space-y-4">
                {/* Type, ORCID, Name, Contact Person */}
                <div className="grid gap-y-4 md:grid-cols-12 md:gap-x-3" data-testid={`author-${index}-fields-grid`}>
                    <SelectField
                        id={`${author.id}-type`}
                        label="Author type"
                        value={author.type}
                        onValueChange={(value) => onTypeChange(value as AuthorType)}
                        options={[
                            { value: 'person', label: 'Person' },
                            { value: 'institution', label: 'Institution' },
                        ]}
                        containerProps={{
                            'data-testid': `author-${index}-type-field`,
                            className: 'md:col-span-2',
                        }}
                        triggerClassName="w-full"
                        required
                    />

                    {isPerson ? (
                        <>
                            {/* ORCID with Verify & Fill Button */}
                            <div className="relative flex flex-col gap-2 md:col-span-3" data-testid={`author-${index}-orcid-field`}>
                                <Label htmlFor={`${author.id}-orcid`} className="inline-flex flex-wrap items-baseline gap-x-2">
                                    <span>ORCID</span>
                                    {author.type === 'person' && author.orcidVerified && (
                                        <Badge variant="outline" className="h-4 border-green-600 px-1.5 py-0 text-[10px] leading-none text-green-600">
                                            <CheckCircle2 className="mr-0.5 h-2.5 w-2.5" />
                                            Verified
                                        </Badge>
                                    )}
                                    {isVerifying && (
                                        <span className="inline-flex items-center gap-0.5 text-[10px] text-muted-foreground">
                                            <Loader2 className="h-2.5 w-2.5 animate-spin" />
                                            Verifying...
                                        </span>
                                    )}
                                </Label>
                                <div className="flex gap-2">
                                    <input
                                        id={`${author.id}-orcid`}
                                        type="text"
                                        value={author.orcid || ''}
                                        onChange={(event) => handlePersonFieldChange('orcid', event.target.value)}
                                        placeholder="0000-0000-0000-0000"
                                        className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                                        inputMode="text"
                                        pattern="[0-9]{4}-[0-9]{4}-[0-9]{4}-[0-9]{3}[0-9X]"
                                    />
                                    {author.type === 'person' && (
                                        <OrcidSearchDialog
                                            onSelect={handleSelectSuggestion}
                                            triggerClassName="h-10 w-10 shrink-0 p-0 hover:bg-accent"
                                        />
                                    )}
                                </div>
                                {verificationError && <p className="mt-1 text-sm text-red-600">{verificationError}</p>}
                                {author.orcid && OrcidService.isValidFormat(author.orcid) && (
                                    <a
                                        href={OrcidService.formatOrcidUrl(author.orcid)}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="inline-flex items-center gap-1 text-xs text-blue-600 hover:underline"
                                    >
                                        View on ORCID.org
                                        <ExternalLink className="h-3 w-3" />
                                    </a>
                                )}

                                {/* ORCID Auto-Suggestions */}
                                {showSuggestions && orcidSuggestions.length > 0 && (
                                    <div className="absolute z-10 mt-1 max-h-60 w-full overflow-y-auto rounded-md border border-gray-300 bg-white shadow-lg">
                                        {isLoadingSuggestions && (
                                            <div className="flex items-center gap-2 p-3 text-sm text-gray-500">
                                                <Loader2 className="h-4 w-4 animate-spin" />
                                                Searching for matching ORCIDs...
                                            </div>
                                        )}
                                        {!isLoadingSuggestions &&
                                            orcidSuggestions.map((suggestion) => (
                                                <button
                                                    key={suggestion.orcid}
                                                    type="button"
                                                    onClick={() => handleSelectSuggestion(suggestion)}
                                                    className="w-full border-b border-gray-100 p-3 text-left transition-colors last:border-b-0 hover:bg-gray-50"
                                                >
                                                    <div className="flex items-start justify-between gap-2">
                                                        <div className="min-w-0 flex-1">
                                                            <p className="truncate font-medium text-gray-900">
                                                                {suggestion.creditName || `${suggestion.firstName} ${suggestion.lastName}`}
                                                            </p>
                                                            <p className="mt-1 font-mono text-xs text-gray-600">{suggestion.orcid}</p>
                                                            {suggestion.institutions.length > 0 && (
                                                                <p className="mt-1 truncate text-xs text-gray-500">
                                                                    {suggestion.institutions.join(', ')}
                                                                </p>
                                                            )}
                                                        </div>
                                                        <ExternalLink className="h-4 w-4 shrink-0 text-gray-400" />
                                                    </div>
                                                </button>
                                            ))}
                                    </div>
                                )}
                            </div>

                            <InputField
                                id={`${author.id}-firstName`}
                                label="First name"
                                value={author.firstName || ''}
                                onChange={(event) => handlePersonFieldChange('firstName', event.target.value)}
                                containerProps={{ className: 'md:col-span-3' }}
                            />
                            <InputField
                                id={`${author.id}-lastName`}
                                label="Last name"
                                value={author.lastName || ''}
                                onChange={(event) => handlePersonFieldChange('lastName', event.target.value)}
                                containerProps={{ className: 'md:col-span-3' }}
                                required
                            />
                            <div className="flex flex-col items-start gap-2 md:col-span-1" data-testid={`author-${index}-contact-field`}>
                                <Tooltip>
                                    <TooltipTrigger asChild>
                                        <Label htmlFor={`${author.id}-contact`} className="inline-flex cursor-help font-medium">
                                            <span aria-hidden="true">CP</span>
                                            <span id={contactLabelTextId} className="sr-only">
                                                Contact person
                                            </span>
                                        </Label>
                                    </TooltipTrigger>
                                    <TooltipContent side="top">Contact Person: Select if this author should be the primary contact.</TooltipContent>
                                </Tooltip>
                                <Checkbox
                                    id={`${author.id}-contact`}
                                    checked={author.isContact}
                                    onCheckedChange={(checked) => onContactChange(checked === true)}
                                    aria-describedby={`${author.id}-contact-hint`}
                                    aria-labelledby={contactLabelTextId}
                                />
                                <p id={`${author.id}-contact-hint`} className="sr-only">
                                    Contact Person: Select if this author should be the primary contact.
                                </p>
                            </div>
                        </>
                    ) : (
                        <InputField
                            id={`${author.id}-institution`}
                            label="Institution name"
                            value={author.institutionName || ''}
                            onChange={(event) => onInstitutionNameChange(event.target.value)}
                            containerProps={{ className: 'md:col-span-10' }}
                            required
                        />
                    )}
                </div>

                {/* Affiliations, Email, Website */}
                <div className="grid gap-y-4 md:grid-cols-12 md:gap-x-3" data-testid={`author-${index}-affiliations-grid`}>
                    <TagInputField
                        id={`${author.id}-affiliations`}
                        label="Affiliations"
                        value={author.affiliations as TagInputItem[]}
                        onChange={(detail) =>
                            onAffiliationsChange({
                                raw: detail.raw,
                                tags: detail.tags.map((tag) => ({
                                    value: tag.value,
                                    rorId: 'rorId' in tag && typeof tag.rorId === 'string' ? tag.rorId : null,
                                })),
                            })
                        }
                        placeholder="Institution A, Institution B"
                        containerProps={{
                            className: isPerson && author.isContact ? 'md:col-span-6' : 'md:col-span-12',
                            'data-testid': `author-${index}-affiliations-field`,
                        }}
                        data-testid={`author-${index}-affiliations-input`}
                        tagifySettings={tagifySettings}
                        aria-describedby={affiliationsDescriptionId}
                    />

                    {/* Email and Website for Contact Person */}
                    {isPerson && author.isContact && (
                        <>
                            <InputField
                                id={`${author.id}-email`}
                                type="email"
                                label="Email address"
                                value={author.email || ''}
                                onChange={(event) => handlePersonFieldChange('email', event.target.value)}
                                containerProps={{ className: 'md:col-span-3' }}
                                required
                            />
                            <InputField
                                id={`${author.id}-website`}
                                type="url"
                                label="Website"
                                value={author.website || ''}
                                onChange={(event) => handlePersonFieldChange('website', event.target.value)}
                                placeholder="https://example.org"
                                containerProps={{ className: 'md:col-span-3' }}
                            />
                        </>
                    )}

                    {/* ROR ID Badges */}
                    {affiliationsWithRorId.length > 0 && (
                        <div
                            id={affiliationsDescriptionId}
                            className="col-span-full flex flex-col gap-2 md:col-span-12"
                            data-testid={`author-${index}-affiliations-ror-ids`}
                            aria-live="polite"
                        >
                            <p className="text-sm font-medium text-muted-foreground">Linked ROR IDs</p>
                            <div className="flex flex-wrap gap-2" role="list" aria-label="Selected ROR identifiers">
                                {affiliationsWithRorId.map((affiliation) => (
                                    <Badge
                                        key={`${affiliation.rorId}-${affiliation.value}`}
                                        variant="secondary"
                                        className="gap-1 px-2 py-1 text-xs font-medium transition-colors hover:bg-secondary/80"
                                        role="listitem"
                                        asChild
                                    >
                                        <a
                                            href={affiliation.rorId}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="inline-flex items-center gap-1"
                                        >
                                            <span aria-hidden="true">ROR:</span>
                                            <span aria-hidden="true" className="font-mono">
                                                {affiliation.rorId}
                                            </span>
                                            <span className="sr-only">
                                                {`Open ROR identifier for ${affiliation.value}: ${affiliation.rorId} in new tab`}
                                            </span>
                                        </a>
                                    </Badge>
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </section>
    );
}
