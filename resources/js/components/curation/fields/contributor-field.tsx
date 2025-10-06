import { useMemo } from 'react';
import { Plus, Trash2 } from 'lucide-react';
import InputField from './input-field';
import { SelectField } from './select-field';
import TagInputField from './tag-input-field';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type { AffiliationSuggestion, AffiliationTag } from '@/types/affiliations';
import type { TagData, TagifySettings } from '@yaireo/tagify';

export const CONTRIBUTOR_ROLE_OPTIONS = [
    'Contact Person',
    'Data Collector',
    'Data Curator',
    'Data Manager',
    'Distributor',
    'Editor',
    'Hosting Institution',
    'Producer',
    'Project Leader',
    'Project Manager',
    'Project Member',
    'Registration Agency',
    'Registration Authority',
    'Related Person',
    'Researcher',
    'Research Group',
    'Rights Holder',
    'Sponsor',
    'Supervisor',
    'Translator',
    'WorkPackage Leader',
    'Other',
] as const;

export type ContributorRole = (typeof CONTRIBUTOR_ROLE_OPTIONS)[number];
export type ContributorType = 'person' | 'institution';

export interface ContributorRoleTag {
    value: ContributorRole;
}

interface BaseContributorEntry {
    id: string;
    type: ContributorType;
    roles: ContributorRoleTag[];
    rolesInput: string;
    affiliations: AffiliationTag[];
    affiliationsInput: string;
}

export interface PersonContributorEntry extends BaseContributorEntry {
    type: 'person';
    orcid: string;
    firstName: string;
    lastName: string;
}

export interface InstitutionContributorEntry extends BaseContributorEntry {
    type: 'institution';
    institutionName: string;
}

export type ContributorEntry = PersonContributorEntry | InstitutionContributorEntry;

interface ContributorFieldProps {
    contributor: ContributorEntry;
    index: number;
    onTypeChange: (type: ContributorType) => void;
    onRolesChange: (value: { raw: string; tags: ContributorRoleTag[] }) => void;
    onPersonFieldChange: (
        field: 'orcid' | 'firstName' | 'lastName',
        value: string,
    ) => void;
    onInstitutionNameChange: (value: string) => void;
    onAffiliationsChange: (value: { raw: string; tags: AffiliationTag[] }) => void;
    onRemoveContributor: () => void;
    canRemove: boolean;
    onAddContributor: () => void;
    canAddContributor: boolean;
    affiliationSuggestions: AffiliationSuggestion[];
}

export default function ContributorField({
    contributor,
    index,
    onTypeChange,
    onRolesChange,
    onPersonFieldChange,
    onInstitutionNameChange,
    onAffiliationsChange,
    onRemoveContributor,
    canRemove,
    onAddContributor,
    canAddContributor,
    affiliationSuggestions,
}: ContributorFieldProps) {
    const isPerson = contributor.type === 'person';

    const roleWhitelist = useMemo(() => {
        return CONTRIBUTOR_ROLE_OPTIONS.map((role) => ({ value: role }));
    }, []);

    const roleTagifySettings = useMemo<Partial<TagifySettings<TagData>>>(() => {
        return {
            whitelist: roleWhitelist,
            enforceWhitelist: true,
            maxTags: roleWhitelist.length,
            dropdown: {
                enabled: roleWhitelist.length > 0 ? 1 : 0,
                maxItems: roleWhitelist.length,
                searchKeys: ['value'],
            },
        };
    }, [roleWhitelist]);

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
                        onClick={onRemoveContributor}
                        aria-label={`Remove contributor ${index + 1}`}
                        className="self-end"
                    >
                        <Trash2 className="h-4 w-4" />
                    </Button>
                )}
            </div>

            <div className="mt-6 grid md:grid-cols-[1fr_auto] md:gap-x-3">
                <div className="space-y-4">
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
                            value={contributor.roles}
                            onChange={(detail) =>
                                onRolesChange({
                                    raw: detail.raw,
                                    tags: detail.tags.map((tag) => ({
                                        value: tag.value as ContributorRole,
                                    })),
                                })
                            }
                            placeholder="Select one or more roles"
                            containerProps={{
                                className: 'md:col-span-6 lg:col-span-8',
                                'data-testid': `contributor-${index}-roles-field`,
                            }}
                            data-testid={`contributor-${index}-roles-input`}
                            tagifySettings={roleTagifySettings}
                            aria-describedby={rolesHintId}
                            required
                        />
                    </div>

                    <p id={rolesHintId} className="sr-only">
                        Choose all roles that apply to this contributor.
                    </p>

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
                                pattern="^\\d{4}-\\d{4}-\\d{4}-\\d{3}[\\dX]$"
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

                    <div
                        className="grid gap-y-4 md:grid-cols-12 md:gap-x-3"
                        data-testid={`contributor-${index}-affiliations-grid`}
                    >
                        <TagInputField
                            id={`${contributor.id}-affiliations`}
                            label="Affiliations"
                            value={contributor.affiliations}
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

                {canAddContributor && (
                    <div className="hidden md:flex md:items-center md:self-center">
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            aria-label="Add contributor"
                            onClick={onAddContributor}
                        >
                            <Plus className="h-4 w-4" />
                        </Button>
                    </div>
                )}
            </div>

            {canAddContributor && (
                <div className="mt-4 flex justify-end md:hidden">
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        aria-label="Add contributor"
                        onClick={onAddContributor}
                    >
                        <Plus className="h-4 w-4" />
                    </Button>
                </div>
            )}
        </section>
    );
}
