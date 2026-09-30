import type { TagData, TagifySettings } from '@yaireo/tagify';
import Tagify from '@yaireo/tagify';
import type { HTMLAttributes, InputHTMLAttributes } from 'react';
import { useContext, useEffect, useMemo, useRef, useState } from 'react';

import { Label } from '@/components/ui/label';
import { type RorInputDraft, useRorInputDraft } from '@/hooks/use-ror-input-drafts';
import { indexRorSuggestions, parseRorInput, resolveRorInput } from '@/lib/ror-input';
import { cn } from '@/lib/utils';
import type { AffiliationSuggestion, AffiliationTag } from '@/types/affiliations';

import { RorCatalogContext, RorInputFeedback } from './ror-input-feedback';

export interface TagInputItem {
    value: string;
    [key: string]: unknown;
}

export interface TagInputChangeDetail<T extends TagInputItem = TagInputItem> {
    raw: string;
    tags: T[];
}

interface TagInputFieldProps<T extends TagInputItem = TagInputItem> extends Omit<InputHTMLAttributes<HTMLInputElement>, 'value' | 'onChange'> {
    id: string;
    label: string;
    value: T[];
    onChange: (detail: TagInputChangeDetail<T>) => void;
    hideLabel?: boolean;
    className?: string;
    containerProps?: HTMLAttributes<HTMLDivElement> & { 'data-testid'?: string };
    tagifySettings?: Partial<TagifySettings<TagData>>;
    ror?: { suggestions: AffiliationSuggestion[]; section: RorInputDraft['section'] };
    'data-testid'?: string;
}

export function TagInputField<T extends TagInputItem = TagInputItem>({
    id,
    label,
    value,
    onChange,
    hideLabel = false,
    className,
    containerProps,
    tagifySettings,
    ror,
    required,
    disabled,
    placeholder,
    'data-testid': dataTestId,
    ...inputProps
}: TagInputFieldProps<T>) {
    const inputRef = useRef<HTMLInputElement | null>(null);
    const tagifyRef = useRef<Tagify<TagData> | null>(null);
    const changeHandlerRef = useRef(onChange);
    const tagifyChangeHandlerRef = useRef<((event: CustomEvent) => void) | null>(null);
    const editingRorIdRef = useRef<string | null>(null);
    const originalDelimitersRef = useRef<string | RegExp>(',');
    const delimitersWereSuspendedRef = useRef(false);
    const originalDropdownEnabledRef = useRef<number | false>(0);
    const dropdownWasSuspendedRef = useRef(false);
    const originalAutoCompleteEnabledRef = useRef(true);
    const autoCompleteWasSuspendedRef = useRef(false);
    const rorDraft = useRorInputDraft(id);
    const catalog = useContext(RorCatalogContext);
    const rorIndex = useMemo(() => catalog?.index ?? indexRorSuggestions(ror?.suggestions ?? []), [catalog?.index, ror?.suggestions]);
    const [rorOpen, setRorOpen] = useState(true);
    const rorActions = useRef({
        input: (_text: string, _editingIndex?: number) => {},
        key: (_event: KeyboardEvent) => {},
        clear: () => {},
        names: () => {},
    });

    const clearRorInput = () => {
        const tagify = tagifyRef.current;
        rorDraft.set(null);
        const editable = tagify?.DOM.scope.querySelector<HTMLElement>('.tagify__tag [contenteditable="true"]');
        editable?.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        if (tagify) {
            tagify.DOM.input.textContent = '';
            tagify.DOM.input.dispatchEvent(new Event('input', { bubbles: true }));
            tagify.dropdown.hide(true);
        }
    };
    const selectRor = (selected: AffiliationTag) => {
        const editingIndex = rorDraft.get()?.editingIndex;
        const affiliation = { value: selected.value, rorId: selected.rorId };
        const next = value.map((tag, index) => (index === editingIndex ? affiliation : tag));
        if (editingIndex === undefined && !next.some((tag) => tag.value === selected.value && tag.rorId === selected.rorId)) next.push(affiliation);
        clearRorInput();
        changeHandlerRef.current({ raw: next.map((tag) => tag.value).join(', '), tags: next as T[] });
    };
    const commitPlainNames = () => {
        const draft = rorDraft.get();
        if (!draft || draft.editingIndex !== undefined || parseRorInput(draft.text).kind !== 'name') return;
        const next = [...value];
        for (const name of draft.text
            .split(',')
            .map((part) => part.trim())
            .filter(Boolean)) {
            if (!next.some((tag) => tag.value === name && !tag.rorId)) next.push({ value: name, rorId: null } as unknown as T);
        }
        clearRorInput();
        changeHandlerRef.current({ raw: next.map((tag) => tag.value).join(', '), tags: next });
    };
    rorActions.current = {
        input: (text, editingIndex) => {
            if (!ror) return;
            editingIndex ??= rorDraft.get()?.editingIndex;
            const parsed = parseRorInput(text);
            // Inline edits of an existing label use Tagify's established edit path.
            rorDraft.set(text.trim() && (editingIndex === undefined || parsed.kind !== 'name') ? { text, editingIndex, section: ror.section } : null);
            setRorOpen(true);
            const tagify = tagifyRef.current;
            if (tagify) {
                tagify.settings.addTagOnBlur = parsed.kind === 'name';
                if (parsed.kind !== 'name') tagify.dropdown.hide(true);
            }
        },
        key: (event) => {
            const draft = rorDraft.get();
            if (!ror || !draft || event.isComposing) return;
            if (parseRorInput(draft.text).kind === 'name') {
                if (event.key === 'Enter' && draft.text.includes(',') && draft.editingIndex === undefined) {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                    commitPlainNames();
                }
                return;
            }
            if (['Enter', 'ArrowDown', 'ArrowUp', 'Escape'].includes(event.key)) {
                event.preventDefault();
                event.stopImmediatePropagation();
                if (event.key === 'Escape') {
                    setRorOpen(false);
                    return;
                }
                if (event.key !== 'Enter') {
                    setRorOpen(true);
                    return;
                }
                const match = resolveRorInput(parseRorInput(draft.text), rorIndex);
                if (rorOpen && match && !catalog?.isLoading && !catalog?.error) selectRor(match);
            }
        },
        clear: () => rorDraft.set(null),
        names: commitPlainNames,
    };

    useEffect(() => {
        changeHandlerRef.current = onChange;
    }, [onChange]);

    const mergedClassName = useMemo(() => cn('flex flex-col gap-2', containerProps?.className, className), [className, containerProps?.className]);

    useEffect(() => {
        const inputElement = inputRef.current;
        if (!inputElement) {
            return;
        }

        inputElement.value = value.map((item) => item.value).join(', ');

        const settings: TagifySettings<TagData> = {
            delimiters: ',',
            editTags: 1,
            duplicates: false,
            placeholder,
            dropdown: { enabled: 0 },
            maxTags: Infinity,
            originalInputValueFormat: (values) =>
                values
                    .map((item) => item.value?.trim())
                    .filter((tag): tag is string => Boolean(tag && tag.length > 0))
                    .join(', '),
            a11y: {
                focusableTags: true,
            },
            transformTag: (tagData) => {
                // Preserve rorId if it exists in the tag data
                if ('rorId' in tagData && typeof tagData.rorId === 'string') {
                    // Keep rorId at the top level for easy access
                    return tagData;
                }
                return tagData;
            },
            ...tagifySettings,
            ...(ror
                ? {
                      delimiters: /(?!)/,
                      duplicates: true,
                      pasteAsTags: false,
                      autoComplete: { enabled: false },
                      editTags: { clicks: 1 as const, keepInvalid: true },
                      createInvalidTags: false,
                      validate: (tag: TagData) =>
                          typeof tag.rorId === 'string' ||
                          parseRorInput(tag.value).kind === 'name' ||
                          'Select the organization to confirm its ROR ID.',
                  }
                : {}),
        };

        const tagify = new Tagify(inputElement, settings);
        tagifyRef.current = tagify;

        // Set test ID for Tagify scope (for Playwright)
        tagify.DOM.scope.dataset.testid = `${id}-tagify`;
        // Note: data-testid for the input element is set on the original <input> element below
        (inputElement as HTMLInputElement & { tagify?: Tagify<TagData> }).tagify = tagify;

        if (value.length > 0) {
            tagify.removeAllTags();
            // Pass tags directly - Tagify should preserve all properties
            tagify.addTags(value);
        }

        const handleChange = (event: CustomEvent) => {
            const detail = event.detail as { value?: string; tagify: Tagify<TagData> };
            const rawValue = detail.value ?? '';

            const tags = detail.tagify.value
                .map((item) => {
                    const trimmedValue = typeof item.value === 'string' ? item.value.trim() : '';

                    if (!trimmedValue) {
                        return null;
                    }

                    const rawItem = item as Record<string, unknown>;
                    const data = item.data as Record<string, unknown> | undefined;
                    const directRorId = typeof rawItem.rorId === 'string' ? rawItem.rorId : null;
                    const rorId = directRorId ?? (data && typeof data.rorId === 'string' ? data.rorId : null);

                    return {
                        value: trimmedValue,
                        rorId,
                    } as unknown as T;
                })
                .filter((item): item is T => Boolean(item));

            const uniqueTags = ror
                ? tags.filter((tag, index) => tags.findIndex((other) => other.value === tag.value && other.rorId === tag.rorId) === index)
                : tags;
            changeHandlerRef.current({ raw: rawValue, tags: uniqueTags });
            if (ror && !tagify.DOM.input.textContent?.trim() && rorDraft.get()?.editingIndex === undefined) rorActions.current.clear();
        };

        const suspendAffiliationEditFormatting = () => {
            if (!delimitersWereSuspendedRef.current) {
                originalDelimitersRef.current = tagify.settings.delimiters;
                delimitersWereSuspendedRef.current = true;
            }

            tagify.settings.delimiters = /(?!)/;

            if (tagify.settings.dropdown && !dropdownWasSuspendedRef.current) {
                originalDropdownEnabledRef.current = tagify.settings.dropdown.enabled;
                dropdownWasSuspendedRef.current = true;
            }

            if (tagify.settings.dropdown) {
                tagify.settings.dropdown.enabled = false;
                tagify.dropdown.hide(true);
            }

            if (tagify.settings.autoComplete && !autoCompleteWasSuspendedRef.current) {
                originalAutoCompleteEnabledRef.current = tagify.settings.autoComplete.enabled;
                autoCompleteWasSuspendedRef.current = true;
            }

            if (tagify.settings.autoComplete) {
                tagify.settings.autoComplete.enabled = false;
            }
        };

        const restoreAffiliationEditFormatting = () => {
            if (delimitersWereSuspendedRef.current) {
                tagify.settings.delimiters = originalDelimitersRef.current;
                delimitersWereSuspendedRef.current = false;
            }

            if (dropdownWasSuspendedRef.current && tagify.settings.dropdown) {
                tagify.settings.dropdown.enabled = originalDropdownEnabledRef.current;
                dropdownWasSuspendedRef.current = false;
            }

            if (autoCompleteWasSuspendedRef.current && tagify.settings.autoComplete) {
                tagify.settings.autoComplete.enabled = originalAutoCompleteEnabledRef.current;
                autoCompleteWasSuspendedRef.current = false;
            }

            editingRorIdRef.current = null;
        };

        const handleEditStart = (event: CustomEvent) => {
            const tagData = event.detail?.data as Record<string, unknown> | undefined;
            const rorId = typeof tagData?.rorId === 'string' ? tagData.rorId : null;
            editingRorIdRef.current = rorId;

            if (rorId) {
                suspendAffiliationEditFormatting();
            }
        };

        const handleEditUpdated = (event: CustomEvent) => {
            const tagData = event.detail?.data as Record<string, unknown> | undefined;
            if (tagData && editingRorIdRef.current) {
                tagData.rorId = editingRorIdRef.current;
            }
            restoreAffiliationEditFormatting();
        };

        const handleEditKeydown = (event: CustomEvent) => {
            const keyboardEvent = (event.detail as { event?: KeyboardEvent })?.event;
            if (keyboardEvent?.key === 'Escape') {
                restoreAffiliationEditFormatting();
                if (ror) rorActions.current.clear();
            }
        };

        tagify.on('change', handleChange);
        tagifyChangeHandlerRef.current = handleChange;
        tagify.on('edit:start', handleEditStart);
        tagify.on('edit:updated', handleEditUpdated);
        tagify.on('edit:keydown', handleEditKeydown);

        // Capture draft text synchronously, before Tagify handles blur, paste or Enter.
        const handleRorInput = (event: Event) => {
            const target = event.target as HTMLElement;
            const tag = target.closest('.tagify__tag');
            const index = tag ? Array.from(tagify.DOM.scope.querySelectorAll('.tagify__tag')).indexOf(tag) : undefined;
            rorActions.current.input(target.textContent ?? '', index);
        };
        const handleRorKey = (event: KeyboardEvent) => rorActions.current.key(event);
        const hideRorDropdown = () => {
            if (parseRorInput(tagify.DOM.input.textContent ?? '').kind !== 'name') tagify.dropdown.hide(true);
        };
        const handleRorPaste = () => rorActions.current.input(tagify.DOM.input.textContent ?? '');
        const handleRorBlur = (event: FocusEvent) => {
            if (event.target === tagify.DOM.input && tagify.DOM.input.textContent?.includes(',')) rorActions.current.names();
        };
        if (ror) {
            tagify.DOM.scope.addEventListener('input', handleRorInput, true);
            tagify.DOM.scope.addEventListener('keydown', handleRorKey, true);
            tagify.DOM.scope.addEventListener('paste', handleRorInput);
            tagify.DOM.scope.addEventListener('blur', handleRorBlur, true);
            tagify.on('input', hideRorDropdown);
            tagify.on('paste', handleRorPaste);
            const draft = rorDraft.get();
            if (draft) {
                tagify.DOM.input.textContent = draft.text;
                tagify.settings.addTagOnBlur = parseRorInput(draft.text).kind === 'name';
            }
        }

        return () => {
            tagify.off('change', handleChange);
            tagifyChangeHandlerRef.current = null;
            tagify.off('edit:start', handleEditStart);
            tagify.off('edit:updated', handleEditUpdated);
            tagify.off('edit:keydown', handleEditKeydown);
            tagify.DOM.scope.removeEventListener('input', handleRorInput, true);
            tagify.DOM.scope.removeEventListener('keydown', handleRorKey, true);
            tagify.DOM.scope.removeEventListener('paste', handleRorInput);
            tagify.DOM.scope.removeEventListener('blur', handleRorBlur, true);
            tagify.off('input', hideRorDropdown);
            tagify.off('paste', handleRorPaste);
            tagify.destroy();
            tagifyRef.current = null;
        };
        // We intentionally exclude dependencies to avoid re-initialising Tagify
        // which manages its own DOM lifecycle.
        // oxlint-disable-next-line react/exhaustive-deps
    }, []);

    useEffect(() => {
        const tagify = tagifyRef.current;
        const inputElement = inputRef.current;
        if (!tagify || !inputElement) {
            return;
        }

        if (disabled) {
            tagify.setReadonly(true);
            inputElement.disabled = true;
        } else {
            tagify.setReadonly(false);
            inputElement.disabled = false;
        }
    }, [disabled]);

    // Update whitelist when tagifySettings changes (e.g., when affiliations are loaded)
    useEffect(() => {
        const tagify = tagifyRef.current;
        if (!tagify || !tagifySettings?.whitelist) {
            return;
        }

        tagify.whitelist = tagifySettings.whitelist;

        // Update dropdown settings if provided
        // Defensive: settings can be undefined in test environments
        if (tagifySettings.dropdown && tagify.settings?.dropdown) {
            tagify.settings.dropdown = {
                ...tagify.settings.dropdown,
                ...tagifySettings.dropdown,
            };
        }

        if (editingRorIdRef.current) {
            if (tagifySettings.dropdown && 'enabled' in tagifySettings.dropdown && tagifySettings.dropdown.enabled !== undefined) {
                originalDropdownEnabledRef.current = tagifySettings.dropdown.enabled;
                dropdownWasSuspendedRef.current = true;
            }

            if (tagify.settings.dropdown) {
                tagify.settings.dropdown.enabled = false;
                tagify.dropdown.hide(true);
            }

            if (tagify.settings.autoComplete) {
                tagify.settings.autoComplete.enabled = false;
            }
        }
    }, [tagifySettings]);

    useEffect(() => {
        const input = tagifyRef.current?.DOM.input;
        if (!ror || !input) return;
        const isRor = !!rorDraft.draft && parseRorInput(rorDraft.draft.text).kind !== 'name';
        const hasOption =
            isRor && rorOpen && !!resolveRorInput(parseRorInput(rorDraft.draft?.text ?? ''), rorIndex) && !catalog?.isLoading && !catalog?.error;
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-expanded', String(hasOption));
        input.setAttribute('aria-controls', `${id}-ror-options`);
        input.setAttribute(
            'aria-describedby',
            [inputProps['aria-describedby'], `${id}-ror-help`, isRor ? `${id}-ror-feedback` : ''].filter(Boolean).join(' '),
        );
        if (hasOption) input.setAttribute('aria-activedescendant', `${id}-ror-option`);
        else input.removeAttribute('aria-activedescendant');
    }, [ror, rorDraft.draft, rorIndex, rorOpen, catalog, id, inputProps]);

    useEffect(() => {
        const tagify = tagifyRef.current;
        const inputElement = inputRef.current;
        if (!tagify || !inputElement) {
            return;
        }

        const currentValues = tagify.value
            .map((item) => {
                const rawItem = item as Record<string, unknown>;
                const data = rawItem.data as Record<string, unknown> | undefined;

                // Try to get rorId from item directly or from item.data
                const directRorId = typeof rawItem.rorId === 'string' ? rawItem.rorId : null;
                const dataRorId = data && typeof data.rorId === 'string' ? data.rorId : null;
                const rorId = directRorId ?? dataRorId;

                return {
                    value: typeof item.value === 'string' ? item.value : '',
                    rorId,
                };
            })
            .filter((item) => item.value);

        const areEqual =
            currentValues.length === value.length &&
            currentValues.every((item, index) => {
                const next = value[index];
                let expectedRorId: string | null = null;

                if ('rorId' in next) {
                    if (typeof next.rorId === 'string') {
                        expectedRorId = next.rorId;
                    } else if (next.rorId === null) {
                        expectedRorId = null;
                    }
                }

                return item.value === next.value && item.rorId === expectedRorId;
            });

        if (areEqual) {
            return;
        }

        // Replacing tags from props emits a transient empty `change` event in
        // Tagify. It must not overwrite a list just imported into parent state.
        const changeHandler = tagifyChangeHandlerRef.current;
        if (changeHandler) tagify.off('change', changeHandler);
        try {
            tagify.removeAllTags();
            inputElement.value = value.map((item) => item.value).join(', ');
            if (value.length > 0) {
                tagify.addTags(value, true, true);
            }
        } finally {
            if (changeHandler) tagify.on('change', changeHandler);
        }
    }, [value]);

    useEffect(() => {
        const inputElement = inputRef.current;
        if (!inputElement) {
            return;
        }

        inputElement.required = Boolean(required);
    }, [required]);

    const labelId = `${id}-label`;

    // Only use aria-label when label is hidden; otherwise use aria-labelledby
    const ariaProps = hideLabel ? { 'aria-label': label } : { 'aria-labelledby': labelId };

    return (
        <div {...containerProps} className={mergedClassName}>
            <Label id={labelId} htmlFor={id} className={hideLabel ? 'sr-only' : undefined}>
                {label}
                {required && (
                    <span aria-hidden="true" className="ml-1 font-bold text-destructive">
                        *
                    </span>
                )}
            </Label>
            <input ref={inputRef} id={id} placeholder={placeholder} data-testid={dataTestId} {...ariaProps} {...inputProps} />
            {ror && (
                <>
                    <p id={`${id}-ror-help`} className="text-xs text-muted-foreground">
                        Enter an institution name, ROR ID, URL or Name (ROR ID), then select a match. Use Enter to add names.
                    </p>
                    <RorInputFeedback
                        {...catalog}
                        id={id}
                        text={rorDraft.draft?.text ?? ''}
                        index={rorIndex}
                        open={rorOpen}
                        onSelect={selectRor}
                        onDiscard={clearRorInput}
                    />
                </>
            )}
        </div>
    );
}

export default TagInputField;
