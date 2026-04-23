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
import { CheckCircle2, ExternalLink, GripVertical, RefreshCw, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { useAffiliationsTagify } from '@/hooks/use-affiliations-tagify';
import { useOrcidAutofill } from '@/hooks/use-orcid-autofill';
import { type OrcidSearchResult, OrcidService } from '@/services/orcid';
import type { AffiliationSuggestion, AffiliationTag } from '@/types/affiliations';

import InputField from '../input-field';
import { OrcidSearchDialog } from '../orcid-search-dialog';
import { OrcidSuggestionsButton } from '../orcid-suggestions-button';
import { SelectField } from '../select-field';
import TagInputField, { type TagInputItem } from '../tag-input-field';
import type { ContributorEntry, ContributorRoleTag, ContributorType } from './types';

interface ContributorItemProps {
    contributor: ContributorEntry;
    index: number;
    onTypeChange: (type: ContributorType) => void;
    onRolesChange: (value: { raw: string; tags: ContributorRoleTag[] }) => void;
    onPersonFieldChange: (field: 'orcid' | 'firstName' | 'lastName' | 'email' | 'website', value: string) => void;
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
    const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id: contributor.id });

    const style = {
        transform: CSS.Transform.toString(transform),
        transition,
        opacity: isDragging ? 0.5 : 1,
    };

    // Track user interactions to prevent auto-suggest / auto-verify on initial load
    // (see issue #610 – avoids ORCID network traffic when merely opening an existing resource)
    const [hasUserInteracted, setHasUserInteracted] = useState(false);

    // Wrapper for onPersonFieldChange that marks user interaction for the fields
    // that should trigger ORCID lookups (orcid for auto-verify, firstName/lastName
    // for suggestion search). Email/website do NOT flip the flag.
    const handlePersonFieldChange = (field: 'orcid' | 'firstName' | 'lastName' | 'email' | 'website', value: string) => {
        if (field === 'orcid' || field === 'firstName' || field === 'lastName') {
            setHasUserInteracted(true);
        }
        onPersonFieldChange(field, value);
    };

    // Detect whether this contributor has the "Contact Person" role
    const hasContactPersonRole = useMemo(
        () =>
            contributor.roles.some(
                (role) => role.value.replace(/\s+/g, '').toLowerCase() === 'contactperson',
            ),
        [contributor.roles],
    );

    // ORCID verification, auto-fill, and suggestions via shared hook
    const {
        isVerifying,
        verificationError,
        orcidSuggestions,
        isLoadingSuggestions,
        showSuggestions,
        hideSuggestions,
        handleOrcidSelect,
        canRetry,
        retryVerification,
        errorType,
        isFormatValid,
        pendingOrcidData,
        clearPendingOrcidData,
        applyPendingData,
    } = useOrcidAutofill({
        entry: contributor,
        onEntryChange: onContributorChange,
        hasUserInteracted,
        includeEmail: false, // Contributors don't have email field
    });

    // Handle selecting an ORCID suggestion
    const handleSelectSuggestion = async (suggestion: OrcidSearchResult) => {
        hideSuggestions();
        await handleOrcidSelect(suggestion.orcid);
    };

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

    // Affiliations Tagify settings and ROR ID extraction via shared hook
    const {
        tagifySettings: affiliationTagifySettings,
        affiliationsWithRorId,
        affiliationsDescriptionId,
    } = useAffiliationsTagify({
        affiliationSuggestions,
        affiliations: contributor.affiliations,
        idPrefix: contributor.id,
    });

    const rolesHintId = `${contributor.id}-roles-hint`;
    const rolesUnavailableId = `${contributor.id}-roles-unavailable`;
    const hasRoleOptions = roleOptions.length > 0;
    const rolesDescriptionIds = hasRoleOptions ? rolesHintId : `${rolesHintId} ${rolesUnavailableId}`.trim();

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
                        className="cursor-grab touch-none text-muted-foreground transition-colors hover:text-foreground active:cursor-grabbing"
                        {...attributes}
                        {...listeners}
                        aria-label={`Reorder contributor ${index + 1}`}
                    >
                        <GripVertical className="h-5 w-5" />
                    </button>
                    <div>
                        <h3 id={`${contributor.id}-heading`} className="text-lg leading-6 font-semibold text-foreground">
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
                        placeholder={hasRoleOptions ? 'Select one or more roles' : 'No roles available for this contributor type'}
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
                    <p id={rolesUnavailableId} className="text-sm text-muted-foreground md:col-span-6 lg:col-span-8" role="status">
                        No roles are available for {isPerson ? 'person' : 'institution'} contributors yet.
                    </p>
                )}

                {/* Person or Institution Fields */}
                {isPerson ? (
                    <div className="grid gap-y-4 md:grid-cols-12 md:gap-x-3">
                        {/* ORCID with Verify & Fill Button */}
                        <div className="relative flex flex-col gap-2 md:col-span-12 lg:col-span-4" data-testid={`contributor-${index}-orcid-field`}>
                            <div className="inline-flex flex-wrap items-baseline gap-x-2">
                                <Label htmlFor={`${contributor.id}-orcid`}>ORCID</Label>
                                {contributor.type === 'person' && contributor.orcidVerified && (
                                    <Tooltip>
                                        <TooltipTrigger asChild>
                                            <button
                                                type="button"
                                                onClick={retryVerification}
                                                aria-label="Re-verify ORCID"
                                                className="inline-flex cursor-pointer items-center border-green-600 text-green-600 transition-opacity hover:opacity-70"
                                            >
                                                <Badge variant="outline" className="h-4 border-green-600 px-1.5 py-0 text-[10px] leading-none text-green-600">
                                                    <CheckCircle2 className="mr-0.5 h-2.5 w-2.5" />
                                                    Verified
                                                </Badge>
                                            </button>
                                        </TooltipTrigger>
                                        <TooltipContent>ORCID verified — click to re-verify</TooltipContent>
                                    </Tooltip>
                                )}
                                {isVerifying && (
                                    <span className="inline-flex items-center gap-0.5 text-[10px] text-muted-foreground">
                                        <Spinner size="xs" />
                                        Verifying...
                                    </span>
                                )}
                            </div>
                            <span id={`${contributor.id}-orcid-status`} className="sr-only">
                                {contributor.type === 'person' && contributor.orcidVerified ? 'ORCID verified' : ''}
                            </span>
                            <div className="flex gap-2">
                                <Input
                                    id={`${contributor.id}-orcid`}
                                    type="text"
                                    value={contributor.orcid || ''}
                                    onChange={(event) => handlePersonFieldChange('orcid', event.target.value)}
                                    placeholder="0000-0000-0000-0000"
                                    inputMode="text"
                                    pattern="[0-9]{4}-[0-9]{4}-[0-9]{4}-[0-9]{3}[0-9X]"
                                    aria-describedby={`${contributor.id}-orcid-status`}
                                />
                                {contributor.type === 'person' && (
                                    <OrcidSearchDialog onSelect={handleSelectSuggestion} triggerClassName="h-10 w-10 shrink-0" />
                                )}
                            </div>
                            {/* Error display with retry button */}
                            {verificationError && (
                                <div className="mt-1 flex items-center gap-2">
                                    <p className="text-sm text-red-600">{verificationError}</p>
                                    {canRetry && (
                                        <Button type="button" variant="ghost" size="sm" onClick={retryVerification} className="h-6 px-2 text-xs">
                                            <RefreshCw className="mr-1 h-3 w-3" />
                                            Retry
                                        </Button>
                                    )}
                                </div>
                            )}
                            {/* Format valid badge when checksum is valid but online verification failed */}
                            {isFormatValid &&
                                !contributor.orcidVerified &&
                                (errorType === 'timeout' || errorType === 'api_error' || errorType === 'network') && (
                                    <Badge
                                        variant="outline"
                                        className="mt-1 h-4 border-yellow-600 px-1.5 py-0 text-[10px] leading-none text-yellow-600"
                                    >
                                        Format valid (unverified)
                                    </Badge>
                                )}
                            {contributor.orcid && OrcidService.isValidFormat(contributor.orcid) && (
                                <a
                                    href={OrcidService.formatOrcidUrl(contributor.orcid)}
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
                                            <Spinner size="sm" />
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
                            id={`${contributor.id}-firstName`}
                            label="First name"
                            value={contributor.firstName || ''}
                            onChange={(event) => handlePersonFieldChange('firstName', event.target.value)}
                            containerProps={{ className: 'md:col-span-6 lg:col-span-4' }}
                        />
                        <InputField
                            id={`${contributor.id}-lastName`}
                            label="Last name"
                            value={contributor.lastName || ''}
                            onChange={(event) => handlePersonFieldChange('lastName', event.target.value)}
                            containerProps={{ className: 'md:col-span-6 lg:col-span-4' }}
                            required
                        />

                        {/* Contact Person email & website fields */}
                        {hasContactPersonRole && (
                            <>
                                <InputField
                                    id={`${contributor.id}-email`}
                                    label="Email"
                                    type="email"
                                    value={contributor.email || ''}
                                    onChange={(event) => handlePersonFieldChange('email', event.target.value)}
                                    containerProps={{ className: 'md:col-span-6 lg:col-span-4' }}
                                    required
                                />
                                <InputField
                                    id={`${contributor.id}-website`}
                                    label="Website"
                                    type="url"
                                    value={contributor.website || ''}
                                    onChange={(event) => handlePersonFieldChange('website', event.target.value)}
                                    containerProps={{ className: 'md:col-span-6 lg:col-span-4' }}
                                />
                            </>
                        )}
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
                <div className="grid gap-y-4 md:grid-cols-12 md:gap-x-3" data-testid={`contributor-${index}-affiliations-grid`}>
                    <TagInputField
                        id={`${contributor.id}-affiliations`}
                        label="Affiliations"
                        value={contributor.affiliations as TagInputItem[]}
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
                            className: 'md:col-span-12',
                            'data-testid': `contributor-${index}-affiliations-field`,
                        }}
                        data-testid={`contributor-${index}-affiliations-input`}
                        tagifySettings={affiliationTagifySettings}
                        aria-describedby={affiliationsDescriptionId}
                    />

                    {/* ORCID Suggestions Button */}
                    {pendingOrcidData && (
                        <div className="col-span-full flex items-center">
                            <OrcidSuggestionsButton
                                pendingData={pendingOrcidData}
                                onAccept={applyPendingData}
                                onDiscard={clearPendingOrcidData}
                            />
                        </div>
                    )}

                    {/* ROR ID Badges */}
                    {affiliationsWithRorId.length > 0 && (
                        <div
                            id={affiliationsDescriptionId}
                            className="col-span-full flex flex-col gap-2 md:col-span-12"
                            data-testid={`contributor-${index}-affiliations-ror-ids`}
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
