import * as AccordionPrimitive from '@radix-ui/react-accordion';
import { ChevronDownIcon } from 'lucide-react';

import { cn } from '@/lib/utils';

function Accordion(
    props: React.ComponentProps<typeof AccordionPrimitive.Root>,
) {
    return <AccordionPrimitive.Root data-slot="accordion" {...props} />;
}

function AccordionItem(
    { className, ...props }: React.ComponentProps<typeof AccordionPrimitive.Item>,
) {
    return (
        <AccordionPrimitive.Item
            data-slot="accordion-item"
            className={cn('border-b', className)}
            {...props}
        />
    );
}

function AccordionTrigger(
    { className, children, ...props }: React.ComponentProps<typeof AccordionPrimitive.Trigger>,
) {
    return (
        <AccordionPrimitive.Header className="flex">
            <AccordionPrimitive.Trigger
                data-slot="accordion-trigger"
                className={cn(
                    'flex flex-1 items-center justify-between py-4 font-medium transition-all hover:underline [&[data-state=open]>svg]:rotate-180',
                    className,
                )}
                {...props}
            >
                {children}
                <ChevronDownIcon className="h-4 w-4 shrink-0 transition-transform duration-200" />
            </AccordionPrimitive.Trigger>
        </AccordionPrimitive.Header>
    );
}

function AccordionContent(
    { className, children, ...props }: React.ComponentProps<typeof AccordionPrimitive.Content>,
) {
    return (
        <AccordionPrimitive.Content
            data-slot="accordion-content"
            className={cn(
                'overflow-hidden text-sm data-[state=closed]:animate-accordion-up data-[state=open]:animate-accordion-down',
                className,
            )}
            {...props}
        >
            <div className="pb-4 pt-0">{children}</div>
        </AccordionPrimitive.Content>
    );
}

export { Accordion, AccordionContent,AccordionItem, AccordionTrigger };
