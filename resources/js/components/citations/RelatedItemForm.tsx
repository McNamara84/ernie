import { zodResolver } from '@hookform/resolvers/zod';
import { Plus, Search, Trash2 } from 'lucide-react';
import { useEffect } from 'react';
import { type Resolver, useFieldArray, useForm } from 'react-hook-form';

import {
    Accordion,
    AccordionContent,
    AccordionItem,
    AccordionTrigger,
} from '@/components/ui/accordion';
import { Button } from '@/components/ui/button';
import {
    Form,
    FormControl,
    FormField,
    FormItem,
    FormLabel,
    FormMessage,
} from '@/components/ui/form';
import { Input } from '@/components/ui/input';
import { LoadingButton } from '@/components/ui/loading-button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useCitationLookup } from '@/hooks/use-citation-lookup';
import { relatedItemSchema, type RelatedItemInput } from '@/lib/validations/related-item';
import type { RelatedItem } from '@/types/related-item';

export interface RelatedItemFormOption {
    value: string;
    label: string;
}
export interface RelationTypeOption {
    id: number;
    label: string;
}

interface RelatedItemFormProps {
    initialValue?: Partial<RelatedItem>;
    resourceTypes: RelatedItemFormOption[];
    relationTypes: RelationTypeOption[];
    contributorTypes: RelatedItemFormOption[];
    onSubmit: (values: RelatedItemInput) => Promise<void> | void;
    onCancel?: () => void;
    submitting?: boolean;
    /** Whether the DOI autofill feature is enabled. Defaults to true. */
    enableLookup?: boolean;
}

function emptyDefaults(): RelatedItemInput {
    return {
        related_item_type: '',
        relation_type_id: 0,
        publication_year: null,
        volume: null,
        issue: null,
        number: null,
        number_type: null,
        first_page: null,
        last_page: null,
        publisher: null,
        edition: null,
        identifier: null,
        identifier_type: null,
        position: 0,
        titles: [{ title: '', title_type: 'MainTitle', position: 0 }],
        creators: [],
        contributors: [],
    };
}

/**
 * Form for creating or editing a DataCite relatedItem.
 *
 * Organised into accordion sections; Identifier → optional DOI lookup → Titles
 * → Creators → Contributors → Publication details → Numbering.
 */
export function RelatedItemForm({
    initialValue,
    resourceTypes,
    relationTypes,
    contributorTypes,
    onSubmit,
    onCancel,
    submitting = false,
    enableLookup = true,
}: RelatedItemFormProps) {
    const form = useForm<RelatedItemInput>({
        resolver: zodResolver(relatedItemSchema) as Resolver<RelatedItemInput>,
        defaultValues: { ...emptyDefaults(), ...initialValue } as RelatedItemInput,
    });

    const titles = useFieldArray({ control: form.control, name: 'titles' });
    const creators = useFieldArray({ control: form.control, name: 'creators' });
    const contributors = useFieldArray({ control: form.control, name: 'contributors' });

    const lookup = useCitationLookup();

    // Apply lookup result to form when it arrives.
    useEffect(() => {
        if (!lookup.result || lookup.result.source === 'not_found') return;
        const r = lookup.result;

        if (r.related_item_type) form.setValue('related_item_type', r.related_item_type);
        if (r.publication_year) form.setValue('publication_year', r.publication_year);
        if (r.publisher) form.setValue('publisher', r.publisher);
        if (r.volume) form.setValue('volume', r.volume);
        if (r.issue) form.setValue('issue', r.issue);
        if (r.first_page) form.setValue('first_page', r.first_page);
        if (r.last_page) form.setValue('last_page', r.last_page);
        if (r.title) {
            form.setValue('titles', [
                { title: r.title, title_type: 'MainTitle', position: 0 },
                ...(r.subtitle
                    ? [{ title: r.subtitle, title_type: 'Subtitle' as const, position: 1 }]
                    : []),
            ]);
        }
        if (r.creators && r.creators.length > 0) {
            form.setValue(
                'creators',
                r.creators.map((c, idx) => ({
                    name: c.name,
                    name_type: (c.name_type === 'Organizational'
                        ? 'Organizational'
                        : 'Personal') as 'Personal' | 'Organizational',
                    given_name: c.given_name ?? null,
                    family_name: c.family_name ?? null,
                    name_identifier: c.name_identifier ?? null,
                    name_identifier_scheme: c.name_identifier_scheme ?? null,
                    position: idx,
                    affiliations: [],
                })),
            );
        }
    }, [lookup.result, form]);

    const handleLookup = () => {
        const doi = form.getValues('identifier');
        if (doi) {
            form.setValue('identifier_type', 'DOI');
            lookup.lookup(doi);
        }
    };

    const handleSubmit = form.handleSubmit(async (values) => {
        await onSubmit(values);
    });

    return (
        <Form {...form}>
            <form onSubmit={handleSubmit} className="space-y-4">
                {/* Required */}
                <div className="grid gap-4 sm:grid-cols-2">
                    <FormField
                        control={form.control}
                        name="related_item_type"
                        render={({ field }) => (
                            <FormItem>
                                <FormLabel>Type *</FormLabel>
                                <Select
                                    value={field.value || ''}
                                    onValueChange={(v) => field.onChange(v)}
                                >
                                    <FormControl>
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select type" />
                                        </SelectTrigger>
                                    </FormControl>
                                    <SelectContent>
                                        {resourceTypes.map((opt) => (
                                            <SelectItem key={opt.value} value={opt.value}>
                                                {opt.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <FormMessage />
                            </FormItem>
                        )}
                    />

                    <FormField
                        control={form.control}
                        name="relation_type_id"
                        render={({ field }) => (
                            <FormItem>
                                <FormLabel>Relation type *</FormLabel>
                                <Select
                                    value={field.value ? String(field.value) : ''}
                                    onValueChange={(v) => field.onChange(Number(v))}
                                >
                                    <FormControl>
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select relation" />
                                        </SelectTrigger>
                                    </FormControl>
                                    <SelectContent>
                                        {relationTypes.map((opt) => (
                                            <SelectItem key={opt.id} value={String(opt.id)}>
                                                {opt.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <FormMessage />
                            </FormItem>
                        )}
                    />
                </div>

                <Accordion
                    type="multiple"
                    defaultValue={['identifier', 'titles']}
                    className="w-full"
                >
                    {/* Identifier + Lookup */}
                    <AccordionItem value="identifier">
                        <AccordionTrigger>Identifier (optional)</AccordionTrigger>
                        <AccordionContent>
                            <div className="grid gap-3 sm:grid-cols-[1fr_160px_auto]">
                                <FormField
                                    control={form.control}
                                    name="identifier"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel>Identifier</FormLabel>
                                            <FormControl>
                                                <Input
                                                    {...field}
                                                    value={field.value ?? ''}
                                                    placeholder="e.g. 10.1234/abcd"
                                                />
                                            </FormControl>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                                <FormField
                                    control={form.control}
                                    name="identifier_type"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel>Type</FormLabel>
                                            <FormControl>
                                                <Input
                                                    {...field}
                                                    value={field.value ?? ''}
                                                    placeholder="DOI"
                                                />
                                            </FormControl>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                                {enableLookup ? (
                                    <div className="flex items-end">
                                        <LoadingButton
                                            type="button"
                                            variant="outline"
                                            onClick={handleLookup}
                                            loading={lookup.isLoading}
                                            aria-label="Look up DOI metadata"
                                        >
                                            <Search className="mr-1 h-4 w-4" />
                                            Lookup
                                        </LoadingButton>
                                    </div>
                                ) : null}
                            </div>
                            {lookup.error ? (
                                <p className="mt-2 text-sm text-destructive">{lookup.error}</p>
                            ) : null}
                            {lookup.result?.source === 'not_found' ? (
                                <p className="mt-2 text-sm text-muted-foreground">
                                    No metadata found for this identifier.
                                </p>
                            ) : null}
                            {lookup.result &&
                            lookup.result.source !== 'not_found' ? (
                                <p className="mt-2 text-sm text-muted-foreground">
                                    Metadata autofilled from {lookup.result.source}.
                                </p>
                            ) : null}
                        </AccordionContent>
                    </AccordionItem>

                    {/* Titles */}
                    <AccordionItem value="titles">
                        <AccordionTrigger>Titles *</AccordionTrigger>
                        <AccordionContent>
                            <div className="space-y-3">
                                {titles.fields.map((fieldItem, idx) => (
                                    <div
                                        key={fieldItem.id}
                                        className="grid gap-2 sm:grid-cols-[1fr_180px_auto]"
                                    >
                                        <FormField
                                            control={form.control}
                                            name={`titles.${idx}.title`}
                                            render={({ field }) => (
                                                <FormItem>
                                                    <FormControl>
                                                        <Input {...field} placeholder="Title" />
                                                    </FormControl>
                                                    <FormMessage />
                                                </FormItem>
                                            )}
                                        />
                                        <FormField
                                            control={form.control}
                                            name={`titles.${idx}.title_type`}
                                            render={({ field }) => (
                                                <FormItem>
                                                    <Select
                                                        value={field.value}
                                                        onValueChange={field.onChange}
                                                    >
                                                        <FormControl>
                                                            <SelectTrigger>
                                                                <SelectValue />
                                                            </SelectTrigger>
                                                        </FormControl>
                                                        <SelectContent>
                                                            <SelectItem value="MainTitle">
                                                                MainTitle
                                                            </SelectItem>
                                                            <SelectItem value="Subtitle">
                                                                Subtitle
                                                            </SelectItem>
                                                            <SelectItem value="TranslatedTitle">
                                                                TranslatedTitle
                                                            </SelectItem>
                                                            <SelectItem value="AlternativeTitle">
                                                                AlternativeTitle
                                                            </SelectItem>
                                                        </SelectContent>
                                                    </Select>
                                                    <FormMessage />
                                                </FormItem>
                                            )}
                                        />
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            onClick={() => titles.remove(idx)}
                                            aria-label={`Remove title ${idx + 1}`}
                                            disabled={titles.fields.length <= 1}
                                        >
                                            <Trash2 className="h-4 w-4" />
                                        </Button>
                                    </div>
                                ))}
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        titles.append({
                                            title: '',
                                            title_type: 'Subtitle',
                                            position: titles.fields.length,
                                        })
                                    }
                                >
                                    <Plus className="mr-1 h-4 w-4" /> Add title
                                </Button>
                                {form.formState.errors.titles?.root?.message ? (
                                    <p className="text-sm text-destructive">
                                        {form.formState.errors.titles.root.message}
                                    </p>
                                ) : null}
                            </div>
                        </AccordionContent>
                    </AccordionItem>

                    {/* Creators */}
                    <AccordionItem value="creators">
                        <AccordionTrigger>
                            Creators ({creators.fields.length})
                        </AccordionTrigger>
                        <AccordionContent>
                            <div className="space-y-3">
                                {creators.fields.map((fieldItem, idx) => (
                                    <div
                                        key={fieldItem.id}
                                        className="grid gap-2 sm:grid-cols-[1fr_160px_auto]"
                                    >
                                        <FormField
                                            control={form.control}
                                            name={`creators.${idx}.name`}
                                            render={({ field }) => (
                                                <FormItem>
                                                    <FormControl>
                                                        <Input
                                                            {...field}
                                                            placeholder="Family, Given"
                                                        />
                                                    </FormControl>
                                                    <FormMessage />
                                                </FormItem>
                                            )}
                                        />
                                        <FormField
                                            control={form.control}
                                            name={`creators.${idx}.name_type`}
                                            render={({ field }) => (
                                                <FormItem>
                                                    <Select
                                                        value={field.value}
                                                        onValueChange={field.onChange}
                                                    >
                                                        <FormControl>
                                                            <SelectTrigger>
                                                                <SelectValue />
                                                            </SelectTrigger>
                                                        </FormControl>
                                                        <SelectContent>
                                                            <SelectItem value="Personal">
                                                                Personal
                                                            </SelectItem>
                                                            <SelectItem value="Organizational">
                                                                Organizational
                                                            </SelectItem>
                                                        </SelectContent>
                                                    </Select>
                                                    <FormMessage />
                                                </FormItem>
                                            )}
                                        />
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            onClick={() => creators.remove(idx)}
                                            aria-label={`Remove creator ${idx + 1}`}
                                        >
                                            <Trash2 className="h-4 w-4" />
                                        </Button>
                                    </div>
                                ))}
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        creators.append({
                                            name: '',
                                            name_type: 'Personal',
                                            given_name: null,
                                            family_name: null,
                                            name_identifier: null,
                                            name_identifier_scheme: null,
                                            position: creators.fields.length,
                                            affiliations: [],
                                        })
                                    }
                                >
                                    <Plus className="mr-1 h-4 w-4" /> Add creator
                                </Button>
                            </div>
                        </AccordionContent>
                    </AccordionItem>

                    {/* Contributors */}
                    <AccordionItem value="contributors">
                        <AccordionTrigger>
                            Contributors ({contributors.fields.length})
                        </AccordionTrigger>
                        <AccordionContent>
                            <div className="space-y-3">
                                {contributors.fields.map((fieldItem, idx) => (
                                    <div
                                        key={fieldItem.id}
                                        className="grid gap-2 sm:grid-cols-[1fr_160px_160px_auto]"
                                    >
                                        <FormField
                                            control={form.control}
                                            name={`contributors.${idx}.name`}
                                            render={({ field }) => (
                                                <FormItem>
                                                    <FormControl>
                                                        <Input {...field} placeholder="Name" />
                                                    </FormControl>
                                                    <FormMessage />
                                                </FormItem>
                                            )}
                                        />
                                        <FormField
                                            control={form.control}
                                            name={`contributors.${idx}.name_type`}
                                            render={({ field }) => (
                                                <FormItem>
                                                    <Select
                                                        value={field.value}
                                                        onValueChange={field.onChange}
                                                    >
                                                        <FormControl>
                                                            <SelectTrigger>
                                                                <SelectValue />
                                                            </SelectTrigger>
                                                        </FormControl>
                                                        <SelectContent>
                                                            <SelectItem value="Personal">
                                                                Personal
                                                            </SelectItem>
                                                            <SelectItem value="Organizational">
                                                                Organizational
                                                            </SelectItem>
                                                        </SelectContent>
                                                    </Select>
                                                </FormItem>
                                            )}
                                        />
                                        <FormField
                                            control={form.control}
                                            name={`contributors.${idx}.contributor_type`}
                                            render={({ field }) => (
                                                <FormItem>
                                                    <Select
                                                        value={field.value || ''}
                                                        onValueChange={field.onChange}
                                                    >
                                                        <FormControl>
                                                            <SelectTrigger>
                                                                <SelectValue placeholder="Role" />
                                                            </SelectTrigger>
                                                        </FormControl>
                                                        <SelectContent>
                                                            {contributorTypes.map((opt) => (
                                                                <SelectItem
                                                                    key={opt.value}
                                                                    value={opt.value}
                                                                >
                                                                    {opt.label}
                                                                </SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                    <FormMessage />
                                                </FormItem>
                                            )}
                                        />
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            onClick={() => contributors.remove(idx)}
                                            aria-label={`Remove contributor ${idx + 1}`}
                                        >
                                            <Trash2 className="h-4 w-4" />
                                        </Button>
                                    </div>
                                ))}
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        contributors.append({
                                            name: '',
                                            name_type: 'Personal',
                                            given_name: null,
                                            family_name: null,
                                            name_identifier: null,
                                            name_identifier_scheme: null,
                                            contributor_type: '',
                                            position: contributors.fields.length,
                                            affiliations: [],
                                        })
                                    }
                                >
                                    <Plus className="mr-1 h-4 w-4" /> Add contributor
                                </Button>
                            </div>
                        </AccordionContent>
                    </AccordionItem>

                    {/* Publication details */}
                    <AccordionItem value="publication">
                        <AccordionTrigger>Publication details</AccordionTrigger>
                        <AccordionContent>
                            <div className="grid gap-3 sm:grid-cols-3">
                                <FormField
                                    control={form.control}
                                    name="publication_year"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel>Year</FormLabel>
                                            <FormControl>
                                                <Input
                                                    type="number"
                                                    value={field.value ?? ''}
                                                    onChange={(e) =>
                                                        field.onChange(
                                                            e.target.value === ''
                                                                ? null
                                                                : Number(e.target.value),
                                                        )
                                                    }
                                                />
                                            </FormControl>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                                <FormField
                                    control={form.control}
                                    name="publisher"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel>Publisher</FormLabel>
                                            <FormControl>
                                                <Input {...field} value={field.value ?? ''} />
                                            </FormControl>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                                <FormField
                                    control={form.control}
                                    name="edition"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel>Edition</FormLabel>
                                            <FormControl>
                                                <Input {...field} value={field.value ?? ''} />
                                            </FormControl>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                            </div>
                        </AccordionContent>
                    </AccordionItem>

                    {/* Numbering */}
                    <AccordionItem value="numbering">
                        <AccordionTrigger>Numbering</AccordionTrigger>
                        <AccordionContent>
                            <div className="grid gap-3 sm:grid-cols-3">
                                <FormField
                                    control={form.control}
                                    name="volume"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel>Volume</FormLabel>
                                            <FormControl>
                                                <Input {...field} value={field.value ?? ''} />
                                            </FormControl>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                                <FormField
                                    control={form.control}
                                    name="issue"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel>Issue</FormLabel>
                                            <FormControl>
                                                <Input {...field} value={field.value ?? ''} />
                                            </FormControl>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                                <FormField
                                    control={form.control}
                                    name="number"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel>Number</FormLabel>
                                            <FormControl>
                                                <Input {...field} value={field.value ?? ''} />
                                            </FormControl>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                                <FormField
                                    control={form.control}
                                    name="number_type"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel>Number type</FormLabel>
                                            <Select
                                                value={field.value ?? ''}
                                                onValueChange={(v) =>
                                                    field.onChange(v === '' ? null : v)
                                                }
                                            >
                                                <FormControl>
                                                    <SelectTrigger>
                                                        <SelectValue placeholder="—" />
                                                    </SelectTrigger>
                                                </FormControl>
                                                <SelectContent>
                                                    <SelectItem value="Article">Article</SelectItem>
                                                    <SelectItem value="Chapter">Chapter</SelectItem>
                                                    <SelectItem value="Report">Report</SelectItem>
                                                    <SelectItem value="Other">Other</SelectItem>
                                                </SelectContent>
                                            </Select>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                                <FormField
                                    control={form.control}
                                    name="first_page"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel>First page</FormLabel>
                                            <FormControl>
                                                <Input {...field} value={field.value ?? ''} />
                                            </FormControl>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                                <FormField
                                    control={form.control}
                                    name="last_page"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel>Last page</FormLabel>
                                            <FormControl>
                                                <Input {...field} value={field.value ?? ''} />
                                            </FormControl>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                            </div>
                        </AccordionContent>
                    </AccordionItem>
                </Accordion>

                <div className="flex justify-end gap-2 pt-4">
                    {onCancel ? (
                        <Button type="button" variant="outline" onClick={onCancel}>
                            Cancel
                        </Button>
                    ) : null}
                    <LoadingButton type="submit" loading={submitting}>
                        Save
                    </LoadingButton>
                </div>
            </form>
        </Form>
    );
}
