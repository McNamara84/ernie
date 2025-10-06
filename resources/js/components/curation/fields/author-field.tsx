import { useMemo } from 'react';
import { Plus, Trash2 } from 'lucide-react';
import InputField from './input-field';
import { SelectField } from './select-field';
import TagInputField from './tag-input-field';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import type { AffiliationSuggestion, AffiliationTag } from '@/types/affiliations';
import type { TagData, TagifySettings } from '@yaireo/tagify';

export type AuthorType = 'person' | 'institution';

interface BaseAuthorEntry {
    id: string;
    affiliations: AffiliationTag[];
    affiliationsInput: string;
}

export interface PersonAuthorEntry extends BaseAuthorEntry {
    type: 'person';
    orcid: string;
    firstName: string;
    lastName: string;
    email: string;
    website: string;
    isContact: boolean;
}

export interface InstitutionAuthorEntry extends BaseAuthorEntry {
    type: 'institution';
    institutionName: string;
    rorId: string;
}

export type AuthorEntry = PersonAuthorEntry | InstitutionAuthorEntry;

interface AuthorFieldProps {
    author: AuthorEntry;
    index: number;
    onTypeChange: (type: AuthorType) => void;
    onPersonFieldChange: (
        field: 'orcid' | 'firstName' | 'lastName' | 'email' | 'website',
        value: string,
    ) => void;
    onInstitutionNameChange: (value: string) => void;
    onInstitutionRorIdChange: (value: string) => void;
    onContactChange: (checked: boolean) => void;
    onAffiliationsChange: (value: { raw: string; tags: AffiliationTag[] }) => void;
    onRemoveAuthor: () => void;
    canRemove: boolean;
    onAddAuthor: () => void;
    canAddAuthor: boolean;
    affiliationSuggestions: AffiliationSuggestion[];
}

export function AuthorField({
    author,
    index,
    onTypeChange,
    onPersonFieldChange,
    onInstitutionNameChange,
    onInstitutionRorIdChange,
    onContactChange,
    onAffiliationsChange,
    onRemoveAuthor,
    canRemove,
    onAddAuthor,
    canAddAuthor,
    affiliationSuggestions,
}: AuthorFieldProps) {
    const isPerson = author.type === 'person';
    const contactLabelTextId = `${author.id}-contact-label-text`;
    const affiliationsWithRorId = useMemo(() => {
        const seen = new Set<string>();

        return author.affiliations.reduce<{ value: string; rorId: string }[]>((accumulator, affiliation) => {
            const value = affiliation.value.trim();
            const rorId = typeof affiliation.rorId === 'string' ? affiliation.rorId.trim() : '';

            if (!value || !rorId || seen.has(rorId)) {
                return accumulator;
            }

            seen.add(rorId);
            accumulator.push({ value, rorId });
            return accumulator;
        }, []);
    }, [author.affiliations]);
    const affiliationsDescriptionId =
        affiliationsWithRorId.length > 0 ? `${author.id}-affiliations-ror-description` : undefined;

    const tagifySettings = useMemo<Partial<TagifySettings<TagData>>>(() => {
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
            aria-labelledby={`${author.id}-heading`}
        >
            <div className="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                <div>
                    <h3
                        id={`${author.id}-heading`}
                        className="text-lg font-semibold leading-6 text-foreground"
                    >
                        Author {index + 1}
                    </h3>
                </div>
                {canRemove && (
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        onClick={onRemoveAuthor}
                        aria-label={`Remove author ${index + 1}`}
                        className="self-end"
                    >
                        <Trash2 className="h-4 w-4" />
                    </Button>
                )}
            </div>

            <div className="mt-6 grid md:grid-cols-[1fr_auto] md:gap-x-3">
                <div className="space-y-4">
                    <div
                        className="grid gap-y-4 md:grid-cols-12 md:gap-x-3"
                        data-testid={`author-${index}-fields-grid`}
                    >
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
                                <InputField
                                    id={`${author.id}-orcid`}
                                    label="ORCID"
                                    value={author.orcid}
                                    onChange={(event) =>
                                        onPersonFieldChange('orcid', event.target.value)
                                    }
                                    placeholder="0000-0000-0000-0000"
                                    containerProps={{
                                        'data-testid': `author-${index}-orcid-field`,
                                        className: 'md:col-span-3',
                                    }}
                                    inputClassName="w-full"
                                    inputMode="text"
                                    pattern="^\d{4}-\d{4}-\d{4}-\d{3}[\dX]$"
                                />
                                <InputField
                                    id={`${author.id}-firstName`}
                                    label="First name"
                                    value={author.firstName}
                                    onChange={(event) =>
                                        onPersonFieldChange('firstName', event.target.value)
                                    }
                                    containerProps={{ className: 'md:col-span-3' }}
                                />
                                <InputField
                                    id={`${author.id}-lastName`}
                                    label="Last name"
                                    value={author.lastName}
                                    onChange={(event) =>
                                        onPersonFieldChange('lastName', event.target.value)
                                    }
                                    containerProps={{ className: 'md:col-span-3' }}
                                    required
                                />
                                <div
                                    className="flex flex-col items-start gap-2 md:col-span-1"
                                    data-testid={`author-${index}-contact-field`}
                                >
                                    <Tooltip>
                                        <TooltipTrigger asChild>
                                            <Label
                                                htmlFor={`${author.id}-contact`}
                                                className="cursor-help font-medium inline-flex"
                                            >
                                                <span aria-hidden="true">CP</span>
                                                <span id={contactLabelTextId} className="sr-only">
                                                    Contact person
                                                </span>
                                            </Label>
                                        </TooltipTrigger>
                                        <TooltipContent side="top">
                                            Contact Person: Select if this author should be the primary
                                            contact.
                                        </TooltipContent>
                                    </Tooltip>
                                    <Checkbox
                                        id={`${author.id}-contact`}
                                        checked={author.isContact}
                                        onCheckedChange={(checked) => onContactChange(checked === true)}
                                        aria-describedby={`${author.id}-contact-hint`}
                                        aria-labelledby={contactLabelTextId}
                                    />
                                    <p id={`${author.id}-contact-hint`} className="sr-only">
                                        Contact Person: Select if this author should be the primary
                                        contact.
                                    </p>
                                </div>
                            </>
                        ) : (
                            <>
                                <InputField
                                    id={`${author.id}-institution`}
                                    label="Institution name"
                                    value={author.institutionName}
                                    onChange={(event) => onInstitutionNameChange(event.target.value)}
                                    containerProps={{ className: 'md:col-span-7' }}
                                    required
                                />
                                <InputField
                                    id={`${author.id}-rorId`}
                                    label="ROR ID"
                                    value={author.rorId}
                                    onChange={(event) => onInstitutionRorIdChange(event.target.value)}
                                    placeholder="https://ror.org/"
                                    inputMode="url"
                                    containerProps={{ className: 'md:col-span-4' }}
                                />
                            </>
                        )}
                    </div>

                    <div
                        className="grid gap-y-4 md:grid-cols-12 md:gap-x-3"
                        data-testid={`author-${index}-affiliations-grid`}
                    >
                        <TagInputField
                            id={`${author.id}-affiliations`}
                            label="Affiliations"
                            value={author.affiliations}
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
                                className: isPerson && author.isContact ? 'md:col-span-5' : 'md:col-span-11',
                                'data-testid': `author-${index}-affiliations-field`,
                            }}
                            data-testid={`author-${index}-affiliations-input`}
                            tagifySettings={tagifySettings}
                            aria-describedby={affiliationsDescriptionId}
                        />
                        {affiliationsWithRorId.length > 0 && (
                            <div
                                id={affiliationsDescriptionId}
                                className="col-span-full flex flex-col gap-2 md:col-span-12"
                                data-testid={`author-${index}-affiliations-ror-ids`}
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
                        {isPerson && author.isContact && (
                            <>
                                <InputField
                                    id={`${author.id}-email`}
                                    type="email"
                                    label="Email address"
                                    value={author.email}
                                    onChange={(event) =>
                                        onPersonFieldChange('email', event.target.value)
                                    }
                                    containerProps={{ className: 'md:col-span-3' }}
                                    required
                                />
                                <InputField
                                    id={`${author.id}-website`}
                                    type="url"
                                    label="Website"
                                    value={author.website}
                                    onChange={(event) =>
                                        onPersonFieldChange('website', event.target.value)
                                    }
                                    placeholder="https://example.org"
                                    containerProps={{ className: 'md:col-span-3' }}
                                />
                            </>
                        )}
                    </div>
                </div>

                {canAddAuthor && (
                    <div className="hidden md:flex md:items-center md:self-center">
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            aria-label="Add author"
                            onClick={onAddAuthor}
                        >
                            <Plus className="h-4 w-4" />
                        </Button>
                    </div>
                )}
            </div>

            {canAddAuthor && (
                <div className="flex justify-end mt-4 md:hidden">
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        aria-label="Add author"
                        onClick={onAddAuthor}
                    >
                        <Plus className="h-4 w-4" />
                    </Button>
                </div>
            )}
        </section>
    );
}

export default AuthorField;
