import { ChevronsDown, ChevronsUp } from 'lucide-react';
import { Children, createContext, isValidElement, type ReactNode, useContext, useEffect, useRef } from 'react';

import { Accordion, AccordionContent, AccordionItem, AccordionTrigger } from '@/components/ui/accordion';
import { Button } from '@/components/ui/button';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

export const EDITOR_SETTINGS_SECTION_ORDER = [
    'resource-types',
    'licenses',
    'title-types',
    'description-types',
    'date-types',
    'languages',
    'contributor-person-roles',
    'contributor-institution-roles',
    'contributor-both-roles',
    'relation-types',
    'identifier-types',
    'thesauri',
    'persistent-identifiers',
    'landing-page-domains',
    'download-url-suggestions',
    'datacenters',
] as const;

interface EditorSettingsAccordionProps {
    value: string[];
    onValueChange: (value: string[]) => void;
    children: ReactNode;
}

interface EditorSettingsAccordionContextValue {
    openSections: string[];
    allClosed: boolean;
    allOpen: boolean;
    collapseAll: () => void;
    expandAll: () => void;
}

const EditorSettingsAccordionContext = createContext<EditorSettingsAccordionContextValue | null>(null);

export function EditorSettingsAccordion({ value, onValueChange, children }: EditorSettingsAccordionProps) {
    const orderByValue = new Map<string, number>(EDITOR_SETTINGS_SECTION_ORDER.map((sectionValue, index) => [sectionValue, index]));
    const orderedSections = Children.toArray(children).sort((left, right) => {
        const leftValue = isValidElement<{ value?: string }>(left) ? left.props.value : undefined;
        const rightValue = isValidElement<{ value?: string }>(right) ? right.props.value : undefined;

        return (orderByValue.get(leftValue ?? '') ?? Number.MAX_SAFE_INTEGER) - (orderByValue.get(rightValue ?? '') ?? Number.MAX_SAFE_INTEGER);
    });

    return (
        <Accordion type="multiple" value={value} onValueChange={onValueChange} className="flex flex-col gap-4" data-testid="settings-accordion">
            <EditorSettingsAccordionContext.Provider
                value={{
                    openSections: value,
                    allClosed: value.length === 0,
                    allOpen: EDITOR_SETTINGS_SECTION_ORDER.every((sectionValue) => value.includes(sectionValue)),
                    collapseAll: () => onValueChange([]),
                    expandAll: () => onValueChange([...EDITOR_SETTINGS_SECTION_ORDER]),
                }}
            >
                {orderedSections}
            </EditorSettingsAccordionContext.Provider>
        </Accordion>
    );
}

function EditorSettingsSectionActions({ accordion, onAction }: { accordion: EditorSettingsAccordionContextValue; onAction: () => void }) {
    return (
        <div className="flex items-center gap-1 pr-4 sm:pr-6">
            {!accordion.allClosed && (
                <Tooltip>
                    <TooltipTrigger asChild>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon-xs"
                            aria-label="Collapse all settings cards"
                            onClick={() => {
                                accordion.collapseAll();
                                onAction();
                            }}
                        >
                            <ChevronsUp className="h-3.5 w-3.5" aria-hidden="true" />
                        </Button>
                    </TooltipTrigger>
                    <TooltipContent>Collapse all settings cards</TooltipContent>
                </Tooltip>
            )}
            {!accordion.allOpen && (
                <Tooltip>
                    <TooltipTrigger asChild>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon-xs"
                            aria-label="Expand all settings cards"
                            onClick={() => {
                                accordion.expandAll();
                                onAction();
                            }}
                        >
                            <ChevronsDown className="h-3.5 w-3.5" aria-hidden="true" />
                        </Button>
                    </TooltipTrigger>
                    <TooltipContent>Expand all settings cards</TooltipContent>
                </Tooltip>
            )}
        </div>
    );
}

interface EditorSettingsSectionProps {
    value: string;
    title: string;
    description?: string;
    icon?: ReactNode;
    summary: ReactNode;
    children: ReactNode;
    className?: string;
    forceMount?: boolean;
    focusOnOpen?: boolean;
}

export function EditorSettingsSection({
    value,
    title,
    description,
    icon,
    summary,
    children,
    className,
    forceMount = false,
    focusOnOpen = false,
}: EditorSettingsSectionProps) {
    const accordion = useContext(EditorSettingsAccordionContext);
    const isOpen = accordion?.openSections.includes(value) ?? false;
    const triggerRef = useRef<HTMLButtonElement>(null);
    const didFocus = useRef(false);

    useEffect(() => {
        if (!focusOnOpen || !isOpen || didFocus.current) return;
        const frame = requestAnimationFrame(() => {
            const trigger = triggerRef.current;
            if (!trigger) return;
            didFocus.current = true;
            trigger.focus({ preventScroll: true });
            trigger.scrollIntoView({ block: 'start', behavior: 'instant' });
        });
        return () => cancelAnimationFrame(frame);
    }, [focusOnOpen, isOpen]);

    return (
        <AccordionItem
            value={value}
            className={cn('overflow-hidden rounded-xl border bg-card text-card-foreground shadow-sm last:border-b', className)}
        >
            <AccordionTrigger
                ref={triggerRef}
                className="min-w-0 scroll-mt-52 items-center gap-3 py-4 pr-2 pl-4 hover:no-underline sm:pl-6 md:scroll-mt-32"
                actions={accordion ? <EditorSettingsSectionActions accordion={accordion} onAction={() => triggerRef.current?.focus()} /> : undefined}
            >
                <span className="flex min-w-0 flex-1 flex-col gap-2 text-left">
                    <span className="flex min-w-0 items-center gap-2 text-base leading-none font-semibold tracking-tight">
                        {icon ? (
                            <span className="shrink-0" aria-hidden="true">
                                {icon}
                            </span>
                        ) : null}
                        <span>{title}</span>
                    </span>
                    {description ? <span className="max-w-4xl text-sm font-normal text-muted-foreground">{description}</span> : null}
                    {summary}
                </span>
            </AccordionTrigger>
            <AccordionContent
                forceMount={forceMount ? true : undefined}
                hidden={forceMount && !isOpen}
                aria-hidden={forceMount && !isOpen ? true : undefined}
                className="px-4 pb-5 sm:px-6 sm:pb-6"
            >
                {children}
            </AccordionContent>
        </AccordionItem>
    );
}
