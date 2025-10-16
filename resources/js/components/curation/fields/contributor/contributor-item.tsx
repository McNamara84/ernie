/**
 * ContributorItem Component
 * 
 * Individual contributor entry with all fields.
 * Supports both person and institution types with roles and drag & drop reordering.
 * Migrated from contributor-field.tsx
 */

import { useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import type { TagData, TagifySettings } from '@yaireo/tagify';
import { CheckCircle2, Loader2, Trash2, ExternalLink, GripVertical } from 'lucide-react';
import { useMemo, useState, useEffect } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import type { AffiliationSuggestion, AffiliationTag } from '@/types/affiliations';
import { OrcidService, type OrcidSearchResult } from '@/services/orcid';

import InputField from '../input-field';
import { SelectField } from '../select-field';
import TagInputField, { type TagInputItem } from '../tag-input-field';
import { OrcidSearchDialog } from '../orcid-search-dialog';
import type { ContributorEntry, ContributorRoleTag, ContributorType, PersonContributorEntry } from './types';

interface ContributorItemProps {
    contributor: ContributorEntry;
    index: number;
    onTypeChange: (type: ContributorType) => void;
    onRolesChange: (value: { raw: string; tags: ContributorRoleTag[] }) => void;
    onPersonFieldChange: (field: 'orcid' | 'firstName' | 'lastName', value: string) => void;
    onInstitutionNameChange: (value: string) => void;
    onAffiliationsChange: (value: { raw: string; tags: AffiliationTag[] }) => void;
    onContributorChange: (contributor: ContributorEntry) => void; // For bulk updates (e.g., ORCID verification)
    onRemove: () => void;
    canRemove: boolean;
    affiliationSuggestions: AffiliationSuggestion[];
    personRoleOptions: readonly string[];
    institutionRoleOptions: readonly string[];
}

/**
 * ContributorItem - Single contributor entry component with full field implementation
 */
export default function ContributorItem({
    contributor,
    index,
    onTypeChange,
    onRolesChange,
    onPersonFieldChange,
    onInstitutionNameChange,
    onAffiliationsChange,
    onContributorChange,
    onRemove,
    canRemove,
    affiliationSuggestions,
    personRoleOptions,
    institutionRoleOptions,
}: ContributorItemProps) {
    const isPerson = contributor.type === 'person';

    // Drag & Drop
    const {
        attributes,
        listeners,
        setNodeRef,
        transform,
        transition,
        isDragging,
    } = useSortable({ id: contributor.id });

    const style = {
        transform: CSS.Transform.toString(transform),
        transition,
        opacity: isDragging ? 0.5 : 1,
    };

    // ORCID Auto-Fill State
    const [isVerifying, setIsVerifying] = useState(false);
    const [verificationError, setVerificationError] = useState<string | null>(null);
    
    // ORCID Auto-Suggest State
    const [orcidSuggestions, setOrcidSuggestions] = useState<OrcidSearchResult[]>([]);
    const [isLoadingSuggestions, setIsLoadingSuggestions] = useState(false);
    const [showSuggestions, setShowSuggestions] = useState(false);

    // Role options based on type
    const roleOptions = useMemo(
        () => (isPerson ? personRoleOptions : institutionRoleOptions).map((role) => ({ value: role })),
        [institutionRoleOptions, isPerson, personRoleOptions],
    );

    // Tagify settings for roles
    const roleTagifySettings = useMemo<Partial<TagifySettings<TagData>>>(() => {
        return {
            whitelist: roleOptions,
            enforceWhitelist: true,
            maxTags: roleOptions.length,
            dropdown: {
                enabled: roleOptions.length > 0 ? 0 : 1,
                maxItems: roleOptions.length || 10,
                searchKeys: ['value'],
            },
        };
    }, [roleOptions]);

    // Extract affiliations with ROR IDs
    const affiliationsWithRorId = useMemo(() => {
        const seen = new Set<string>();

        return contributor.affiliations.reduce<{ value: string; rorId: string }[]>(
            (accumulator, affiliation) => {
                const value = affiliation.value.trim();
                const rorId = typeof affiliation.rorId === 'string' ? affiliation.rorId.trim() : '';

                if (!value || !rorId || seen.has(rorId)) {
                    return accumulator;
                }

                seen.add(rorId);
                accumulator.push({ value, rorId });
                return accumulator;
            },
            [],
        );
    }, [contributor.affiliations]);

    const affiliationsDescriptionId =
        affiliationsWithRorId.length > 0 ? `${contributor.id}-contributor-affiliations` : undefined;

    const rolesHintId = `${contributor.id}-roles-hint`;
    const rolesUnavailableId = `${contributor.id}-roles-unavailable`;
    const hasRoleOptions = roleOptions.length > 0;
    const rolesDescriptionIds = hasRoleOptions ? rolesHintId : `${rolesHintId} ${rolesUnavailableId}`.trim();

    /**
     * Handle ORCID Search & Select
     * Triggered when user selects a person from the search dialog
     */
    const handleOrcidSelect = async (orcid: string, searchResult: OrcidSearchResult) => {
        if (contributor.type !== 'person') return;
        
        // Set the ORCID and clear any previous errors
        onPersonFieldChange('orcid', orcid);
        setVerificationError(null);
        
        // Auto-fill name fields if empty
        if (!contributor.firstName && searchResult.firstName) {
            onPersonFieldChange('firstName', searchResult.firstName);
        }
        if (!contributor.lastName && searchResult.lastName) {
            onPersonFieldChange('lastName', searchResult.lastName);
        }
        
        // Now verify and fetch full details
        setIsVerifying(true);
        
        try {
            const response = await OrcidService.fetchOrcidRecord(orcid);
            
            if (!response.success || !response.data) {
                setVerificationError(response.error || 'Failed to fetch ORCID data');
                setIsVerifying(false);
                return;
            }
            
            const data = response.data;
            
            // Update with complete ORCID data
            const updatedContributor: PersonContributorEntry = {
                ...contributor,
                orcid,
                firstName: data.firstName || contributor.firstName,
                lastName: data.lastName || contributor.lastName,
                orcidVerified: true,
                orcidVerifiedAt: new Date().toISOString(),
            };

            // Auto-fill affiliations from full ORCID record
            if (data.affiliations.length > 0) {
                const employmentAffiliations = data.affiliations
                    .filter(aff => aff.type === 'employment' && aff.name)
                    .map(aff => ({
                        value: aff.name!,
                        rorId: null,
                    }));

                if (employmentAffiliations.length > 0) {
                    const existingValues = new Set(contributor.affiliations.map(a => a.value));
                    const newAffiliations = employmentAffiliations.filter(
                        a => !existingValues.has(a.value)
                    );

                    if (newAffiliations.length > 0) {
                        updatedContributor.affiliations = [
                            ...contributor.affiliations,
                            ...newAffiliations as AffiliationTag[],
                        ];
                        updatedContributor.affiliationsInput = updatedContributor.affiliations
                            .map((a: AffiliationTag) => a.value)
                            .join(', ');
                    }
                }
            }

            onContributorChange(updatedContributor);
            setIsVerifying(false);
        } catch (error) {
            console.error('ORCID fetch error:', error);
            setVerificationError('Failed to fetch complete ORCID data');
            setIsVerifying(false);
        }
    };
    
    /**
     * Handle selecting an ORCID suggestion
     */
    const handleSelectSuggestion = async (suggestion: OrcidSearchResult) => {
        setShowSuggestions(false);
        await handleOrcidSelect(suggestion.orcid, suggestion);
    };
    
    /**
     * Auto-suggest ORCIDs based on name and affiliations
     * Triggered when firstName + lastName are filled and ORCID is empty
     */
    useEffect(() => {
        const searchForOrcid = async () => {
            // Only search if person type, has name, and no ORCID yet
            if (
                contributor.type !== 'person' ||
                !contributor.firstName?.trim() ||
                !contributor.lastName?.trim() ||
                contributor.orcid?.trim() ||
                contributor.orcidVerified
            ) {
                setOrcidSuggestions([]);
                setShowSuggestions(false);
                return;
            }

            setIsLoadingSuggestions(true);
            setShowSuggestions(true);

            try {
                // Build search query: "FirstName LastName"
                let searchQuery = `${contributor.firstName.trim()} ${contributor.lastName.trim()}`;
                
                // Add first affiliation if available to refine search
                if (contributor.affiliations.length > 0 && contributor.affiliations[0].value) {
                    searchQuery += ` ${contributor.affiliations[0].value}`;
                }

                const response = await OrcidService.searchOrcid(searchQuery);

                if (response.success && response.data) {
                    // Limit to top 5 suggestions
                    setOrcidSuggestions(response.data.results.slice(0, 5));
                } else {
                    setOrcidSuggestions([]);
                }
            } catch (error) {
                console.error('ORCID suggestion error:', error);
                setOrcidSuggestions([]);
            } finally {
                setIsLoadingSuggestions(false);
            }
        };

        // Debounce: Wait 800ms after user stops typing
        const timeoutId = setTimeout(searchForOrcid, 800);

        return () => clearTimeout(timeoutId);
    }, [contributor]);

    /**
     * Auto-verify and fill when a valid ORCID is entered
     * Triggered when ORCID is syntactically valid and not yet verified
     */
    useEffect(() => {
        const autoVerifyOrcid = async () => {
            // Only auto-verify if:
            // 1. Person type
            // 2. ORCID has valid format
            // 3. Not already verified
            // 4. Not currently verifying
            if (
                contributor.type !== 'person' ||
                !contributor.orcid?.trim() ||
                !OrcidService.isValidFormat(contributor.orcid) ||
                contributor.orcidVerified ||
                isVerifying
            ) {
                return;
            }

            setIsVerifying(true);
            setVerificationError(null);

            try {
                // Validate ORCID exists
                const validationResponse = await OrcidService.validateOrcid(contributor.orcid);
                
                if (!validationResponse.success || !validationResponse.data?.exists) {
                    setVerificationError('ORCID not found');
                    setIsVerifying(false);
                    return;
                }

                // Fetch full ORCID record
                const response = await OrcidService.fetchOrcidRecord(contributor.orcid);

                if (!response.success || !response.data) {
                    setVerificationError('Failed to fetch ORCID data');
                    setIsVerifying(false);
                    return;
                }

                const data = response.data;

                // Auto-fill fields
                const updatedContributor = {
                    ...contributor,
                    firstName: data.firstName || contributor.firstName,
                    lastName: data.lastName || contributor.lastName,
                    orcidVerified: true,
                    orcidVerifiedAt: new Date().toISOString(),
                };

                // Auto-fill affiliations from full ORCID record
                if (data.affiliations.length > 0) {
                    const employmentAffiliations = data.affiliations
                        .filter(aff => aff.type === 'employment' && aff.name)
                        .map(aff => ({
                            value: aff.name!,
                            rorId: null,
                        }));

                    if (employmentAffiliations.length > 0) {
                        const existingValues = new Set(contributor.affiliations.map(a => a.value));
                        const newAffiliations = employmentAffiliations.filter(
                            a => !existingValues.has(a.value)
                        );

                        if (newAffiliations.length > 0) {
                            updatedContributor.affiliations = [
                                ...contributor.affiliations,
                                ...newAffiliations as AffiliationTag[],
                            ];
                            updatedContributor.affiliationsInput = updatedContributor.affiliations
                                .map((a: AffiliationTag) => a.value)
                                .join(', ');
                        }
                    }
                }

                onContributorChange(updatedContributor);
                setIsVerifying(false);
            } catch (error) {
                console.error('Auto-verify ORCID error:', error);
                setVerificationError('Failed to verify ORCID');
                setIsVerifying(false);
            }
        };

        // Debounce: Wait 800ms after ORCID input stops
        const timeoutId = setTimeout(autoVerifyOrcid, 800);

        return () => clearTimeout(timeoutId);
    }, [contributor]);

    // Tagify settings for affiliations autocomplete
    const affiliationTagifySettings = useMemo<Partial<TagifySettings<TagData>>>(() => {
        const whitelist = affiliationSuggestions.map((suggestion) => ({
            value: suggestion.value,
            rorId: suggestion.rorId,
            searchTerms: suggestion.searchTerms,
        }));

        return {
            whitelist,
            dropdown: {
                enabled: whitelist.length > 0 ? 1 : 0,
                maxItems: 20,
                closeOnSelect: true,
                searchKeys: ['value', 'searchTerms'],
            },
        };
    }, [affiliationSuggestions]);

    return (
        <section
            ref={setNodeRef}
            style={style}
            className="rounded-lg border border-border bg-card p-6 shadow-sm transition hover:shadow-md"
            aria-labelledby={`${contributor.id}-heading`}
        >
            {/* Header with Drag Handle and Remove Button */}
            <div className="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                <div className="flex items-center gap-3">
                    {/* Drag Handle */}
                    <button
                        type="button"
                        className="cursor-grab active:cursor-grabbing touch-none text-muted-foreground hover:text-foreground transition-colors"
                        {...attributes}
                        {...listeners}
                        aria-label={`Reorder contributor ${index + 1}`}
                    >
                        <GripVertical className="h-5 w-5" />
                    </button>
                    <div>
                        <h3
                            id={`${contributor.id}-heading`}
                            className="text-lg font-semibold leading-6 text-foreground"
                        >
                            Contributor {index + 1}
                        </h3>
                    </div>
                </div>
                {canRemove && (
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        onClick={onRemove}
                        aria-label={`Remove contributor ${index + 1}`}
                        className="self-end"
                    >
                        <Trash2 className="h-4 w-4" />
                    </Button>
                )}
            </div>

            {/* Form Fields */}
            <div className="mt-6 space-y-4">
                {/* Type and Roles */}
                <div className="grid gap-y-4 md:grid-cols-12 md:gap-x-3">
                    <SelectField
                        id={`${contributor.id}-type`}
                        label="Contributor type"
                        value={contributor.type}
                        onValueChange={(value) => onTypeChange(value as ContributorType)}
                        options={[
                            { value: 'person', label: 'Person' },
                            { value: 'institution', label: 'Institution' },
                        ]}
                        containerProps={{
                            'data-testid': `contributor-${index}-type-field`,
                            className: 'md:col-span-6 lg:col-span-4',
                        }}
                        triggerClassName="w-full"
                        required
                    />

                    <TagInputField
                        id={`${contributor.id}-roles`}
                        label="Roles"
                        value={contributor.roles as TagInputItem[]}
                        onChange={(detail) =>
                            onRolesChange({
                                raw: detail.raw,
                                tags: detail.tags.map((tag) => ({
                                    value: tag.value,
                                })),
                            })
                        }
                        placeholder={
                            hasRoleOptions
                                ? 'Select one or more roles'
                                : 'No roles available for this contributor type'
                        }
                        disabled={!hasRoleOptions}
                        containerProps={{
                            className: 'md:col-span-6 lg:col-span-8',
                            'data-testid': `contributor-${index}-roles-field`,
                        }}
                        data-testid={`contributor-${index}-roles-input`}
                        tagifySettings={roleTagifySettings}
                        aria-describedby={rolesDescriptionIds}
                        required={hasRoleOptions}
                    />
                </div>

                <p id={rolesHintId} className="sr-only">
                    Choose all roles that apply to this contributor.
                </p>
                {!hasRoleOptions && (
                    <p
                        id={rolesUnavailableId}
                        className="text-sm text-muted-foreground md:col-span-6 lg:col-span-8"
                        role="status"
                    >
                        No roles are available for {isPerson ? 'person' : 'institution'} contributors yet.
                    </p>
                )}

                {/* Person or Institution Fields */}
                {isPerson ? (
                    <div className="grid gap-y-4 md:grid-cols-12 md:gap-x-3">
                        {/* ORCID with Verify & Fill Button */}
                        <div className="relative flex flex-col gap-2 md:col-span-12 lg:col-span-4" data-testid={`contributor-${index}-orcid-field`}>
                            <Label htmlFor={`${contributor.id}-orcid`} className="inline-flex items-baseline flex-wrap gap-x-2">
                                <span>ORCID</span>
                                {contributor.type === 'person' && contributor.orcidVerified && (
                                    <Badge 
                                        variant="outline" 
                                        className="text-green-600 border-green-600 h-4 py-0 px-1.5 text-[10px] leading-none"
                                    >
                                        <CheckCircle2 className="h-2.5 w-2.5 mr-0.5" />
                                        Verified
                                    </Badge>
                                )}
                                {isVerifying && (
                                    <span className="text-[10px] text-muted-foreground inline-flex items-center gap-0.5">
                                        <Loader2 className="h-2.5 w-2.5 animate-spin" />
                                        Verifying...
                                    </span>
                                )}
                            </Label>
                            <div className="flex gap-2">
                                <input
                                    id={`${contributor.id}-orcid`}
                                    type="text"
                                    value={contributor.orcid || ''}
                                    onChange={(event) =>
                                        onPersonFieldChange('orcid', event.target.value)
                                    }
                                    placeholder="0000-0000-0000-0000"
                                    className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                                    inputMode="text"
                                    pattern="[0-9]{4}-[0-9]{4}-[0-9]{4}-[0-9]{3}[0-9X]"
                                />
                                {contributor.type === 'person' && (
                                    <OrcidSearchDialog
                                        onSelect={handleSelectSuggestion}
                                        triggerClassName="h-10 w-10 flex-shrink-0"
                                    />
                                )}
                            </div>
                            {verificationError && (
                                <p className="text-sm text-red-600 mt-1">
                                    {verificationError}
                                </p>
                            )}
                            {contributor.orcid && OrcidService.isValidFormat(contributor.orcid) && (
                                <a
                                    href={OrcidService.formatOrcidUrl(contributor.orcid)}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="text-xs text-blue-600 hover:underline inline-flex items-center gap-1"
                                >
                                    View on ORCID.org
                                    <ExternalLink className="h-3 w-3" />
                                </a>
                            )}
                            
                            {/* ORCID Auto-Suggestions */}
                            {showSuggestions && orcidSuggestions.length > 0 && (
                                <div className="absolute z-10 mt-1 w-full bg-white border border-gray-300 rounded-md shadow-lg max-h-60 overflow-y-auto">
                                    {isLoadingSuggestions && (
                                        <div className="p-3 text-sm text-gray-500 flex items-center gap-2">
                                            <Loader2 className="h-4 w-4 animate-spin" />
                                            Searching for matching ORCIDs...
                                        </div>
                                    )}
                                    {!isLoadingSuggestions && orcidSuggestions.map((suggestion) => (
                                        <button
                                            key={suggestion.orcid}
                                            type="button"
                                            onClick={() => handleSelectSuggestion(suggestion)}
                                            className="w-full text-left p-3 hover:bg-gray-50 border-b border-gray-100 last:border-b-0 transition-colors"
                                        >
                                            <div className="flex items-start justify-between gap-2">
                                                <div className="flex-1 min-w-0">
                                                    <p className="font-medium text-gray-900 truncate">
                                                        {suggestion.creditName || `${suggestion.firstName} ${suggestion.lastName}`}
                                                    </p>
                                                    <p className="text-xs text-gray-600 font-mono mt-1">
                                                        {suggestion.orcid}
                                                    </p>
                                                    {suggestion.institutions.length > 0 && (
                                                        <p className="text-xs text-gray-500 mt-1 truncate">
                                                            {suggestion.institutions.join(', ')}
                                                        </p>
                                                    )}
                                                </div>
                                                <ExternalLink className="h-4 w-4 text-gray-400 flex-shrink-0" />
                                            </div>
                                        </button>
                                    ))}
                                </div>
                            )}
                        </div>
                        
                        <InputField
                            id={`${contributor.id}-firstName`}
                            label="First name"
                            value={contributor.firstName || ''}
                            onChange={(event) =>
                                onPersonFieldChange('firstName', event.target.value)
                            }
                            containerProps={{ className: 'md:col-span-6 lg:col-span-4' }}
                        />
                        <InputField
                            id={`${contributor.id}-lastName`}
                            label="Last name"
                            value={contributor.lastName || ''}
                            onChange={(event) =>
                                onPersonFieldChange('lastName', event.target.value)
                            }
                            containerProps={{ className: 'md:col-span-6 lg:col-span-4' }}
                            required
                        />
                    </div>
                ) : (
                    <div className="grid gap-y-4 md:grid-cols-12 md:gap-x-3">
                        <InputField
                            id={`${contributor.id}-institution`}
                            label="Institution name"
                            value={contributor.institutionName || ''}
                            onChange={(event) => onInstitutionNameChange(event.target.value)}
                            containerProps={{ className: 'md:col-span-12' }}
                            required
                        />
                    </div>
                )}

                {/* Affiliations */}
                <div
                    className="grid gap-y-4 md:grid-cols-12 md:gap-x-3"
                    data-testid={`contributor-${index}-affiliations-grid`}
                >
                    <TagInputField
                        id={`${contributor.id}-affiliations`}
                        label="Affiliations"
                        value={contributor.affiliations as TagInputItem[]}
                        onChange={(detail) =>
                            onAffiliationsChange({
                                raw: detail.raw,
                                tags: detail.tags.map((tag) => ({
                                    value: tag.value,
                                    rorId:
                                        'rorId' in tag && typeof tag.rorId === 'string'
                                            ? tag.rorId
                                            : null,
                                })),
                            })
                        }
                        placeholder="Institution A, Institution B"
                        containerProps={{
                            className: 'md:col-span-12',
                            'data-testid': `contributor-${index}-affiliations-field`,
                        }}
                        data-testid={`contributor-${index}-affiliations-input`}
                        tagifySettings={affiliationTagifySettings}
                        aria-describedby={affiliationsDescriptionId}
                    />

                    {/* ROR ID Badges */}
                    {affiliationsWithRorId.length > 0 && (
                        <div
                            id={affiliationsDescriptionId}
                            className="col-span-full flex flex-col gap-2 md:col-span-12"
                            data-testid={`contributor-${index}-affiliations-ror-ids`}
                            aria-live="polite"
                        >
                            <p className="text-sm font-medium text-muted-foreground">
                                Linked ROR IDs
                            </p>
                            <div
                                className="flex flex-wrap gap-2"
                                role="list"
                                aria-label="Selected ROR identifiers"
                            >
                                {affiliationsWithRorId.map((affiliation) => (
                                    <Badge
                                        key={`${affiliation.rorId}-${affiliation.value}`}
                                        variant="secondary"
                                        className="gap-1 px-2 py-1 text-xs font-medium hover:bg-secondary/80 transition-colors"
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
