/**
 * ContributorItem Component
 * 
 * Individual contributor entry with all fields.
 * Supports both person and institution types with roles.
 * Migrated from contributor-field.tsx
 */

import type { TagData, TagifySettings } from '@yaireo/tagify';
import { Trash2 } from 'lucide-react';
import { useMemo } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type { AffiliationSuggestion, AffiliationTag } from '@/types/affiliations';

import InputField from '../input-field';
import { SelectField } from '../select-field';
import TagInputField, { type TagInputItem } from '../tag-input-field';
import type { ContributorEntry, ContributorRoleTag, ContributorType } from './types';

interface ContributorItemProps {
    contributor: ContributorEntry;
    index: number;
    onTypeChange: (type: ContributorType) => void;
    onRolesChange: (value: { raw: string; tags: ContributorRoleTag[] }) => void;
    onPersonFieldChange: (field: 'orcid' | 'firstName' | 'lastName', value: string) => void;
    onInstitutionNameChange: (value: string) => void;
    onAffiliationsChange: (value: { raw: string; tags: AffiliationTag[] }) => void;
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
    onRemove,
    canRemove,
    affiliationSuggestions,
    personRoleOptions,
    institutionRoleOptions,
}: ContributorItemProps) {
    const isPerson = contributor.type === 'person';

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
                        <InputField
                            id={`${contributor.id}-orcid`}
                            label="ORCID"
                            value={contributor.orcid}
                            onChange={(event) =>
                                onPersonFieldChange('orcid', event.target.value)
                            }
                            placeholder="0000-0000-0000-0000"
                            containerProps={{
                                'data-testid': `contributor-${index}-orcid-field`,
                                className: 'md:col-span-12 lg:col-span-4',
                            }}
                            inputClassName="w-full"
                            inputMode="text"
                            pattern="[0-9]{4}-[0-9]{4}-[0-9]{4}-[0-9]{3}[0-9X]"
                        />
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
