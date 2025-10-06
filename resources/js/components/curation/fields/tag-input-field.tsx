import { useEffect, useMemo, useRef } from 'react';
import Tagify from '@yaireo/tagify';
import type { TagData, TagifySettings } from '@yaireo/tagify';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import type { HTMLAttributes, InputHTMLAttributes } from 'react';

export interface TagInputItem {
    value: string;
    rorId?: string | null;
    [key: string]: unknown;
}

export interface TagInputChangeDetail {
    raw: string;
    tags: TagInputItem[];
}

interface TagInputFieldProps
    extends Omit<InputHTMLAttributes<HTMLInputElement>, 'value' | 'onChange'> {
    id: string;
    label: string;
    value: TagInputItem[];
    onChange: (detail: TagInputChangeDetail) => void;
    hideLabel?: boolean;
    className?: string;
    containerProps?: HTMLAttributes<HTMLDivElement> & { 'data-testid'?: string };
    tagifySettings?: Partial<TagifySettings<TagData>>;
    'data-testid'?: string;
}

export function TagInputField({
    id,
    label,
    value,
    onChange,
    hideLabel = false,
    className,
    containerProps,
    tagifySettings,
    required,
    disabled,
    placeholder,
    'data-testid': dataTestId,
    ...inputProps
}: TagInputFieldProps) {
    const inputRef = useRef<HTMLInputElement | null>(null);
    const tagifyRef = useRef<Tagify<TagData> | null>(null);
    const changeHandlerRef = useRef(onChange);

    useEffect(() => {
        changeHandlerRef.current = onChange;
    }, [onChange]);

    const mergedClassName = useMemo(
        () => cn('flex flex-col gap-2', containerProps?.className, className),
        [className, containerProps?.className],
    );

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

            const tags: TagInputItem[] = detail.tagify.value
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
                    };
                })
                .filter((item): item is { value: string; rorId: string | null } => Boolean(item));

            changeHandlerRef.current({ raw: rawValue, tags });
        };

        tagify.on('change', handleChange);

        return () => {
            tagify.off('change', handleChange);
            tagify.destroy();
            tagifyRef.current = null;
        };
        // We intentionally exclude dependencies to avoid re-initialising Tagify
        // which manages its own DOM lifecycle.
        // eslint-disable-next-line react-hooks/exhaustive-deps
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
    }, [tagifySettings]);

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

        tagify.removeAllTags();
        inputElement.value = value.map((item) => item.value).join(', ');

        if (value.length > 0) {
            tagify.addTags(value, true, true);
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
    const ariaProps = hideLabel
        ? { 'aria-label': label }
        : { 'aria-labelledby': labelId };

    return (
        <div {...containerProps} className={mergedClassName}>
            <Label
                id={labelId}
                htmlFor={id}
                className={hideLabel ? 'sr-only' : undefined}
            >
                {label}
                {required && (
                    <span aria-hidden="true" className="text-destructive ml-1">
                        *
                    </span>
                )}
            </Label>
            <input
                ref={inputRef}
                id={id}
                placeholder={placeholder}
                data-testid={dataTestId}
                {...ariaProps}
                {...inputProps}
            />
        </div>
    );
}

export default TagInputField;
