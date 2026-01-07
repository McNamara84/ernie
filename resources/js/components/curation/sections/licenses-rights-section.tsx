import type { ReactElement, RefObject } from 'react';

import { SectionHeader } from '@/components/curation/section-header';
import { AccordionContent, AccordionItem, AccordionTrigger } from '@/components/ui/accordion';
import type { ValidationMessage } from '@/hooks/use-form-validation';

import LicenseField from '../fields/license-field';

type SectionStatus = 'valid' | 'invalid' | 'optional-empty';

type LicenseEntry = {
    id: string;
    license: string;
};

type LicenseOption = {
    value: string;
    label: string;
};

type LicensesRightsSectionProps = {
    status: SectionStatus;
    renderStatusBadge: (status: SectionStatus) => ReactElement;
    contentRef: RefObject<HTMLDivElement | null>;

    licenseEntries: LicenseEntry[];
    licenseOptions: LicenseOption[];
    maxLicenses: number;
    canAddLicense: boolean;

    onLicenseChange: (index: number, value: string) => void;
    onAddLicense: () => void;
    onRemoveLicense: (index: number) => void;

    firstLicenseValidationMessages?: ValidationMessage[];
    firstLicenseTouched?: boolean;
    onFirstLicenseValidationBlur?: () => void;
};

export function LicensesRightsSection({
    status,
    renderStatusBadge,
    contentRef,
    licenseEntries,
    licenseOptions,
    maxLicenses,
    canAddLicense,
    onLicenseChange,
    onAddLicense,
    onRemoveLicense,
    firstLicenseValidationMessages,
    firstLicenseTouched,
    onFirstLicenseValidationBlur,
}: LicensesRightsSectionProps) {
    return (
        <AccordionItem value="licenses-rights">
            <AccordionTrigger>
                <div className="flex items-center gap-2">
                    <span>Licenses and Rights</span>
                    {renderStatusBadge(status)}
                </div>
            </AccordionTrigger>
            <AccordionContent ref={contentRef}>
                <SectionHeader
                    label="Licenses and Rights"
                    description="Specify usage rights and restrictions for your dataset."
                    tooltip="At least one license is required. Choose a license that matches your data sharing policy."
                    required
                    counter={{ current: licenseEntries.length, max: maxLicenses }}
                />
                <div className="space-y-4">
                    {licenseEntries.map((entry, index) => (
                        <LicenseField
                            key={entry.id}
                            id={entry.id}
                            license={entry.license}
                            options={licenseOptions}
                            onLicenseChange={(val) => onLicenseChange(index, val)}
                            onAdd={onAddLicense}
                            onRemove={() => onRemoveLicense(index)}
                            isFirst={index === 0}
                            canAdd={canAddLicense}
                            required={index === 0}
                            validationMessages={index === 0 ? firstLicenseValidationMessages : undefined}
                            touched={index === 0 ? firstLicenseTouched : undefined}
                            onValidationBlur={index === 0 ? onFirstLicenseValidationBlur : undefined}
                            data-testid={`license-select-${index}`}
                        />
                    ))}
                </div>
            </AccordionContent>
        </AccordionItem>
    );
}
