import type { ReactElement, RefObject } from 'react';

import { AccordionContent, AccordionItem, AccordionTrigger } from '@/components/ui/accordion';
import { SectionHeader } from '@/components/curation/section-header';
import type { ValidationMessage } from '@/hooks/use-form-validation';

import DescriptionField, { type DescriptionEntry } from '../fields/description-field';

type SectionStatus = 'valid' | 'invalid' | 'optional-empty';

type DescriptionsSectionProps = {
    status: SectionStatus;
    renderStatusBadge: (status: SectionStatus) => ReactElement;
    contentRef: RefObject<HTMLDivElement | null>;

    descriptions: DescriptionEntry[];
    onChange: (descriptions: DescriptionEntry[]) => void;

    abstractValidationMessages: ValidationMessage[];
    abstractTouched: boolean;
    onAbstractValidationBlur: () => void;
};

export function DescriptionsSection({
    status,
    renderStatusBadge,
    contentRef,
    descriptions,
    onChange,
    abstractValidationMessages,
    abstractTouched,
    onAbstractValidationBlur,
}: DescriptionsSectionProps) {
    return (
        <AccordionItem value="descriptions">
            <AccordionTrigger>
                <div className="flex items-center gap-2">
                    <span>Descriptions</span>
                    {renderStatusBadge(status)}
                </div>
            </AccordionTrigger>
            <AccordionContent ref={contentRef}>
                <SectionHeader
                    label="Descriptions"
                    description="Detailed information about your dataset."
                    tooltip="Abstract is required (50-17,500 characters). Other description types are optional."
                    required
                />
                <DescriptionField
                    descriptions={descriptions}
                    onChange={onChange}
                    abstractValidationMessages={abstractValidationMessages}
                    abstractTouched={abstractTouched}
                    onAbstractValidationBlur={onAbstractValidationBlur}
                />
            </AccordionContent>
        </AccordionItem>
    );
}
