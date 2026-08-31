import { Children, createContext, isValidElement, type ReactNode, useContext } from 'react';

import { Accordion, AccordionContent, AccordionItem, AccordionTrigger } from '@/components/ui/accordion';
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
    'datacenters',
] as const;

interface EditorSettingsAccordionProps {
    value: string;
    onValueChange: (value: string) => void;
    children: ReactNode;
}

const OpenEditorSettingsSectionContext = createContext('');

export function EditorSettingsAccordion({ value, onValueChange, children }: EditorSettingsAccordionProps) {
    const orderByValue = new Map<string, number>(EDITOR_SETTINGS_SECTION_ORDER.map((sectionValue, index) => [sectionValue, index]));
    const orderedSections = Children.toArray(children).sort((left, right) => {
        const leftValue = isValidElement<{ value?: string }>(left) ? left.props.value : undefined;
        const rightValue = isValidElement<{ value?: string }>(right) ? right.props.value : undefined;

        return (orderByValue.get(leftValue ?? '') ?? Number.MAX_SAFE_INTEGER) - (orderByValue.get(rightValue ?? '') ?? Number.MAX_SAFE_INTEGER);
    });

    return (
        <Accordion
            type="single"
            collapsible
            value={value}
            onValueChange={onValueChange}
            className="flex flex-col gap-4"
            data-testid="settings-accordion"
        >
            <OpenEditorSettingsSectionContext.Provider value={value}>{orderedSections}</OpenEditorSettingsSectionContext.Provider>
        </Accordion>
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
}

export function EditorSettingsSection({ value, title, description, icon, summary, children, className }: EditorSettingsSectionProps) {
    const openSection = useContext(OpenEditorSettingsSectionContext);
    const isOpen = openSection === value;

    return (
        <AccordionItem
            value={value}
            className={cn('overflow-hidden rounded-xl border bg-card text-card-foreground shadow-sm last:border-b', className)}
        >
            <AccordionTrigger className="items-center gap-3 px-4 py-4 hover:no-underline sm:px-6">
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
            <AccordionContent forceMount hidden={!isOpen} aria-hidden={!isOpen} className="px-4 pb-5 sm:px-6 sm:pb-6">
                {children}
            </AccordionContent>
        </AccordionItem>
    );
}
