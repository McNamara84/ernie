import { Plus } from 'lucide-react';
import InputField from './input-field';
import { SelectField } from './select-field';
import TagInputField from './tag-input-field';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

export type AuthorType = 'person' | 'institution';

interface BaseAuthorEntry {
    id: string;
    affiliations: string[];
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
    onAffiliationsChange: (value: { raw: string; tags: string[] }) => void;
    onRemoveAuthor: () => void;
    canRemove: boolean;
    onAddAuthor: () => void;
    canAddAuthor: boolean;
}

export function AuthorField({
    author,
    index,
    onTypeChange,
    onPersonFieldChange,
    onInstitutionNameChange,
    onContactChange,
    onAffiliationsChange,
    onRemoveAuthor,
    canRemove,
    onAddAuthor,
    canAddAuthor,
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

            <div
                className="mt-6 grid gap-y-4 md:grid-cols-12 md:gap-x-3"
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
                    triggerClassName="w-full md:w-[8.5rem]"
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
                            inputClassName="w-full md:max-w-[19ch]"
                            inputMode="numeric"
                            pattern="\\d{4}-\\d{4}-\\d{4}-\\d{4}(\\d{3}[0-9X])?"
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
                            className="md:col-span-1 flex flex-col items-start gap-2"
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
                    <InputField
                        id={`${author.id}-institution`}
                        label="Institution name"
                        value={author.institutionName}
                        onChange={(event) => onInstitutionNameChange(event.target.value)}
                        className="md:col-span-9"
                        required
                    />
                )}

                {canAddAuthor && (
                    <div className="flex items-end md:col-span-1 md:justify-end">
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

            <div className="mt-6 space-y-3">
                <div
                    className="grid gap-y-3 md:grid-cols-12 md:gap-x-3"
                    data-testid={`author-${index}-affiliations-grid`}
                >
                    <TagInputField
                        id={`${author.id}-affiliations`}
                        label="Affiliations"
                        value={author.affiliations}
                        onChange={(detail) => onAffiliationsChange(detail)}
                        placeholder="Institution A, Institution B"
                        containerProps={{
                            className: cn(
                                'md:col-span-12',
                                isPerson && author.isContact
                                    ? 'md:col-span-6'
                                    : 'md:col-span-12',
                            ),
                            'data-testid': `author-${index}-affiliations-field`,
                        }}
                        data-testid={`author-${index}-affiliations-input`}
                    />
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
        </section>
    );
}

export default AuthorField;
