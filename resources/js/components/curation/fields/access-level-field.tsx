import { SelectField } from './select-field';

import type { AccessLevel } from '../types/datacite-form-types';

interface AccessLevelFieldProps {
    value: AccessLevel;
    onChange: (value: AccessLevel) => void;
    onBlur: () => void;
    validationMessages: Parameters<typeof SelectField>[0]['validationMessages'];
    touched: boolean;
}

export function AccessLevelField({ value, onChange, onBlur, validationMessages, touched }: AccessLevelFieldProps) {
    return (
        <SelectField
            id="accessLevel"
            label="Access Level"
            value={value}
            onValueChange={(nextValue) => onChange(nextValue as AccessLevel)}
            onValidationBlur={onBlur}
            validationMessages={validationMessages}
            touched={touched}
            options={[
                { value: 'open', label: 'Open access' },
                { value: 'restricted', label: 'Restricted access' },
                { value: 'embargoed', label: 'Embargoed access' },
                { value: 'metadata-only', label: 'Metadata only access' },
            ]}
            className="min-w-0 md:col-span-6 xl:col-span-2"
            labelTooltip="Access conditions are independent of the selected license. Embargoed access requires an Available date."
            required
            data-testid="access-level-select"
        />
    );
}
