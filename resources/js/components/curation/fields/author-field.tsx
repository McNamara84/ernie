import { Minus, Plus } from 'lucide-react';
import InputField from './input-field';
import { SelectField } from './select-field';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

export type AuthorType = 'person' | 'institution';

export interface AffiliationEntry {
    id: string;
    value: string;
}

export interface PersonAuthorEntry {
    id: string;
    type: 'person';
    orcid: string;
    firstName: string;
    lastName: string;
    email: string;
    website: string;
    isContact: boolean;
    affiliations: AffiliationEntry[];
}

export interface InstitutionAuthorEntry {
    id: string;
    type: 'institution';
    institutionName: string;
    affiliations: AffiliationEntry[];
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
    onContactChange: (checked: boolean) => void;
    onAffiliationChange: (affiliationId: string, value: string) => void;
    onAddAffiliation: () => void;
    onRemoveAffiliation: (affiliationId: string) => void;
    onRemoveAuthor: () => void;
    canRemove: boolean;
}

export function AuthorField({
    author,
    index,
    onTypeChange,
    onPersonFieldChange,
    onInstitutionNameChange,
    onContactChange,
    onAffiliationChange,
    onAddAffiliation,
    onRemoveAffiliation,
    onRemoveAuthor,
    canRemove,
}: AuthorFieldProps) {
    const isPerson = author.type === 'person';
    const contactLabelTextId = `${author.id}-contact-label-text`;

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
                    <p className="mt-1 text-sm text-muted-foreground">
                        Provide details for this author and their affiliations.
                    </p>
                </div>
                {canRemove && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={onRemoveAuthor}
                        aria-label={`Remove author ${index + 1}`}
                        className="self-end"
                    >
                        Remove
                    </Button>
                )}
            </div>

            <div className="mt-6 grid gap-4 md:grid-cols-12">
                <SelectField
                    id={`${author.id}-type`}
                    label="Author type"
                    value={author.type}
                    onValueChange={(value) => onTypeChange(value as AuthorType)}
                    options={[
                        { value: 'person', label: 'Person' },
                        { value: 'institution', label: 'Institution' },
                    ]}
                    className="md:col-span-2 md:max-w-[12rem]"
                    containerProps={{ 'data-testid': `author-${index}-type-field` }}
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
                            className="md:col-span-3 md:max-w-[20ch]"
                            containerProps={{ 'data-testid': `author-${index}-orcid-field` }}
                            inputMode="numeric"
                            pattern="\\d{4}-\\d{4}-\\d{4}-\\d{4}(\\d{3}[0-9X])?"
                            aria-describedby={`${author.id}-orcid-help`}
                        />
                        <InputField
                            id={`${author.id}-lastName`}
                            label="Last name"
                            value={author.lastName}
                            onChange={(event) =>
                                onPersonFieldChange('lastName', event.target.value)
                            }
                            className="md:col-span-3"
                            required
                        />
                        <InputField
                            id={`${author.id}-firstName`}
                            label="First name"
                            value={author.firstName}
                            onChange={(event) =>
                                onPersonFieldChange('firstName', event.target.value)
                            }
                            className="md:col-span-2"
                        />
                        <div
                            className="md:col-span-2 flex items-start gap-2 md:items-center"
                            data-testid={`author-${index}-contact-field`}
                        >
                            <Checkbox
                                id={`${author.id}-contact`}
                                checked={author.isContact}
                                onCheckedChange={(checked) =>
                                    onContactChange(checked === true)
                                }
                                aria-describedby={`${author.id}-contact-hint`}
                                aria-labelledby={contactLabelTextId}
                            />
                            <div className="flex flex-col gap-1">
                                <Tooltip>
                                    <TooltipTrigger asChild>
                                        <span className="inline-flex cursor-help">
                                            <Label htmlFor={`${author.id}-contact`} className="font-medium">
                                                <span aria-hidden="true">CP</span>
                                                <span id={contactLabelTextId} className="sr-only">
                                                    Contact person
                                                </span>
                                            </Label>
                                        </span>
                                    </TooltipTrigger>
                                    <TooltipContent side="top">
                                        Contact Person: Select if this author should be the primary
                                        contact.
                                    </TooltipContent>
                                </Tooltip>
                                <p id={`${author.id}-contact-hint`} className="sr-only">
                                    Contact Person: Select if this author should be the primary
                                    contact.
                                </p>
                            </div>
                        </div>
                        <p
                            id={`${author.id}-orcid-help`}
                            className="md:col-span-12 text-xs text-muted-foreground"
                        >
                            Use the 16-digit ORCID identifier when available.
                        </p>
                        {author.isContact && (
                            <>
                                <InputField
                                    id={`${author.id}-email`}
                                    type="email"
                                    label="Email address"
                                    value={author.email}
                                    onChange={(event) =>
                                        onPersonFieldChange('email', event.target.value)
                                    }
                                    className="md:col-span-4"
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
                                    className="md:col-span-4"
                                />
                            </>
                        )}
                    </>
                ) : (
                    <InputField
                        id={`${author.id}-institution`}
                        label="Institution name"
                        value={author.institutionName}
                        onChange={(event) => onInstitutionNameChange(event.target.value)}
                        className="md:col-span-9"
                        required
                    />
                )}
            </div>

            <div className="mt-6 space-y-3">
                <Label className="text-sm font-medium">Affiliations</Label>
                <div className="space-y-2">
                    {author.affiliations.map((affiliation, affiliationIndex) => (
                        <div
                            key={affiliation.id}
                            className={cn(
                                'flex flex-col gap-2 md:flex-row md:items-center md:gap-3'
                            )}
                        >
                            <InputField
                                id={`${author.id}-affiliation-${affiliation.id}`}
                                label={
                                    affiliationIndex === 0
                                        ? 'Affiliation'
                                        : `Affiliation ${affiliationIndex + 1}`
                                }
                                hideLabel={affiliationIndex > 0}
                                value={affiliation.value}
                                onChange={(event) =>
                                    onAffiliationChange(affiliation.id, event.target.value)
                                }
                                className="flex-1"
                            />
                            {author.affiliations.length > 1 && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon"
                                    onClick={() => onRemoveAffiliation(affiliation.id)}
                                    aria-label={`Remove affiliation ${affiliationIndex + 1}`}
                                >
                                    <Minus className="h-4 w-4" />
                                </Button>
                            )}
                        </div>
                    ))}
                </div>
                <Button
                    type="button"
                    variant="secondary"
                    size="sm"
                    onClick={onAddAffiliation}
                    className="mt-2"
                >
                    <Plus className="mr-2 h-4 w-4" /> Add affiliation
                </Button>
            </div>
        </section>
    );
}

export default AuthorField;
