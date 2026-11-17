import { Check, ChevronsUpDown, Plus, Trash2 } from 'lucide-react';
import { type Dispatch, type SetStateAction, useEffect, useMemo, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import {
    contentMetadataErrors,
    type FormatEntry,
    normalizedSizeUnit,
    REMOVED_METADATA_MESSAGE,
    SIZE_UNITS,
    type SizeEntry,
} from '@/lib/content-metadata';
import { EMPTY_FORMAT_USAGE, type FormatUsage, loadMimeTypes, mimeTypeLabel, type MimeTypeOption, mimeTypeOptions } from '@/lib/mime-types';

function MimeTypeSelect({
    row,
    index,
    options,
    onChange,
    error,
}: {
    row: FormatEntry;
    index: number;
    options: MimeTypeOption[];
    onChange: (value: string) => void;
    error?: string;
}) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [limit, setLimit] = useState(60);
    const matching = useMemo(
        () => options.filter((option) => `${option.label} ${option.value}`.toLowerCase().includes(query.trim().toLowerCase())),
        [options, query],
    );
    const id = `format-${row.clientId}`;

    return (
        <div className="min-w-0 flex-1 space-y-2">
            <Label htmlFor={id}>Format {index + 1}</Label>
            <Popover open={open} onOpenChange={setOpen}>
                <PopoverTrigger asChild>
                    <Button
                        type="button"
                        variant="outline"
                        role="combobox"
                        id={id}
                        data-testid={`format-select-${index}`}
                        aria-expanded={open}
                        aria-invalid={Boolean(error) || row.removedOnServer}
                        aria-describedby={row.removedOnServer ? `${id}-conflict` : error ? `${id}-error` : undefined}
                        className="w-full justify-between font-normal"
                        title={row.value || undefined}
                    >
                        <span className="truncate">{row.value ? mimeTypeLabel(row.value) : 'Select format'}</span>
                        <ChevronsUpDown className="ml-2 size-4 shrink-0" />
                    </Button>
                </PopoverTrigger>
                <PopoverContent className="w-[min(32rem,90vw)] p-0" align="start">
                    <Command shouldFilter={false}>
                        <CommandInput
                            placeholder="Search formats or MIME types..."
                            value={query}
                            onValueChange={(value) => {
                                setQuery(value);
                                setLimit(60);
                            }}
                        />
                        <CommandList>
                            <CommandEmpty>{options.length === 0 ? 'Loading MIME types...' : 'No matching format.'}</CommandEmpty>
                            <CommandGroup heading="Most used in ERNIE first">
                                {matching.slice(0, limit).map((option) => (
                                    <CommandItem
                                        key={option.value}
                                        value={option.value}
                                        aria-label={`${option.label} (${option.value})`}
                                        className="items-start"
                                        onSelect={() => {
                                            onChange(option.value);
                                            setOpen(false);
                                        }}
                                    >
                                        <Check
                                            className={`mt-0.5 mr-2 size-4 shrink-0 ${row.value === option.value ? 'opacity-100' : 'opacity-0'}`}
                                        />
                                        <div className="min-w-0">
                                            <span className="block font-medium break-words">{option.label}</span>
                                            <span className="block text-xs break-all text-muted-foreground">{option.value}</span>
                                        </div>
                                    </CommandItem>
                                ))}
                                {matching.length > limit && (
                                    <CommandItem value="__more__" onSelect={() => setLimit((value) => value + 60)}>
                                        Show more MIME types ({matching.length - limit} remaining)
                                    </CommandItem>
                                )}
                            </CommandGroup>
                        </CommandList>
                    </Command>
                </PopoverContent>
            </Popover>
            {row.value && options.length > 0 && !options.some((option) => option.value === row.value) && (
                <p className="text-sm text-muted-foreground">Existing format; select an IANA MIME type to replace it.</p>
            )}
            {error && !row.removedOnServer && (
                <p id={`${id}-error`} role="alert" className="text-sm text-destructive">
                    {error}
                </p>
            )}
        </div>
    );
}

function RemovedEntryNotice({
    id,
    kind,
    canKeep,
    onKeep,
    onDiscard,
    help,
}: {
    id: string;
    kind: 'format' | 'size';
    canKeep: boolean;
    onKeep?: () => void;
    onDiscard: () => void;
    help?: string;
}) {
    return (
        <div id={id} role="alert" className="space-y-2 rounded-md border border-destructive p-3 text-sm">
            <p>{REMOVED_METADATA_MESSAGE}</p>
            {help && <p className="text-muted-foreground">{help}</p>}
            <div className="flex flex-wrap gap-2">
                {onKeep && (
                    <Button type="button" variant="outline" disabled={!canKeep} onClick={onKeep}>
                        Keep as new {kind}
                    </Button>
                )}
                <Button type="button" variant="outline" onClick={onDiscard}>
                    Discard local {kind} change
                </Button>
            </div>
        </div>
    );
}

export function FormatSizeField({
    formats,
    sizes,
    onFormatsChange,
    onSizesChange,
    errors = {},
    physicalObject = false,
    formatUsage = EMPTY_FORMAT_USAGE,
}: {
    formats: FormatEntry[];
    sizes: SizeEntry[];
    onFormatsChange: Dispatch<SetStateAction<FormatEntry[]>>;
    onSizesChange: Dispatch<SetStateAction<SizeEntry[]>>;
    errors?: Record<string, string>;
    physicalObject?: boolean;
    formatUsage?: FormatUsage;
}) {
    const [types, setTypes] = useState<string[]>([]);
    const [loadError, setLoadError] = useState(false);
    const [attempt, setAttempt] = useState(0);
    const options = useMemo(() => mimeTypeOptions(types, formatUsage), [types, formatUsage]);
    useEffect(() => {
        let active = true;
        setLoadError(false);
        void loadMimeTypes()
            .then((values) => {
                if (active) setTypes(values);
            })
            .catch(() => {
                if (active) setLoadError(true);
            });
        return () => {
            active = false;
        };
    }, [attempt]);

    return (
        <div className="space-y-6" data-testid="format-size-fields">
            <p className="text-sm text-muted-foreground">
                Describe the formats and sizes of this resource. Formats and sizes are independent metadata entries.
            </p>
            {loadError && (
                <div role="alert" className="text-sm text-destructive">
                    MIME types could not be loaded.{' '}
                    <Button type="button" variant="link" onClick={() => setAttempt((value) => value + 1)}>
                        Retry MIME types
                    </Button>
                </div>
            )}
            <div className="space-y-3">
                {formats.map((row, index) => (
                    <div key={row.clientId} className="space-y-2" data-testid={`format-row-${index}`}>
                        <div className="flex items-start gap-2">
                            <MimeTypeSelect
                                row={row}
                                index={index}
                                options={options}
                                error={errors[`formats.${index}.value`] ?? errors[`formats.${index}.id`]}
                                onChange={(value) =>
                                    onFormatsChange((current) =>
                                        current.map((entry) => (entry.clientId === row.clientId ? { ...entry, value, importKey: undefined } : entry)),
                                    )
                                }
                            />
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="mt-6"
                                aria-label={`Remove format ${index + 1}`}
                                onClick={() => onFormatsChange((current) => current.filter((entry) => entry.clientId !== row.clientId))}
                            >
                                <Trash2 className="size-4" />
                            </Button>
                        </div>
                        {row.removedOnServer && (
                            <RemovedEntryNotice
                                id={`format-${row.clientId}-conflict`}
                                kind="format"
                                canKeep={types.includes(row.value.trim().toLowerCase())}
                                help="Select a registered MIME type to keep this change as a new entry."
                                onKeep={() =>
                                    onFormatsChange((current) =>
                                        current.map((entry) =>
                                            entry.clientId === row.clientId
                                                ? {
                                                      ...entry,
                                                      id: undefined,
                                                      importKey: undefined,
                                                      removedOnServer: undefined,
                                                      value: entry.value.trim().toLowerCase(),
                                                  }
                                                : entry,
                                        ),
                                    )
                                }
                                onDiscard={() => onFormatsChange((current) => current.filter((entry) => entry.clientId !== row.clientId))}
                            />
                        )}
                    </div>
                ))}
                <Button
                    type="button"
                    variant="outline"
                    disabled={formats.some((row) => row.id === undefined && row.value.trim() === '')}
                    onClick={() => {
                        const clientId = crypto.randomUUID();
                        onFormatsChange((current) => [...current, { clientId, value: '' }]);
                    }}
                >
                    <Plus className="mr-2 size-4" />
                    Add format
                </Button>
            </div>
            <div className="space-y-3">
                {sizes.map((row, index) => {
                    const readOnly = row.editable === false || physicalObject;
                    const canRestore = !readOnly && row.type === null;
                    const id = `size-${row.clientId}`;
                    const error =
                        errors[`sizes.${index}.numeric_value`] ??
                        errors[`sizes.${index}.unit`] ??
                        errors[`sizes.${index}.type`] ??
                        errors[`sizes.${index}.id`];
                    return (
                        <div key={row.clientId} className="space-y-2 rounded-md border p-3" data-testid={`size-row-${index}`}>
                            {row.type && <p className="text-sm font-medium">{row.type}</p>}
                            {row.removedOnServer && (
                                <RemovedEntryNotice
                                    id={`${id}-conflict`}
                                    kind="size"
                                    canKeep={Object.keys(contentMetadataErrors([{ ...row, removedOnServer: undefined }])).length === 0}
                                    help={
                                        canRestore
                                            ? undefined
                                            : 'A removed size with a preserved meaning or non-digital value cannot be recreated here. You can copy its number and unit into a new digital size.'
                                    }
                                    onKeep={
                                        canRestore
                                            ? () =>
                                                  onSizesChange((current) =>
                                                      current.map((entry) =>
                                                          entry.clientId === row.clientId
                                                              ? { ...entry, id: undefined, importKey: undefined, removedOnServer: undefined }
                                                              : entry,
                                                      ),
                                                  )
                                            : undefined
                                    }
                                    onDiscard={() => onSizesChange((current) => current.filter((entry) => entry.clientId !== row.clientId))}
                                />
                            )}
                            {readOnly ? (
                                <p className="text-sm">
                                    {row.label || [row.numeric_value, row.unit].filter(Boolean).join(' ')}{' '}
                                    <span className="text-muted-foreground">(preserved metadata)</span>
                                </p>
                            ) : (
                                <div className="flex items-end gap-2">
                                    <div className="flex min-w-0 flex-1 flex-col gap-2">
                                        <Label htmlFor={id}>Size {index + 1}</Label>
                                        <Input
                                            id={id}
                                            data-testid={`size-value-${index}`}
                                            type="text"
                                            inputMode="decimal"
                                            value={row.numeric_value ?? ''}
                                            aria-invalid={Boolean(error) || row.removedOnServer}
                                            aria-describedby={row.removedOnServer ? `${id}-conflict` : error ? `${id}-error` : undefined}
                                            onChange={(event) => {
                                                const numericValue = event.currentTarget.value;
                                                onSizesChange((current) =>
                                                    current.map((entry) =>
                                                        entry.clientId === row.clientId ? { ...entry, numeric_value: numericValue } : entry,
                                                    ),
                                                );
                                            }}
                                        />
                                    </div>
                                    <div className="flex w-28 shrink-0 flex-col gap-2">
                                        <Label htmlFor={`${id}-unit`}>Unit {index + 1}</Label>
                                        <Select
                                            value={normalizedSizeUnit(row.unit)}
                                            onValueChange={(unit) =>
                                                onSizesChange((current) =>
                                                    current.map((entry) => (entry.clientId === row.clientId ? { ...entry, unit } : entry)),
                                                )
                                            }
                                        >
                                            <SelectTrigger
                                                id={`${id}-unit`}
                                                data-testid={`size-unit-${index}`}
                                                aria-invalid={Boolean(error) || row.removedOnServer}
                                            >
                                                <SelectValue placeholder="Unit" />
                                            </SelectTrigger>
                                            <SelectContent position="popper" side="top" align="start">
                                                {SIZE_UNITS.map((unit) => (
                                                    <SelectItem key={unit} value={unit}>
                                                        {unit}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        aria-label={`Remove size ${index + 1}`}
                                        onClick={() => onSizesChange((current) => current.filter((entry) => entry.clientId !== row.clientId))}
                                    >
                                        <Trash2 className="size-4" />
                                    </Button>
                                </div>
                            )}
                            {error && !row.removedOnServer && (
                                <p id={`${id}-error`} role="alert" className="text-sm text-destructive">
                                    {error}
                                </p>
                            )}
                        </div>
                    );
                })}
                {!physicalObject && (
                    <Button
                        type="button"
                        variant="outline"
                        disabled={sizes.some((row) => row.editable !== false && !(row.numeric_value ?? '').trim())}
                        onClick={() => {
                            const clientId = crypto.randomUUID();
                            onSizesChange((current) => [...current, { clientId, numeric_value: '', unit: '', type: null }]);
                        }}
                    >
                        <Plus className="mr-2 size-4" />
                        Add size
                    </Button>
                )}
            </div>
            {(errors.formats || errors.sizes) && (
                <p role="alert" className="text-sm text-destructive">
                    {errors.formats || errors.sizes}
                </p>
            )}
            {errors.contentMetadataFingerprint && (
                <p role="alert" className="text-sm text-destructive">
                    {errors.contentMetadataFingerprint}
                </p>
            )}
        </div>
    );
}
