/**
 * ContributorItem Component
 * 
 * Individual contributor entry with all fields.
 * Supports both person and institution types with roles.
 * Migrated from contributor-field.tsx
 */

import type { TagData, TagifySettings } from '@yaireo/tagify';
import { CheckCircle2, Loader2, Trash2, ExternalLink } from 'lucide-react';
import { useMemo, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import type { AffiliationSuggestion, AffiliationTag } from '@/types/affiliations';
import { OrcidService } from '@/services/orcid';

import InputField from '../input-field';
import { SelectField } from '../select-field';
import TagInputField, { type TagInputItem } from '../tag-input-field';
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

    // ORCID Auto-Fill State
    const [isVerifying, setIsVerifying] = useState(false);
    const [verificationError, setVerificationError] = useState<string | null>(null);

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
     * Handle ORCID verification and auto-fill
     */
    const handleVerifyAndFill = async () => {
        if (contributor.type !== 'person' || !contributor.orcid) {
            return;
        }

        setIsVerifying(true);
        setVerificationError(null);

        try {
            const result = await OrcidService.fetchOrcidRecord(contributor.orcid);

            if (!result.success || !result.data) {
                setVerificationError(result.error || 'Failed to fetch ORCID data');
                setIsVerifying(false);
                return;
            }

            const data = result.data;

            // Prepare updated contributor with auto-filled data
            const updatedContributor: PersonContributorEntry = {
                ...contributor,
                firstName: data.firstName && !contributor.firstName ? data.firstName : contributor.firstName,
                lastName: data.lastName && !contributor.lastName ? data.lastName : contributor.lastName,
                orcidVerified: true,
                orcidVerifiedAt: new Date().toISOString(),
            };

            // Auto-fill affiliations (only employment, not education)
            if (data.affiliations.length > 0) {
                const employmentAffiliations = data.affiliations
                    .filter(aff => aff.type === 'employment' && aff.name)
                    .map(aff => ({
                        value: aff.name!,
                        rorId: null,
                    }));

                if (employmentAffiliations.length > 0) {
                    // Merge with existing affiliations
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

            // Apply all changes at once
            onContributorChange(updatedContributor);

            setIsVerifying(false);
        } catch (error) {
            console.error('ORCID verification error:', error);
            setVerificationError('An unexpected error occurred');
            setIsVerifying(false);
        }
    };

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
            className="rounded-lg border border-border bg-card p-6 shadow-sm transition hover:shadow-md"
            aria-labelledby={`${contributor.id}-heading`}
        >
            {/* Header with Remove Button */}
            <div className="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                <div>
                    <h3
                        id={`${contributor.id}-heading`}
                        className="text-lg font-semibold leading-6 text-foreground"
                    >
                        Contributor {index + 1}
                    </h3>
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
                        <div className="md:col-span-12 lg:col-span-4" data-testid={`contributor-${index}-orcid-field`}>
                            <Label htmlFor={`${contributor.id}-orcid`}>
                                ORCID
                                {contributor.type === 'person' && contributor.orcidVerified && (
                                    <Badge 
                                        variant="outline" 
                                        className="ml-2 text-green-600 border-green-600"
                                    >
                                        <CheckCircle2 className="h-3 w-3 mr-1" />
                                        Verified
                                    </Badge>
                                )}
                            </Label>
                            <div className="flex gap-2 mt-1.5">
                                <input
                                    id={`${contributor.id}-orcid`}
                                    type="text"
                                    value={contributor.orcid}
                                    onChange={(event) =>
                                        onPersonFieldChange('orcid', event.target.value)
                                    }
                                    placeholder="0000-0000-0000-0000"
                                    className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                                    inputMode="text"
                                    pattern="[0-9]{4}-[0-9]{4}-[0-9]{4}-[0-9]{3}[0-9X]"
                                />
                                <Tooltip>
                                    <TooltipTrigger asChild>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="icon"
                                            onClick={handleVerifyAndFill}
                                            disabled={!contributor.orcid || !OrcidService.isValidFormat(contributor.orcid) || isVerifying}
                                            aria-label="Verify ORCID and auto-fill fields"
                                        >
                                            {isVerifying ? (
                                                <Loader2 className="h-4 w-4 animate-spin" />
                                            ) : (
                                                <ExternalLink className="h-4 w-4" />
                                            )}
                                        </Button>
                                    </TooltipTrigger>
                                    <TooltipContent>
                                        <p>Verify ORCID & auto-fill</p>
                                    </TooltipContent>
                                </Tooltip>
                            </div>
                            {verificationError && (
                                <p className="text-sm text-red-600 mt-1.5">
                                    {verificationError}
                                </p>
                            )}
                            {contributor.orcid && OrcidService.isValidFormat(contributor.orcid) && (
                                <a
                                    href={OrcidService.formatOrcidUrl(contributor.orcid)}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="text-xs text-blue-600 hover:underline inline-flex items-center gap-1 mt-1.5"
                                >
                                    View on ORCID.org
                                    <ExternalLink className="h-3 w-3" />
                                </a>
                            )}
                        </div>
                        <InputField
                            id={`${contributor.id}-firstName`}
                            label="First name"
                            value={contributor.firstName}
                            onChange={(event) =>
                                onPersonFieldChange('firstName', event.target.value)
                            }
                            containerProps={{ className: 'md:col-span-6 lg:col-span-4' }}
                        />
                        <InputField
                            id={`${contributor.id}-lastName`}
                            label="Last name"
                            value={contributor.lastName}
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
                            value={contributor.institutionName}
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
