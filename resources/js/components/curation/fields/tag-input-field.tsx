import { useEffect, useMemo, useRef } from 'react';
import Tagify from '@yaireo/tagify';
import type { TagData, TagifySettings } from '@yaireo/tagify';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import type { HTMLAttributes, InputHTMLAttributes } from 'react';

export interface TagInputChangeDetail {
    raw: string;
    tags: string[];
}

interface TagInputFieldProps
    extends Omit<InputHTMLAttributes<HTMLInputElement>, 'value' | 'onChange'> {
    id: string;
    label: string;
    value: string[];
    onChange: (detail: TagInputChangeDetail) => void;
    hideLabel?: boolean;
    className?: string;
    containerProps?: HTMLAttributes<HTMLDivElement> & { 'data-testid'?: string };
    tagifySettings?: Partial<TagifySettings<TagData>>;
}

function areArraysEqual(a: string[], b: string[]) {
    if (a.length !== b.length) {
        return false;
    }

    return a.every((value, index) => value === b[index]);
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

        inputElement.value = value.join(', ');

        const settings: TagifySettings<TagData> = {
            delimiters: ',',
            editTags: 1,
            duplicates: false,
            placeholder,
            dropdown: { enabled: 0 },
            maxTags: Infinity,
            originalInputValueFormat: (values) =>
                values.map((item) => item.value?.trim()).filter(Boolean).join(', '),
            a11y: {
                focusableTags: true,
            },
            ...tagifySettings,
        };

        const tagify = new Tagify(inputElement, settings);
        tagifyRef.current = tagify;

        tagify.DOM.scope.dataset.testid = `${id}-tagify`;
        tagify.DOM.input.setAttribute('data-testid', `${id}-tagify-input`);
        (inputElement as HTMLInputElement & { tagify?: Tagify<TagData> }).tagify = tagify;

        if (value.length > 0) {
            tagify.removeAllTags();
            tagify.addTags(value, true, true);
        }

        const handleChange = (event: CustomEvent) => {
            const detail = event.detail as { value?: string; tagify: Tagify<TagData> };
            const rawValue = detail.value ?? '';
            const tags = detail.tagify.value
                .map((item) => item.value?.trim())
                .filter((tag): tag is string => Boolean(tag && tag.length > 0));

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

    useEffect(() => {
        const tagify = tagifyRef.current;
        if (!tagify) {
            return;
        }

        const currentValues = tagify.value
            .map((item) => item.value?.trim())
            .filter((tag): tag is string => Boolean(tag && tag.length > 0));

        if (areArraysEqual(currentValues, value)) {
            return;
        }

        if (value.length === 0) {
            tagify.removeAllTags();
            return;
        }

        tagify.loadOriginalValues(value.join(', '));
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
                {...ariaProps}
                {...inputProps}
            />
        </div>
    );
}

export default TagInputField;
