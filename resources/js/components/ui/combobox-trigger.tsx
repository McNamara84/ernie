import { type ComponentProps, useEffect, useId, useState } from 'react';

import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

/** Multi-select triggers contain remove buttons and retain label/keyboard behavior. */
export function ComboboxTrigger({ multiple, disabled, onClick, children, className, ...props }: ComponentProps<typeof Button> & { multiple: boolean }) {
    const labelId = useId();
    const [labelledBy, setLabelledBy] = useState<string>();
    const id = props.id;
    const explicitLabel = props['aria-label'];
    const explicitLabelledBy = props['aria-labelledby'];

    useEffect(() => {
        if (!multiple || !id) return;
        const labels = Array.from(document.querySelectorAll('label')).filter((label) => label.htmlFor === id);
        const assigned = new Map<HTMLLabelElement, string>();
        if (!explicitLabel && !explicitLabelledBy) {
            setLabelledBy(labels.map((label, index) => {
                if (!label.id) {
                    label.id = `${labelId}-${index}`;
                    assigned.set(label, label.id);
                }
                return label.id;
            }).join(' ') || undefined);
        }
        // A div does not receive native activation from an associated label.
        const focus = () => { if (!disabled) document.getElementById(id)?.focus(); };
        labels.forEach((label) => label.addEventListener('click', focus));
        return () => {
            labels.forEach((label) => label.removeEventListener('click', focus));
            assigned.forEach((ownedId, label) => { if (label.id === ownedId) label.removeAttribute('id'); });
        };
    }, [multiple, id, disabled, labelId, explicitLabel, explicitLabelledBy]);

    if (!multiple) return <Button disabled={disabled} onClick={onClick} className={className} {...props}>{children}</Button>;
    return (
        <Button
            asChild
            disabled={disabled}
            {...props}
            className={cn(className, disabled && 'pointer-events-none opacity-50')}
            aria-labelledby={explicitLabelledBy ?? (explicitLabel ? undefined : labelledBy)}
            onClick={(event) => {
                if (disabled) event.preventDefault();
                else onClick?.(event);
            }}
        >
            <div
                tabIndex={disabled ? -1 : 0}
                aria-disabled={disabled || undefined}
                onKeyDown={(event) => {
                    if (event.target === event.currentTarget && ['Enter', ' '].includes(event.key)) {
                        event.preventDefault();
                        if (!disabled) event.currentTarget.click();
                    }
                }}
            >
                {children}
            </div>
        </Button>
    );
}
